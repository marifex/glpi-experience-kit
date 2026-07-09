<?php

declare(strict_types=1);

namespace GlpiPlugin\Experiencekit\Application;

final class HealthCheckResult
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $status,
        public readonly string $summary,
        public readonly array $details = [],
    ) {
    }
}
