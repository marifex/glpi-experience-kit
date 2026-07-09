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
