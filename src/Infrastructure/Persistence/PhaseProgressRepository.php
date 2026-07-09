<?php

declare(strict_types=1);

namespace GlpiPlugin\Experiencekit\Infrastructure\Persistence;

use GlpiPlugin\Experiencekit\Domain\GenerationPhase;
use PluginExperiencekitPhaseProgress;
use PluginExperiencekitRun;

/**
 * Thin wrapper over glpi_plugin_experiencekit_phase_progress.
 */
final class PhaseProgressRepository
{
    public function createPending(PluginExperiencekitRun $run, GenerationPhase $phase): PluginExperiencekitPhaseProgress
    {
        $progress = new PluginExperiencekitPhaseProgress();
        $id = $progress->add([
            'runs_id' => $run->getID(),
            'phase'   => $phase->value,
            'status'  => PluginExperiencekitPhaseProgress::STATUS_PENDING,
        ]);
        $progress->getFromDB($id);
        return $progress;
    }

    public function getOrCreate(PluginExperiencekitRun $run, GenerationPhase $phase): PluginExperiencekitPhaseProgress
    {
        $progress = new PluginExperiencekitPhaseProgress();
        if ($progress->getFromDBByCrit(['runs_id' => $run->getID(), 'phase' => $phase->value])) {
            return $progress;
        }
        return $this->createPending($run, $phase);
    }

    /** @return PluginExperiencekitPhaseProgress[] indexed by phase value, in phase order. */
    public function allForRun(PluginExperiencekitRun $run): array
    {
        $byPhase = [];
        foreach (GenerationPhase::ordered() as $phase) {
            $byPhase[$phase->value] = $this->getOrCreate($run, $phase);
        }
        return $byPhase;
    }

    public function markRunning(PluginExperiencekitPhaseProgress $progress, int $totalUnits): void
    {
        $progress->update([
            'id'          => $progress->getID(),
            'status'      => PluginExperiencekitPhaseProgress::STATUS_RUNNING,
            'total_units' => $totalUnits,
            'started_at'  => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    public function recordProgress(PluginExperiencekitPhaseProgress $progress, int $additionalUnits): void
    {
        $progress->update([
            'id'              => $progress->getID(),
            'completed_units' => ((int) $progress->fields['completed_units']) + $additionalUnits,
            'last_heartbeat'  => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    public function markDone(PluginExperiencekitPhaseProgress $progress): void
    {
        $progress->update([
            'id'          => $progress->getID(),
            'status'      => PluginExperiencekitPhaseProgress::STATUS_DONE,
            'finished_at' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    public function markFailed(PluginExperiencekitPhaseProgress $progress, string $error): void
    {
        $progress->update([
            'id'         => $progress->getID(),
            'status'     => PluginExperiencekitPhaseProgress::STATUS_FAILED,
            'last_error' => $error,
        ]);
    }
}
