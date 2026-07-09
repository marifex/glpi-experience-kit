<?php

declare(strict_types=1);

namespace GlpiPlugin\Experiencekit\Infrastructure\Support;

use GlpiPlugin\Experiencekit\Application\EntityScopedActorResolver;
use GlpiPlugin\Experiencekit\Application\GenerationOrchestrator;
use GlpiPlugin\Experiencekit\Application\HealthCheckService;
use GlpiPlugin\Experiencekit\Application\PurgeOrchestrator;
use GlpiPlugin\Experiencekit\Domain\GenerationPhase;
use GlpiPlugin\Experiencekit\Infrastructure\Builder\BulkTicketBuilder;
use GlpiPlugin\Experiencekit\Infrastructure\Builder\CmdbBuilder;
use GlpiPlugin\Experiencekit\Infrastructure\Builder\ItsmConfigBuilder;
use GlpiPlugin\Experiencekit\Infrastructure\Builder\KbAttachmentSurveyBuilder;
use GlpiPlugin\Experiencekit\Infrastructure\Builder\OrgStructureBuilder;
use GlpiPlugin\Experiencekit\Infrastructure\Builder\ScenarioBuilder;
use GlpiPlugin\Experiencekit\Infrastructure\Builder\Support\ActiveUserFinder;
use GlpiPlugin\Experiencekit\Infrastructure\Persistence\HealthCheckRepository;
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
            self::builders($DB),
        );
    }

    public static function makePurgeOrchestrator(): PurgeOrchestrator
    {
        global $DB;

        return new PurgeOrchestrator(
            new RegistryRepository($DB),
            new RunRepository(),
            new PhaseProgressRepository(),
        );
    }

    public static function makeHealthCheckService(): HealthCheckService
    {
        global $DB;

        return new HealthCheckService($DB, new HealthCheckRepository());
    }

    /** @return array<string,\GlpiPlugin\Experiencekit\Application\PhaseBuilderInterface> */
    private static function builders(\DBmysql $db): array
    {
        $actors = new EntityScopedActorResolver($db);
        $users = new ActiveUserFinder($db);

        // Populated as each phase builder lands - see the roadmap.
        return [
            GenerationPhase::ORG_STRUCTURE->value => new OrgStructureBuilder(),
            GenerationPhase::CMDB->value          => new CmdbBuilder(),
            GenerationPhase::ITSM_CONFIG->value   => new ItsmConfigBuilder(),
            GenerationPhase::SCENARIOS->value      => new ScenarioBuilder($actors, $users),
            GenerationPhase::BULK_TICKETS->value  => new BulkTicketBuilder($actors, $users),
            GenerationPhase::KB_ATTACHMENTS_SURVEYS->value => new KbAttachmentSurveyBuilder(),
        ];
    }
}
