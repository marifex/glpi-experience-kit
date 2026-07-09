<?php

declare(strict_types=1);

namespace GlpiPlugin\Experiencekit\Infrastructure\Support;

use GlpiPlugin\Experiencekit\Application\GenerationOrchestrator;
use GlpiPlugin\Experiencekit\Infrastructure\Persistence\PhaseProgressRepository;
use GlpiPlugin\Experiencekit\Infrastructure\Persistence\RegistryRepository;
use GlpiPlugin\Experiencekit\Infrastructure\Persistence\RunRepository;

/**
 * Single composition root for the orchestrator, so the CronTask handler,
 * the admin UI controllers, and the Console commands all wire up the exact
 * same set of phase builders instead of risking drift between them. The
 * builder list grows here as each phase lands (see the roadmap in
 * docs/reference/GLPI_DEMO_DATASET_DNA.md §2.2).
 */
final class OrchestratorFactory
{
    public static function make(): GenerationOrchestrator
    {
        global $DB;

        return new GenerationOrchestrator(
            new RunRepository(),
            new RegistryRepository($DB),
            new PhaseProgressRepository(),
            self::builders(),
        );
    }

    /** @return array<string,\GlpiPlugin\Experiencekit\Application\PhaseBuilderInterface> */
    private static function builders(): array
    {
        // Populated as each phase builder lands. Deliberately empty for now
        // - see PluginExperiencekitRun::cronProcessBatch() and the "Skeleton"
        // build step for why a run cannot yet be started.
        return [];
    }
}
