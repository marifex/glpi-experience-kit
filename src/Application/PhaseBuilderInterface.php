<?php

declare(strict_types=1);

namespace GlpiPlugin\Experiencekit\Application;

use GlpiPlugin\Experiencekit\Domain\GenerationPhase;

/**
 * One implementation per GenerationPhase. Builders never decide "how many"
 * on their own - that always comes from the run's VolumeProfile via
 * RunContext - and every GLPI object a builder creates must be registered
 * on the context before the batch returns, so purge can find it later.
 */
interface PhaseBuilderInterface
{
    public function getPhase(): GenerationPhase;

    /**
     * Computes the total number of work units this phase will process for
     * the given run. Called once, when the phase starts.
     */
    public function plan(RunContext $context): int;

    /**
     * Processes up to $batchSize work units. Must be safe to call
     * repeatedly across separate PHP requests (cron ticks) for the same
     * run - resumability comes from each call picking up wherever the
     * registry/phase progress left off, not from in-memory state.
     */
    public function runBatch(RunContext $context, int $batchSize): BatchResult;
}
