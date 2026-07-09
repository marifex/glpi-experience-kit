<?php

declare(strict_types=1);

namespace GlpiPlugin\Experiencekit\Infrastructure\Persistence;

use PluginExperiencekitHealthcheck;

/**
 * Thin wrapper over glpi_plugin_experiencekit_healthchecks.
 */
final class HealthCheckRepository
{
    public function record(?int $runsId, string $checkKey, string $status, array $details = []): PluginExperiencekitHealthcheck
    {
        $check = new PluginExperiencekitHealthcheck();
        $id = $check->add([
            'runs_id'      => $runsId,
            'check_key'    => $checkKey,
            'status'       => $status,
            'details_json' => json_encode($details),
        ]);
        $check->getFromDB($id);
        return $check;
    }

    /** @return PluginExperiencekitHealthcheck[] Most recent first. */
    public function latestForRun(int $runsId): array
    {
        $check = new PluginExperiencekitHealthcheck();
        $results = [];
        foreach ($check->find(['runs_id' => $runsId], ['date_creation DESC']) as $row) {
            $item = new PluginExperiencekitHealthcheck();
            $item->getFromDB($row['id']);
            $results[] = $item;
        }
        return $results;
    }
}
