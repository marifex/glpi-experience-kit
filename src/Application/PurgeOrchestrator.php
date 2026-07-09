<?php

declare(strict_types=1);

namespace GlpiPlugin\Experiencekit\Application;

use GlpiPlugin\Experiencekit\Infrastructure\Persistence\PhaseProgressRepository;
use GlpiPlugin\Experiencekit\Infrastructure\Persistence\RegistryRepository;
use GlpiPlugin\Experiencekit\Infrastructure\Persistence\RunRepository;
use PluginExperiencekitRun;
use Throwable;

/**
 * Safely removes everything a run generated - and only that. Every delete
 * is driven entirely by the registry (never a raw "delete everything in
 * entity X" sweep), so a run can never touch a record it didn't create
 * itself: pre-existing customer data, or another run's records, are never
 * candidates in the first place.
 *
 * Deletion order is the registry's own creation order, reversed - later
 * records can reference earlier ones (a Problem_Ticket link references
 * both a Problem and a Ticket, a Contract references a Supplier, etc.),
 * never the other way round, so walking backwards is always dependency-
 * safe without needing per-itemtype ordering rules.
 *
 * Bounded/resumable like generation batches, for the same reason: a large
 * run can have thousands of registry rows, and GLPI's own delete() cascade
 * (history, actor links, documents...) is not free per record.
 */
final class PurgeOrchestrator
{
    public function __construct(
        private readonly RegistryRepository $registry,
        private readonly RunRepository $runs,
        private readonly PhaseProgressRepository $phaseProgress,
    ) {
    }

    /** Marks a run for purge; the actual deletion happens via purgeNextBatch(). */
    public function startPurge(PluginExperiencekitRun $run): void
    {
        $this->runs->setStatus($run, PluginExperiencekitRun::STATUS_PURGING);
    }

    /** @return array<string,int> itemtype => count of registry-tracked records still to remove. */
    public function preview(int $runsId): array
    {
        return $this->registry->countsByItemtypeForRun($runsId);
    }

    /**
     * Deletes up to $batchSize registry-tracked records for $run, most-
     * recently-created first. When nothing is left, also clears the run's
     * phase_progress rows and marks it PURGED - the run row itself is kept
     * (not deleted) as a historical record for the admin UI's run history.
     *
     * @return int Records actually deleted this call (0 means the purge is complete).
     */
    public function purgeNextBatch(PluginExperiencekitRun $run, int $batchSize): int
    {
        $rows = array_slice(
            array_reverse($this->registry->allForRun($run->getID())),
            0,
            $batchSize
        );

        if (count($rows) === 0) {
            $this->phaseProgress->deleteForRun($run->getID());
            $this->runs->setStatus($run, PluginExperiencekitRun::STATUS_PURGED);
            return 0;
        }

        $deleted = 0;
        foreach ($rows as $row) {
            $itemtype = $row['itemtype'];
            $itemsId = (int) $row['items_id'];

            try {
                $item = new $itemtype();
                if ($item->getFromDB($itemsId)) {
                    // Not always 'id': e.g. TicketSatisfaction::getIndexName()
                    // is 'tickets_id'. getFromDB() already resolves this
                    // correctly, but delete() needs the same key in its
                    // input explicitly - passing 'id' unconditionally
                    // triggered a silent "Undefined array key" warning for
                    // such classes, confirmed empirically.
                    $item->delete([$itemtype::getIndexName() => $itemsId], true);
                }
            } catch (Throwable) {
                // The underlying object may already be gone (e.g. cascade-
                // deleted alongside its parent, like a Problem_Ticket link
                // when the Problem itself was purged first). Either way,
                // the registry row for it is now stale and should go too -
                // purge must never get stuck retrying a record that can no
                // longer be deleted.
            }

            $this->registry->deleteRow($itemtype, $itemsId);
            $deleted++;
        }

        return $deleted;
    }
}
