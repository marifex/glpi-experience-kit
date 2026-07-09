# GLPI Experience Kit

A GLPI 11 plugin that generates a realistic, fully configured enterprise ITSM
environment — organization hierarchy, CMDB, contracts, knowledge base,
calendars, SLAs, business rules, and narrative incident/problem/change
scenarios — entirely through GLPI's own business-logic classes. Install it,
pick a volume profile, and get a demo-ready GLPI instance in minutes instead
of hand-building one.

This plugin is the productionized successor to a one-off dataset-generation
effort; the original findings and lessons learned (including a critical
requester-actor-link bug and its fix) are preserved at
[`docs/reference/GLPI_DEMO_DATASET_DNA.md`](docs/reference/GLPI_DEMO_DATASET_DNA.md)
and are baked into this plugin's design rather than treated as a one-time fix.

## Status

Under active development. See [`docs/ROADMAP.md`](docs/ROADMAP.md) once
published for the current build phase.

## Requirements

- GLPI 11.0 – 11.99.99
- PHP 8.2+
- MySQL/MariaDB with `innodb_buffer_pool_size` >= 512MB recommended for
  large-profile generation

## Installation

1. Copy or symlink this directory to `<glpi>/plugins/experiencekit`.
2. In GLPI: Setup > Plugins > GLPI Experience Kit > Install > Enable.
3. Setup > Profiles > (your profile) > Experience Kit tab: grant the right
   to the profiles that should be able to generate/purge data. By default
   only profiles with Setup > General (config) read+write get it.

## License

Proprietary — MarifeX.
