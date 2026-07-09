# Changelog

All notable changes to this project are documented in this file.
Format loosely follows [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

### Added
- Plugin skeleton: `setup.php`/`hook.php`/`autoload.php`, PSR-4 autoloading under
  `GlpiPlugin\Experiencekit\`.
- Four core tables: `glpi_plugin_experiencekit_runs`, `..._registry`,
  `..._phase_progress`, `..._healthchecks`.
- `plugin_experiencekit_use` right, granted by default only to profiles that
  already have Setup > General (config) read+write.
- `PluginExperiencekitRun::ProcessBatch` cron task (registered, handler lands
  with the generation orchestrator).
- Minimal admin dashboard at Setup > Plugins and under the Tools menu.
- Domain layer: `VolumeProfile` + `VolumeProfileFactory` (Small/Medium/Large
  presets, Medium reproducing the original dataset's §3 inventory exactly),
  `GenerationPhase` enum for the six data-generating phases.
- Application layer: `GenerationOrchestrator` (single code path advancing a
  run one bounded batch at a time, shared by cron/UI/console),
  `EntityScopedActorResolver` (the permanent §5 fix - entities_id is always
  derived from the chosen requester, never hardcoded), `RunContext`,
  `PhaseBuilderInterface`.
- Infrastructure persistence repositories for runs/registry/phase-progress/
  health-checks, and an `OrchestratorFactory` composition root.
- `OrgStructureBuilder`: the first phase builder - Entities, Locations,
  Groups, Users, reproducing the original dataset's org design (§3-§4)
  exactly (50/30/20 branch weights, the fixed 9-group support taxonomy,
  VIP/onboarding/exited cohort tagging by creation sequence). Seeded,
  reproducible generation via `RandomDataProvider` and `WeightedDistributor`
  (no new Composer dependency).
- Admin UI: a working Generate tab (`front/config.php`) - start a run
  (volume profile, organization name, run name), live per-phase progress
  bars with AJAX polling (`ajax/run_status.php`), Run now/Pause/Resume/
  Cancel controls, and a recent-runs table. This closes the first full
  vertical slice: click Generate in the browser → cron (or "Run now")
  advances the run in bounded batches → real GLPI Entities/Locations/
  Groups/Users appear, all registry-tracked.
- `organization_name` column on `glpi_plugin_experiencekit_runs` (defaults
  to "MarifeX", configurable per run from the Generate form).
- `SequentialPhaseBuilder`: extracted the "process an ordered list of N-of-X
  stages, resumable via the registry" logic shared by `OrgStructureBuilder`
  and `CmdbBuilder` into a common base class.
- `CmdbBuilder`: the second phase builder - States, Manufacturers,
  PeripheralType, ContractTypes, SoftwareLicenseTypes, Suppliers, Software,
  SoftwareLicenses, the six asset types, and Contracts (~10% pre-expired,
  ~10% expiring within 45 days). Retired/Disposed computers are tagged
  `retired_computer` in the registry for the future laptop-replacement
  scenario to find. Shared taxonomy dropdowns (States, Manufacturers, etc.)
  are resolved idempotently (find-by-name-or-create) and deliberately never
  registered for purge, since they're reusable GLPI-wide values, not this
  run's content.
- `ItsmConfigBuilder`: the third phase builder - the 26-category tree
  (5 parents × children), 3 calendars (Standard Business Hours, 24/7
  Support, EMEA Business Hours), the default SLM, all 16 SLAs (4 tiers ×
  2 ticket types × TTO/TTR), and all 22 `RuleTicket` business rules (14
  keyword/category routing, urgent-priority escalation, VIP-requester SLA
  assignment, category-tier SLA defaults, Bronze fallback defaults) -
  extracted directly from the original hand-built dataset still present in
  this dev database as the ground-truth reference, not re-derived from the
  doc's prose. ITSM configuration is deliberately organization-wide shared
  state (like CmdbBuilder's taxonomy) - created once, reused by later runs,
  same as States/Manufacturers - since duplicate parallel rule engines
  across runs would conflict with each other in the same GLPI instance.
- `ScenarioBuilder`: the fourth phase builder - the 7 narrative ITIL chains
  (§4): monthly Windows patching (24 Changes), quarterly firewall upgrade
  (8 Changes, CAB-approved), printer failure cluster (16 Incidents → 3
  correlated Problems via 3 real Printer assets), VPN outage (29 Incidents
  across 3 major-incident events → 3 Problems → 3 emergency Changes,
  CAB-approved), onboarding (N Service Requests tied to the onboarding
  cohort's begin_date, Supervisor TicketValidation), offboarding (N Service
  Requests tied to the exited cohort's end_date, asset reclaim), and laptop
  replacement (tied to CmdbBuilder's `retired_computer`-tagged Computers,
  Change_Ticket + Item_Ticket links, computer comment updated). Every
  Ticket/Problem/Change gets an explicit requester via
  `EntityScopedActorResolver` - not just Tickets, matching the original
  dataset's "100% coverage" requirement for all three itemtypes.
- `RegistryRepository`/`RunContext`: `scenario_tag`-aware
  `registeredIds()`/`registeredCount()`, and a `tag` key on
  `SequentialPhaseBuilder` stage definitions - needed once two stages in
  the same phase register the same itemtype (e.g. patching and firewall
  both creating `Change` records) and must track independent targets.
- `ActiveUserFinder`: extracted the "users that will actually pass
  `User::isValidUserForEntity()` as an actor" query (is_active, begin/
  end_date validity) out of `ScenarioBuilder` into a shared support class,
  used by both it and `BulkTicketBuilder` rather than duplicating the
  exact same query in two places.
- `BulkTicketBuilder`: the fifth phase builder - statistical fill of the
  remaining Incidents/Requests/Problems/Changes needed to reach the
  volume profile's totals, after the 7 narrative scenarios already
  accounted for some of each (target = `profile.X - scenarioCount`,
  computed dynamically per run rather than hardcoded). By far the most
  expensive phase (~150-400ms per `Ticket::add()` due to full rule-engine
  evaluation, matching the doc's own §8 benchmark) - exactly why
  batching/resumability matters most here.

### Fixed
- `front/config.php`'s legacy `../../../inc/includes.php` relative include
  broke under this repo's OneDrive-junction deployment (PHP resolves
  `__DIR__` for junctioned paths to the physical target directory on
  Windows, which has a different ancestor depth than `glpi/plugins/...`).
  Anchored on GLPI's own `GLPI_ROOT` constant instead.
- `CommonDBTM::update()`'s change-detection uses loose `!=`, and PHP's
  `null == 0` is `true`, so writing `0` into a `NULL` column via `update()`
  was silently dropped. The run's saved notification state is now written
  directly in the initial `add()` call instead.
- The default org root entity ("MarifeX", from the original hand-built
  dataset already present in this dev DB) collided with the plugin's own
  hardcoded default org name, producing a confusing "MarifeX > MarifeX"
  tree. Made the organization name a per-run parameter instead.
- `Migration::addField(..., 'varchar', ...)` isn't a recognized field type
  (`fieldFormat()` only knows `'string'`/`'str'`) and silently produced
  malformed SQL. Fixed to `'string'`.
- Every form on the Generate tab posted to the wrong URL: GLPI 11 serves
  legacy plugin files through a Symfony route
  (`Glpi\Controller\LegacyFileLoadController`), which rewrites
  `$_SERVER['PHP_SELF']` to the router's own script instead of this file's
  actual path. Anchored form actions on `Plugin::getWebDir()` instead.
- `CommonDBTM::getFromDBByCrit()` throws `TooManyResultsException` on an
  ambiguous match rather than returning one; a stock GLPI "OEM"
  `SoftwareLicenseType` fixture plus an earlier data load's own "OEM" both
  matched, crashing taxonomy resolution. Switched to `find(..., limit: 1)`,
  which tolerates duplicates.
- Branch-entity assignment for CMDB assets put 100% of a batch in a single
  entity instead of the intended 50/30/20 split: the picker derived its
  random roll as `seq % 1_000_000` offset by a large per-asset-type
  constant, so a small asset count (e.g. 40 computers) never spanned more
  than one bucket of a million-wide weighted range. Replaced with the same
  proven per-call `RandomDataProvider::weightedPick()` already used
  correctly for state assignment.
- `Migration::createRule()` has a real bug in GLPI core: its guard is
  `is_a($rule['sub_type'], Rule::class)`, and PHP's `is_a()` returns `false`
  for a class-name string unless `$allow_string=true` is passed - which
  `createRule()` never does. Confirmed against GLPI's own install
  migrations, which would hit the identical issue. Every rule after the
  first name collision was silently dropped. Rebuilt rule creation directly
  via `RuleTicket`/`RuleCriteria`/`RuleAction`'s own `add()` methods instead.
- `SequentialPhaseBuilder`'s resumability (registered-count vs. target)
  cannot terminate for a stage where some target items are legitimately
  found-not-created (e.g. rule names colliding with pre-existing ones):
  registeredCount can never reach the target, so the phase re-attempts the
  same already-satisfied items every batch tick forever. Confirmed
  empirically (completed_units reached 367 against a total of 22). Moved
  rule creation out of the stage-counting pattern entirely, matching how
  CmdbBuilder's taxonomy is already handled.
- `ScenarioBuilder`'s onboarding/offboarding stage targets were hardcoded
  to the Medium profile's exact counts (40/27) instead of reading
  `$context->profile->usersOnboardingCohort`/`usersExited`, so a Small
  profile run (target 6/4) kept trying to process sequence indices past
  the actual cohort size once it ran out of users.
- `User::isValidUserForEntity()` - the check §5's fix is built around -
  also requires `is_active=1` and a valid `begin_date`/`end_date` window,
  not just a correctly-derived entity. Picking an exited user as a
  scenario requester produces the exact same silently-missing-actor-link
  symptom via a different root cause. Confirmed empirically (1 of 58
  scenario tickets had no requester link). `ScenarioBuilder`'s requester
  pickers now restrict to `activeUserIds()`, replicating GLPI's own
  validity condition so any picked user is guaranteed to pass.
- `ChangeValidation`/`TicketValidation` status stayed `WAITING` (2) despite
  passing `status => ACCEPTED` on `add()`: `prepareInputForAdd()`
  unconditionally forces `WAITING` on creation, and the natural follow-up
  `update()` silently strips `status` too, because
  `prepareInputForUpdate()` only allows it when `canAnswer()` is true -
  i.e. the *current session user* is the validation's actual target, which
  a Super-Admin demo-generation session essentially never is. This is a
  deliberate GLPI security gate, not a bug. Added a narrow, explicitly-
  justified raw `UPDATE` of just the `status`/`validation_date` columns,
  scoped to rows this same method just created.
