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
