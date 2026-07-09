<?php

declare(strict_types=1);

namespace GlpiPlugin\Experiencekit\Application;

/**
 * What a phase builder's runBatch() reports back to the orchestrator.
 */
final class BatchResult
{
    public function __construct(
        public readonly int $processed,
        public readonly bool $phaseComplete,
    ) {
    }
}
