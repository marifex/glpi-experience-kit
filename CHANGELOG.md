# Changelog

All notable changes to this project are documented in this file.
Format loosely follows [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

## [1.0.2] - 2026-07-19

### Fixed
- `setup.php` was missing `plugin_experiencekit_check_config()`. GLPI's own install/activation flow
  (`Plugin::install()`/`checkPluginState()`) calls `plugin_<key>_check_config()` if it exists and only
  flags a plugin as needing configuration when it returns `false` - the function is technically optional
  (GLPI treats its absence as "nothing to configure"), but most published GLPI plugins define it
  explicitly, and relying on the implicit default was reported to cause activation issues on at least
  one real-world GLPI setup (reported by @JamesM-echo-9, issue #1). Added, returning `true`
  unconditionally (this plugin has no required external configuration before it can run).

## [1.0.1] - 2026-07-09

Full Medium-profile QA (the volume profile that reproduces the original reference dataset's exact §3
inventory), run to completion post-release. Every count matched the reference exactly: 4 Entities, 18
Locations, 21 Groups, 500 Users, 980 assets, 90 Software, 130 SoftwareLicenses, 30 Contracts, 20
Suppliers, **7,500 Tickets, 131 Problems, 250 Changes** (all exact), 180 KB articles, 2,250 Document
attachments (matching the doc's own "~2,250 tickets" note exactly). The health check passed 100% at full
scale - 7,500/7,500, 131/131, 250/250 requester actor links - the doc's own historical "100% coverage
after remediation" figure, now achieved by design on the very first run rather than a retrofit.

### Fixed
- `HealthCheckService::checkRegistryOrphans()`'s `WHERE field IN (...)` query, given the full list of a
  run's registered ids for one itemtype in a single call, exceeded MySQL's default
  `range_optimizer_max_mem_size` at Medium-profile volume (7,500 Ticket ids) - confirmed via the
  resulting warning; MySQL fell back to a full table scan rather than failing, so results stayed
  correct, but this only surfaces at realistic production volume, never at the Small-profile scale used
  for day-to-day development testing. Chunked into batches of 1,000.

## [1.0.0] - 2026-07-09

First feature-complete release: all six generation phases, admin UI, background cron processing,
health checks, purge, and console commands, verified end-to-end against a live GLPI 11.0.8 instance.
See `docs/PROJECT_STATUS.md` for the verification summary.

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
- `KbAttachmentSurveyBuilder`: the sixth and final phase builder -
  Knowledge base articles (8 categories, ~30% flagged FAQ), Document
  attachments (~30% of all tickets, via 10 reusable placeholder templates
  rather than one unique file per ticket), and TicketSatisfaction surveys
  (~30% of closed tickets, weighted 1★=5%/2★=7%/3★=18%/4★=35%/5★=35%).
  This closes the full pipeline: a Small-profile run through all six
  phases now reaches `status=completed` end to end for the first time.
- `HealthCheckService` - automated regression checks so the §5 bug class
  can never ship silently again: requester-actor-link coverage for
  Ticket/Problem/Change (the doc's own verification query, generalized to
  all three itemtypes and scoped to registry-tracked records only, so a
  customer's own data is never flagged), plus a registry-consistency
  check for records deleted outside the plugin. Verified against live
  data both ways: a clean run passes all checks, and deliberately
  deleting one ticket's requester link is correctly caught
  ("1 of 750 records are missing a requester link").
- `PurgeOrchestrator` - registry-driven, bounded/resumable deletion
  (reverse creation order, same batching/cron-driven shape as
  generation). Every delete is scoped to registry rows only, so a run can
  never touch a record it didn't create - confirmed against live data: a
  full Small-profile run's 1,522 records across 21 itemtypes were removed
  cleanly, the database returned to its exact pre-existing baseline, and
  the run row itself is kept (marked `purged`) as a history record rather
  than deleted.
- Admin UI: Purge (with a confirmation prompt showing the exact record
  count) and Health Check actions on the Recent Runs table, a live
  progress view for in-progress purges, and a results panel for the most
  recently run health check.
- Console commands (the doc's own §9 recommendation, as a fast SSH-driven
  alternative to waiting on cron ticks), auto-discovered by GLPI's
  `bin/console` under the `plugins:experiencekit:` namespace and built on
  `Glpi\Console\AbstractCommand` (reusing its `--username`-based session
  bootstrap, the same `Auth`/`Session::init()` trick documented in §2.1,
  rather than reimplementing it): `generate` (start or `--run=ID` resume,
  loops the same `GenerationOrchestrator` the UI/cron use until done),
  `purge` (`--run=ID` or `--all`, interactive confirmation showing the
  exact count unless `--no-interaction`), `health-check` (`--run=ID` or
  every run; exits non-zero on any FAIL so it can gate a CI pipeline -
  the doc's own "automated regression test" ask), and `status` (a table
  of recent runs and their record counts). Verified end-to-end via
  `bin/console`: a full Small-profile `generate` reached "completed",
  `health-check` against it passed all checks (750/750, 14/14, 40/40
  requester links), and `purge` (confirmed via piped stdin) removed all
  1,526 records and restored the database to its exact baseline.

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
- `RandomDataProvider::weightedPick()` was declared to return `string`,
  but PHP normalizes purely-numeric string array keys back to `int`
  (`array_combine(array_map('strval', ...), ...)` does *not* prevent
  this - a well-known PHP gotcha), so a caller with numeric-looking keys
  (survey star ratings 1-5) crashed with a `TypeError` the first time it
  ran. Relaxed the return type to `int|string`, matching what PHP array
  keys actually are.
- Not a bug, but worth documenting so it isn't "fixed" into a real one:
  `TicketSatisfaction::getIndexName()` returns `'tickets_id'`, not `'id'`,
  so `add()` returns the ticket's id, not the satisfaction row's own
  auto-increment id. Confirmed this is intentional and consistent -
  `getFromDB()`/`delete()` key off the same `getIndexName()`, so
  registering that returned value is exactly what a later purge needs,
  despite looking like the wrong id at first glance.
- The `getIndexName() !== 'id'` lesson above resurfaced twice more once
  purge/health-check code needed to look records up generically across
  every itemtype the plugin creates, rather than one builder knowing its
  own object's shape: `HealthCheckService`'s registry-orphan check
  hardcoded the `id` column, wrongly flagging every `TicketSatisfaction`
  row as "orphaned" (confirmed empirically: 203 of 203 flagged, a 100%
  false-positive rate) - fixed by querying `$itemtype::getIndexName()`
  instead of assuming `id`. `PurgeOrchestrator::purgeNextBatch()` had the
  same issue in its `delete()` call, which "worked" but silently logged
  an `Undefined array key "tickets_id"` warning on every `TicketSatisfaction`
  deletion - fixed the same way.
