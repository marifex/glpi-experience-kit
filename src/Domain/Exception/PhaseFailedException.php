<?php

declare(strict_types=1);

namespace GlpiPlugin\Experiencekit\Domain\Exception;

/**
 * A phase builder's runBatch() threw while processing a batch. Carries the
 * phase key so the orchestrator can record it against the right
 * phase_progress row.
 */
class PhaseFailedException extends GenerationException
{
    public function __construct(
        public readonly string $phase,
        string $message,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
