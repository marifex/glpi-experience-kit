<?php

declare(strict_types=1);

namespace GlpiPlugin\Experiencekit\Application;

use DBmysql;
use GlpiPlugin\Experiencekit\Infrastructure\Persistence\HealthCheckRepository;
use PluginExperiencekitHealthcheck;

/**
 * Automated regression checks so the class of bug documented in
 * docs/reference/GLPI_DEMO_DATASET_DNA.md §5 (silently-missing requester
 * actor links) - and issues like it - can never ship silently again. Every
 * check is scoped to registry-tracked records only: this is a health check
 * for what THIS plugin generated, not an audit of a customer's own data.
 */
final class HealthCheckService
{
    /** @var array<string,array{table:string,linkTable:string,fk:string}> */
    private const ACTOR_LINK_TARGETS = [
        'Ticket'  => ['table' => 'glpi_tickets', 'linkTable' => 'glpi_tickets_users', 'fk' => 'tickets_id'],
        'Problem' => ['table' => 'glpi_problems', 'linkTable' => 'glpi_problems_users', 'fk' => 'problems_id'],
        'Change'  => ['table' => 'glpi_changes', 'linkTable' => 'glpi_changes_users', 'fk' => 'changes_id'],
    ];

    public function __construct(
        private readonly DBmysql $db,
        private readonly HealthCheckRepository $healthChecks,
    ) {
    }

    /**
     * Runs every check, persists each result, and returns them.
     *
     * @param int|null $runsId Scope to one run, or null for every run this
     *                         plugin has ever generated (still never
     *                         touches non-registry-tracked data).
     * @return HealthCheckResult[]
     */
    public function run(?int $runsId): array
    {
        $results = [];

        foreach (self::ACTOR_LINK_TARGETS as $itemtype => $spec) {
            $results[] = $this->checkActorCoverage($itemtype, $spec, $runsId);
        }

        $results[] = $this->checkRegistryOrphans($runsId);

        foreach ($results as $result) {
            // HealthCheckRepository::record() has no dedicated summary
            // column - fold it into details_json rather than losing the
            // human-readable text (label/summary would otherwise never be
            // persisted, only the raw counts).
            $this->healthChecks->record($runsId, $result->key, $result->status, [
                'summary' => $result->summary,
                'label'   => $result->label,
            ] + $result->details);
        }

        return $results;
    }

    /**
     * The doc's §5 verification query, generalized to Problem/Change too
     * (the original bug affected all three - "100% coverage" was measured
     * across Ticket/Problem/Change alike) and scoped to registry-tracked
     * records so a customer's own actor-less tickets are never flagged.
     */
    private function checkActorCoverage(string $itemtype, array $spec, ?int $runsId): HealthCheckResult
    {
        $ids = $this->registeredIds($itemtype, $runsId);
        $key = 'requester_actor_coverage_' . strtolower($itemtype);
        $label = "{$itemtype} requester actor links";

        if (count($ids) === 0) {
            return new HealthCheckResult($key, $label, PluginExperiencekitHealthcheck::STATUS_PASS, 'No records to check.');
        }

        $missing = $this->db->request([
            'SELECT' => 'id',
            'FROM'   => $spec['table'],
            'WHERE'  => [
                'id' => $ids,
                new \Glpi\DBAL\QueryExpression(
                    'NOT EXISTS (SELECT 1 FROM ' . $spec['linkTable'] . ' WHERE '
                    . $spec['linkTable'] . '.' . $spec['fk'] . ' = ' . $spec['table'] . '.id'
                    . ' AND ' . $spec['linkTable'] . '.type = 1)'
                ),
            ],
        ]);

        $missingIds = [];
        foreach ($missing as $row) {
            $missingIds[] = (int) $row['id'];
        }

        if (count($missingIds) === 0) {
            return new HealthCheckResult(
                $key,
                $label,
                PluginExperiencekitHealthcheck::STATUS_PASS,
                sprintf('%d/%d records have a requester link.', count($ids), count($ids)),
            );
        }

        return new HealthCheckResult(
            $key,
            $label,
            PluginExperiencekitHealthcheck::STATUS_FAIL,
            sprintf('%d of %d records are missing a requester link.', count($missingIds), count($ids)),
            ['missing_ids' => array_slice($missingIds, 0, 50)],
        );
    }

    /**
     * Registry rows whose underlying GLPI object no longer exists - e.g. an
     * object deleted by an administrator outside the plugin's own purge.
     * Not itself a data-integrity risk (nothing references a registry row
     * except the plugin's own purge, which already tolerates this), but
     * worth surfacing since it means purge's "counts to be deleted" preview
     * would overstate what's actually left.
     */
    private function checkRegistryOrphans(?int $runsId): HealthCheckResult
    {
        $where = $runsId !== null ? ['runs_id' => $runsId] : [];
        $byItemtype = [];
        foreach ($this->db->request([
            'FROM'  => 'glpi_plugin_experiencekit_registry',
            'WHERE' => $where,
        ]) as $row) {
            $byItemtype[$row['itemtype']][] = (int) $row['items_id'];
        }

        $orphanCount = 0;
        $orphansByType = [];
        foreach ($byItemtype as $itemtype => $ids) {
            if (!class_exists($itemtype)) {
                continue;
            }
            // Not always 'id': e.g. TicketSatisfaction::getIndexName() is
            // 'tickets_id' (one survey per ticket), and add()/register()
            // consistently used that field as the record's identifier -
            // see KbAttachmentSurveyBuilder::createSurvey(). Matching that
            // here avoids every such record showing up as a false-positive
            // orphan (confirmed empirically: 203 of 203 TicketSatisfaction
            // rows were wrongly flagged before this fix).
            $table = $itemtype::getTable();
            $indexField = $itemtype::getIndexName();
            $existing = [];
            // Chunked: a single `WHERE field IN (...)` with thousands of
            // ids (e.g. 7,500 Tickets on a Medium-profile run) exceeds
            // MySQL's default range_optimizer_max_mem_size, falling back to
            // a full table scan - confirmed empirically via the resulting
            // warning. Still correct either way, but chunking keeps it fast
            // and warning-free at realistic volumes.
            foreach (array_chunk($ids, 1000) as $chunk) {
                foreach ($this->db->request(['SELECT' => $indexField, 'FROM' => $table, 'WHERE' => [$indexField => $chunk]]) as $row) {
                    $existing[] = (int) $row[$indexField];
                }
            }
            $missing = array_diff($ids, $existing);
            if (count($missing) > 0) {
                $orphansByType[$itemtype] = count($missing);
                $orphanCount += count($missing);
            }
        }

        if ($orphanCount === 0) {
            return new HealthCheckResult(
                'registry_orphans',
                'Registry consistency',
                PluginExperiencekitHealthcheck::STATUS_PASS,
                'Every registry-tracked record still exists.',
            );
        }

        return new HealthCheckResult(
            'registry_orphans',
            'Registry consistency',
            PluginExperiencekitHealthcheck::STATUS_WARN,
            sprintf('%d registry-tracked record(s) no longer exist (likely deleted outside the plugin).', $orphanCount),
            $orphansByType,
        );
    }

    /** @return int[] */
    private function registeredIds(string $itemtype, ?int $runsId): array
    {
        $where = ['itemtype' => $itemtype];
        if ($runsId !== null) {
            $where['runs_id'] = $runsId;
        }

        $ids = [];
        foreach ($this->db->request(['SELECT' => 'items_id', 'FROM' => 'glpi_plugin_experiencekit_registry', 'WHERE' => $where]) as $row) {
            $ids[] = (int) $row['items_id'];
        }
        return $ids;
    }
}
