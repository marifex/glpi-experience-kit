# Architecture

## Layers

```
setup.php, hook.php, autoload.php   Plugin bootstrap: hook registration, install/uninstall, PSR-4 loader
inc/                                 GLPI-native itemtypes (CommonDBTM/CommonGLPI): PluginExperiencekitRun,
                                     Registry, PhaseProgress, Healthcheck, Profile (rights tab)
src/Domain/                         Pure PHP, no GLPI dependency: VolumeProfile, VolumeProfileFactory,
                                     GenerationPhase enum, exceptions
src/Application/                    Orchestration: GenerationOrchestrator, PurgeOrchestrator,
                                     HealthCheckService, EntityScopedActorResolver, RunContext,
                                     PhaseBuilderInterface
src/Infrastructure/Persistence/     Repositories over the 4 plugin tables (Run, Registry, PhaseProgress,
                                     HealthCheck)
src/Infrastructure/Builder/         One class per GenerationPhase: OrgStructureBuilder, CmdbBuilder,
                                     ItsmConfigBuilder, ScenarioBuilder, BulkTicketBuilder,
                                     KbAttachmentSurveyBuilder
src/Infrastructure/Builder/Support/ SequentialPhaseBuilder (shared plan()/runBatch() base),
                                     RandomDataProvider, WeightedDistributor, ActiveUserFinder
src/Infrastructure/Support/         OrchestratorFactory - the single composition root
src/Console/                        Symfony Console commands (auto-discovered by bin/console)
front/, ajax/                       Admin UI
```

`inc/` holds legacy, non-namespaced `Plugin<Name>*` classes because they're GLPI itemtypes (need
`CommonDBTM`/`CommonGLPI` inheritance, native list/search screens, hook registration via
`Plugin::registerClass()`). Everything else is namespaced PSR-4 under `GlpiPlugin\Experiencekit\`,
following clean-architecture dependency rules: Domain depends on nothing, Application depends on Domain,
Infrastructure depends on both.

## Generation pipeline

Six phases, always in this order (`GenerationPhase::ordered()`), each depending on IDs the previous
phase registered:

1. **org_structure** - Entities, Locations, Groups, Users
2. **cmdb** - States, Manufacturers, Suppliers, Software, Licenses, Contracts, Assets
3. **itsm_config** - ITIL Categories, Calendars, SLM/SLA, 22 business rules
4. **scenarios** - the 7 narrative ITIL chains (patching, firewall upgrade, printer failure cluster, VPN
   outage, onboarding, offboarding, laptop replacement)
5. **bulk_tickets** - statistical fill of remaining Incidents/Requests/Problems/Changes to reach the
   volume profile's totals
6. **kb_attachments_surveys** - Knowledge base articles, Document attachments, TicketSatisfaction surveys

`GenerationOrchestrator::runNextBatch()` is the single code path that advances a run by one bounded
batch within its current phase - the CronTask handler, the admin UI's "Run now", and the
`plugins:experiencekit:generate` console command all call it. There is no other way to advance a run,
so behavior can never drift between them.

### Resumability

Every phase builder implements `PhaseBuilderInterface::plan()`/`runBatch()`. Most extend
`SequentialPhaseBuilder`, which reduces a phase to an ordered list of `{itemtype, target, create}`
stages and handles the "how far along am I" question generically: it re-derives progress purely from
`glpi_plugin_experiencekit_registry` (via `RunContext::registeredCount()`) rather than any in-memory or
per-request state, so a batch call is safe to make from a fresh PHP process (a cron tick) at any point.
`ScenarioBuilder`/`BulkTicketBuilder`/`KbAttachmentSurveyBuilder` use a `tag` on stages whenever two
stages register the same itemtype within one phase (e.g. patching and firewall both creating `Change`
records) so their progress isn't conflated.

### Purge safety

Every object a builder creates is registered via `RunContext::register()` before its batch returns -
`(runs_id, itemtype, items_id, phase, scenario_tag)`. `PurgeOrchestrator` only ever deletes rows present
in that registry, in reverse creation order (later records can reference earlier ones, never the other
way round). A run can never touch a record it didn't create; pre-existing customer data and other runs'
records are never candidates in the first place. The run row itself survives a purge (marked `purged`)
as a history record.

## The §5 fix, permanently

`docs/reference/GLPI_DEMO_DATASET_DNA.md` §5 documents a GLPI 11 bug class: `CommonITILObject::
updateActors()` silently drops a requester actor link if `User::isValidUserForEntity()` fails - which
happens whenever `entities_id` is hardcoded instead of derived from the requester (entity mismatch), or
the requester is inactive / outside its `begin_date`/`end_date` window. This plugin makes the fix
structural rather than a one-time retrofit:

- `EntityScopedActorResolver::entityForRequester()` is the *only* code path allowed to compute
  `entities_id` for a Ticket/Problem/Change - every builder that creates one calls it.
- `ActiveUserFinder` restricts every "pick a random requester" call to users that will actually pass
  `User::isValidUserForEntity()` (`is_active=1`, valid `begin_date`/`end_date`), replicating GLPI's own
  validity condition so a picked user can never fail the check for that reason.
- `HealthCheckService` runs the doc's own verification query (generalized to Ticket/Problem/Change
  alike) as a standing, repeatable regression check, not a one-time SQL query someone has to remember to
  re-run.

## Other GLPI quirks discovered and documented in code

- **`getIndexName() !== 'id'`**: some GLPI classes (e.g. `TicketSatisfaction`, keyed by `tickets_id`)
  use a different field as their effective identifier. `add()`/`getFromDB()`/`delete()` all key off
  `static::getIndexName()` consistently - generic registry-wide code (health checks, purge) must query
  by that field, not assume `id`.
- **`CommonDBTM::update()`'s loose-comparison diffing**: PHP's `null == 0` is `true`, so updating a
  `NULL` column to `0` via `update()` is silently dropped. Write such values in the initial `add()`
  instead.
- **`CommonITILValidation` status**: `add()` always forces `status = WAITING`; accepting a validation is
  a deliberate follow-up action gated by `canAnswer()` (only the actual assigned validator/group member
  can change it via the normal API) - see `ScenarioBuilder::forceAcceptValidation()`.

See `CHANGELOG.md` for the full list of issues found during development, each with the failure mode that
surfaced it.
