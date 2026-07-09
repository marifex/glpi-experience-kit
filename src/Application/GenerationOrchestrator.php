<?php

declare(strict_types=1);

namespace GlpiPlugin\Experiencekit\Application;

use Config;
use GlpiPlugin\Experiencekit\Domain\Exception\GenerationException;
use GlpiPlugin\Experiencekit\Domain\Exception\PhaseFailedException;
use GlpiPlugin\Experiencekit\Domain\GenerationPhase;
use GlpiPlugin\Experiencekit\Domain\VolumeProfile;
use GlpiPlugin\Experiencekit\Domain\VolumeProfileFactory;
use GlpiPlugin\Experiencekit\Infrastructure\Persistence\PhaseProgressRepository;
use GlpiPlugin\Experiencekit\Infrastructure\Persistence\RegistryRepository;
use GlpiPlugin\Experiencekit\Infrastructure\Persistence\RunRepository;
use PluginExperiencekitPhaseProgress;
use PluginExperiencekitRun;
use Throwable;

/**
 * Drives a generation run one bounded batch at a time. The same
 * runNextBatch() call is used by the CronTask handler, the admin UI's
 * "Run now" action, and the Console command - there is exactly one code
 * path that advances a run, so behavior can never drift between them.
 */
final class GenerationOrchestrator
{
    /** @param array<string,PhaseBuilderInterface> $builders Indexed by GenerationPhase::value. */
    public function __construct(
        private readonly RunRepository $runs,
        private readonly RegistryRepository $registry,
        private readonly PhaseProgressRepository $phaseProgress,
        private readonly array $builders,
    ) {
    }

    public function startRun(
        string $volumeProfileName,
        int $usersId,
        ?string $name = null,
        string $organizationName = 'MarifeX',
    ): PluginExperiencekitRun {
        $profile = VolumeProfileFactory::make($volumeProfileName);
        // Bounded to fit the `seed` column (signed 32-bit int), not PHP_INT_MAX.
        $seed = random_int(1, 2147483647);

        $useNotifications = (bool) Config::getConfigurationValue('core', 'use_notifications');
        $notificationsMailing = (bool) Config::getConfigurationValue('core', 'notifications_mailing');

        $run = $this->runs->create($profile, $usersId, $name, $organizationName, $seed, $useNotifications, $notificationsMailing);

        foreach (GenerationPhase::ordered() as $phase) {
            $this->phaseProgress->createPending($run, $phase);
        }

        Config::setConfigurationValues('core', [
            'use_notifications'    => 0,
            'notifications_mailing' => 0,
        ]);
        $this->runs->setStatus($run, PluginExperiencekitRun::STATUS_RUNNING);

        return $run;
    }

    public function pauseRun(PluginExperiencekitRun $run): void
    {
        $this->runs->setStatus($run, PluginExperiencekitRun::STATUS_PAUSED);
    }

    public function resumeRun(PluginExperiencekitRun $run): void
    {
        $this->runs->setStatus($run, PluginExperiencekitRun::STATUS_RUNNING);
    }

    public function cancelRun(PluginExperiencekitRun $run): void
    {
        $this->restoreNotifications($run);
        $this->runs->setStatus($run, PluginExperiencekitRun::STATUS_FAILED, [
            'error_message' => __('Cancelled by administrator.', 'experiencekit'),
        ]);
    }

    /**
     * Advances $run by up to $batchSize units within its current phase. A
     * no-op for any run that isn't currently 'running' (e.g. paused,
     * already completed) - callers do not need to filter before invoking.
     */
    public function runNextBatch(PluginExperiencekitRun $run, int $batchSize): void
    {
        if ($run->fields['status'] !== PluginExperiencekitRun::STATUS_RUNNING) {
            return;
        }

        $profile = VolumeProfile::fromArray(json_decode($run->fields['profile_json'], true, 512, JSON_THROW_ON_ERROR));
        $currentPhase = GenerationPhase::from($run->fields['current_phase']);
        $builder = $this->builders[$currentPhase->value] ?? null;

        if ($builder === null) {
            throw new GenerationException("No phase builder registered for \"{$currentPhase->value}\".");
        }

        $context = new RunContext($run, $profile, $this->registry);
        $progress = $this->phaseProgress->getOrCreate($run, $currentPhase);

        if ($progress->fields['status'] === PluginExperiencekitPhaseProgress::STATUS_PENDING) {
            $this->phaseProgress->markRunning($progress, $builder->plan($context));
        }

        try {
            $result = $builder->runBatch($context, $batchSize);
        } catch (Throwable $e) {
            $this->phaseProgress->markFailed($progress, $e->getMessage());
            $this->runs->setStatus($run, PluginExperiencekitRun::STATUS_FAILED, ['error_message' => $e->getMessage()]);
            throw new PhaseFailedException($currentPhase->value, $e->getMessage(), $e);
        }

        $this->phaseProgress->recordProgress($progress, $result->processed);

        if (!$result->phaseComplete) {
            return;
        }

        $this->phaseProgress->markDone($progress);
        $next = $currentPhase->next();

        if ($next === null) {
            $this->restoreNotifications($run);
            $this->runs->setStatus($run, PluginExperiencekitRun::STATUS_COMPLETED, [
                'date_completed' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            ]);
            return;
        }

        $this->runs->advancePhase($run, $next);
    }

    private function restoreNotifications(PluginExperiencekitRun $run): void
    {
        // Re-fetch: the run row we were handed may predate the notification
        // fields being written at startRun() time.
        $current = $this->runs->get($run->getID());

        if ($current->fields['notifications_was_enabled'] === null) {
            return;
        }

        Config::setConfigurationValues('core', [
            'use_notifications'     => (int) $current->fields['notifications_was_enabled'],
            'notifications_mailing' => (int) $current->fields['notifications_mailing_was_enabled'],
        ]);
    }
}
