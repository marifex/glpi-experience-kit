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
