<?php

declare(strict_types=1);

namespace GlpiPlugin\Experiencekit\Infrastructure\Persistence;

use DBmysql;
use GlpiPlugin\Experiencekit\Domain\GenerationPhase;
use PluginExperiencekitRegistry;

/**
 * Thin wrapper over glpi_plugin_experiencekit_registry - the plugin's
 * safe-purge mechanism. Every GLPI object a phase builder creates gets one
 * row here before its batch returns.
 */
final class RegistryRepository
{
    public function __construct(private readonly DBmysql $db)
    {
    }

    public function register(int $runsId, GenerationPhase $phase, string $itemtype, int $itemsId, ?string $scenarioTag = null): void
    {
        $registry = new PluginExperiencekitRegistry();
        $registry->add([
            'runs_id'      => $runsId,
            'itemtype'     => $itemtype,
            'items_id'     => $itemsId,
            'phase'        => $phase->value,
            'scenario_tag' => $scenarioTag,
        ]);
    }

    /**
     * @return int[] items_id values, in creation order.
     *
     * $scenarioTag disambiguates stages that register the same itemtype
     * under the same phase but need independently-tracked targets - e.g.
     * the patching and firewall scenarios both create Change records; without
     * this, registeredCount('Change', SCENARIOS) would conflate the two and
     * either stage could appear "done" using the other's progress.
     */
    public function findItemsIdsForRun(int $runsId, string $itemtype, ?GenerationPhase $phase = null, ?string $scenarioTag = null): array
    {
        $where = ['runs_id' => $runsId, 'itemtype' => $itemtype];
        if ($phase !== null) {
            $where['phase'] = $phase->value;
        }
        if ($scenarioTag !== null) {
            $where['scenario_tag'] = $scenarioTag;
        }

        $ids = [];
        foreach ($this->db->request([
            'SELECT' => 'items_id',
            'FROM'   => PluginExperiencekitRegistry::getTable(),
            'WHERE'  => $where,
            'ORDER'  => 'id ASC',
        ]) as $row) {
            $ids[] = (int) $row['items_id'];
        }
        return $ids;
    }

    public function countForRun(int $runsId, string $itemtype, ?GenerationPhase $phase = null, ?string $scenarioTag = null): int
    {
        return count($this->findItemsIdsForRun($runsId, $itemtype, $phase, $scenarioTag));
    }

    public function isRegistered(string $itemtype, int $itemsId): bool
    {
        return (bool) $this->db->request([
            'COUNT'  => 'c',
            'FROM'   => PluginExperiencekitRegistry::getTable(),
            'WHERE'  => ['itemtype' => $itemtype, 'items_id' => $itemsId],
        ])->current()['c'];
    }

    /** @return array<int,array{itemtype:string,items_id:int,phase:string,scenario_tag:?string}> */
    public function allForRun(int $runsId): array
    {
        $rows = [];
        foreach ($this->db->request([
            'FROM'  => PluginExperiencekitRegistry::getTable(),
            'WHERE' => ['runs_id' => $runsId],
            'ORDER' => 'id ASC',
        ]) as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    /** Grouped counts (itemtype => count) for a run - used by the admin UI and purge confirmation. */
    public function countsByItemtypeForRun(int $runsId): array
    {
        $counts = [];
        foreach ($this->db->request([
            'SELECT' => ['itemtype', new \Glpi\DBAL\QueryExpression('COUNT(*) AS c')],
            'FROM'   => PluginExperiencekitRegistry::getTable(),
            'WHERE'  => ['runs_id' => $runsId],
            'GROUPBY' => 'itemtype',
        ]) as $row) {
            $counts[$row['itemtype']] = (int) $row['c'];
        }
        return $counts;
    }

    public function deleteRow(string $itemtype, int $itemsId): void
    {
        $this->db->delete(PluginExperiencekitRegistry::getTable(), [
            'itemtype' => $itemtype,
            'items_id' => $itemsId,
        ]);
    }
}
