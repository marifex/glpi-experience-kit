# Administration Guide

## Installing

1. Copy or symlink this directory to `<glpi>/plugins/experiencekit`.
2. Setup > Plugins > Experience Kit for GLPI > **Install**, then **Enable**.
   Installation creates four tables (`glpi_plugin_experiencekit_runs`, `..._registry`,
   `..._phase_progress`, `..._healthchecks`) and a `ProcessBatch` cron task.
3. Setup > Profiles > (a profile) > **Experience Kit** tab: grant the right to any profile that should
   be able to generate/purge data. By default, only profiles that already have Setup > General
   (`config`) read+write get it - this tool can generate large volumes of data and delete it, so it is
   not broadly granted.

## Generating an environment

Open **Tools > Experience Kit** (or the gear icon on Setup > Plugins). With no run currently in
progress, you'll see a **Generate a new environment** form:

- **Volume profile** - Small (quick smoke test), Medium (reproduces the reference dataset's exact
  inventory), or Large (~3x Medium).
- **Organization name** - the display name for the generated root entity (created as a child of GLPI's
  own root entity, never renaming it - safe to run repeatedly, and safe alongside real data).
- **Run name** - optional label shown in the run history.

Click **Generate**. The run starts immediately and processes a first batch synchronously so you see
progress right away; the page polls and auto-refreshes while a run is active. From here:

- **Run now** - process another bounded batch immediately, instead of waiting for the next cron tick.
- **Pause** / **Resume** - stop advancing without losing progress.
- **Cancel** - stops the run and restores notification settings; already-created records stay in place
  (use Purge to remove them).

A background cron task (`PluginExperiencekitRun::ProcessBatch`, runs every minute by default) advances
any `running` run automatically, so leaving the tab and coming back later works fine - you don't have to
keep clicking "Run now". For a Medium or Large profile, expect the full run to take tens of minutes
(ticket creation is the dominant cost, at roughly 150-400ms each due to full business-rule-engine
evaluation on every `Ticket::add()`) - this is expected, not a hang.

Notifications are automatically disabled for the duration of a run and restored to their prior state
afterward (whether it completes, is cancelled, or fails).

## Health checks

From the **Recent Runs** table, click **Health check** next to any run with records. This runs and
persists a fresh set of checks:

- Requester actor-link coverage for Tickets, Problems, and Changes (should always be 100% for anything
  this plugin generated - if it isn't, something is wrong).
- Registry consistency - flags registry-tracked records that were deleted outside the plugin (e.g. by an
  administrator through the normal GLPI UI).

Results appear in a panel on the same page after it reloads.

## Purging

From **Recent Runs**, click **Purge** next to any completed or failed run. You'll get a confirmation
prompt showing the exact number of records that will be permanently deleted. Purge only ever removes
records this specific run created (tracked in the registry) - it can never touch pre-existing data, a
different run's records, or anything created outside the plugin. Like generation, purge is
batched/resumable: it processes a first pass immediately and the cron task carries any remainder. The
run itself is kept afterward (marked "Purged") so its history remains visible.

## Console commands

For SSH access, the same operations are available via `bin/console`, faster than waiting on cron ticks:

```
bin/console plugins:experiencekit:generate -u <username> [--profile=small|medium|large]
                                                            [--organization=NAME] [--name=NAME]
                                                            [--run=ID] [--batch-size=200]
bin/console plugins:experiencekit:purge -u <username> --run=ID | --all [--batch-size=200]
bin/console plugins:experiencekit:health-check [--run=ID]
bin/console plugins:experiencekit:status [--limit=20]
```

`--username` is required for `generate`/`purge` (actor attribution and history logging need a real GLPI
session); `health-check`/`status` are read-only and need no session. `health-check` exits non-zero if
any check fails, so it can gate a CI pipeline. `purge` asks for interactive confirmation unless run with
`--no-interaction` (which then defaults to *not* purging, as a safety default).

## Troubleshooting

- **A run is stuck in "running" with no progress for a long time**: check that the cron task is enabled
  and running (Setup > Automatic actions > `PluginExperiencekitRun ProcessBatch`), or click "Run now" /
  run the `generate --run=ID` console command to advance it manually.
- **Health check reports a FAIL**: this should not happen for anything the plugin itself generated: file
  an issue with the check's summary and details.
- **A purge seems incomplete**: re-run it (from the UI or `purge --run=ID`) - it's idempotent and will
  finish removing whatever's left in the registry for that run.
