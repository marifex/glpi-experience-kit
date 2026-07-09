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

## What it generates

Organization hierarchy (entities, locations, groups, users with realistic
onboarding/exit cohorts and VIP tagging), a full CMDB (computers, monitors,
network equipment, printers, phones, tablets, software, licenses, contracts,
suppliers), ITSM configuration (categories, calendars, SLM/SLA, 22 business
rules), 7 narrative ITIL scenarios (patching, firewall upgrades, a printer
failure cluster, a VPN outage, onboarding/offboarding, laptop replacement),
a statistical fill of remaining tickets/problems/changes to reach the chosen
volume profile, and a knowledge base with attachments and satisfaction
surveys. Choose a Small, Medium, or Large volume profile; Medium reproduces
the original reference dataset's exact inventory.

Every generated record is safely tracked for purge — running the generator
repeatedly, or removing everything it created, never touches pre-existing
data.

## Documentation

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — layers, the generation
  pipeline, resumability, purge safety, and the permanent fix for the
  original dataset's actor-link bug
- [`docs/ADMIN_GUIDE.md`](docs/ADMIN_GUIDE.md) — installing, generating,
  health checks, purging, console commands
- [`docs/DEVELOPER_GUIDE.md`](docs/DEVELOPER_GUIDE.md) — adding a phase
  builder, conventions, testing approach
- [`docs/ROADMAP.md`](docs/ROADMAP.md) — what's deliberately out of scope
  for v1.0, and why
- [`CHANGELOG.md`](CHANGELOG.md) — full history, including every bug found
  and fixed during development with its concrete failure mode

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
