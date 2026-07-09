<?php

declare(strict_types=1);

namespace GlpiPlugin\Experiencekit\Infrastructure\Persistence;

use GlpiPlugin\Experiencekit\Domain\GenerationPhase;
use GlpiPlugin\Experiencekit\Domain\VolumeProfile;
use PluginExperiencekitRun;

/**
 * Thin wrapper over glpi_plugin_experiencekit_runs.
 */
final class RunRepository
{
    public function create(
        VolumeProfile $profile,
        int $usersId,
        ?string $name,
        string $organizationName,
        int $seed,
        bool $notificationsWasEnabled,
        bool $notificationsMailingWasEnabled,
    ): PluginExperiencekitRun {
        $run = new PluginExperiencekitRun();
        $id = $run->add([
            'name'                                => $name ?? sprintf('%s run - %s', ucfirst($profile->name), date('Y-m-d H:i')),
            'organization_name'                   => $organizationName,
            'status'                               => PluginExperiencekitRun::STATUS_PENDING,
            'volume_profile'                       => $profile->name,
            'profile_json'                         => json_encode($profile->toArray()),
            'current_phase'                        => GenerationPhase::ORG_STRUCTURE->value,
            'seed'                                 => $seed,
            'users_id'                             => $usersId,
            // Written directly on insert rather than via a later update(): CommonDBTM::update()
            // diffs old-vs-new with loose `!=`, and PHP's `null == 0` is true, so a NULL-to-0
            // transition is silently treated as "unchanged" and never reaches the SQL UPDATE.
            'notifications_was_enabled'         => $notificationsWasEnabled ? 1 : 0,
            'notifications_mailing_was_enabled' => $notificationsMailingWasEnabled ? 1 : 0,
        ]);
        $run->getFromDB($id);
        return $run;
    }

    public function get(int $id): PluginExperiencekitRun
    {
        $run = new PluginExperiencekitRun();
        $run->getFromDB($id);
        return $run;
    }

    /** @return PluginExperiencekitRun[] */
    public function findByStatus(string $status): array
    {
        $run = new PluginExperiencekitRun();
        $runs = [];
        foreach ($run->find(['status' => $status]) as $row) {
            $item = new PluginExperiencekitRun();
            $item->getFromDB($row['id']);
            $runs[] = $item;
        }
        return $runs;
    }

    public function setStatus(PluginExperiencekitRun $run, string $status, array $extra = []): void
    {
        $run->update(['id' => $run->getID(), 'status' => $status] + $extra);
    }

    public function advancePhase(PluginExperiencekitRun $run, GenerationPhase $phase): void
    {
        $run->update(['id' => $run->getID(), 'current_phase' => $phase->value]);
    }
}
