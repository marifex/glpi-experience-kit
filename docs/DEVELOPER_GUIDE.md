# Developer Guide

## Local setup

The CLI bootstrap pattern used throughout development (and by the console commands) - no password
needed, since `Auth` is constructed directly rather than via `Auth::login()`:

```php
$glpiRoot = 'C:/laragon/www/glpi';
chdir($glpiRoot);
require_once $glpiRoot . '/vendor/autoload.php';
$kernel = new \Glpi\Kernel\Kernel('production');
$kernel->boot();

$user = new User();
$user->getFromDB(2); // built-in super-admin
$auth = new Auth();
$auth->auth_succeded = true;
$auth->user = $user;
Session::init($auth);

require_once $glpiRoot . '/plugins/experiencekit/autoload.php';
```

This is exactly what `Glpi\Console\AbstractCommand::loadUserSession()` does under the hood for the
shipped console commands.

## Adding a new phase builder

1. Add a case to `src/Domain/GenerationPhase.php` (order matters - it's appended after whichever phase
   it depends on).
2. Create `src/Infrastructure/Builder/YourBuilder.php`. For a builder that's just "create N of type X,
   then N of type Y, ...", extend `SequentialPhaseBuilder` and implement `getPhase()` + `stages()`
   (see `OrgStructureBuilder` or `ScenarioBuilder` for examples of simple vs. more involved stages).
   Otherwise implement `PhaseBuilderInterface` directly (`plan()`/`runBatch()`).
3. Every GLPI object your builder creates must go through `RunContext::register($phase, $itemtype,
   $itemsId, $scenarioTag = null)` before the batch call returns - this is what makes it purge-safe.
   Use a `$scenarioTag` whenever two stages in the same phase create the same itemtype.
4. If your builder creates Tickets/Problems/Changes, resolve `entities_id` via
   `EntityScopedActorResolver::entityForRequester()` - never hardcode it (see docs/ARCHITECTURE.md,
   "The §5 fix, permanently"). Pick requesters via `ActiveUserFinder`, not a raw random pick from all
   registered users - an inactive/not-yet-active user will silently fail the actor-link check for an
   unrelated reason.
5. Register your builder in `src/Infrastructure/Support/OrchestratorFactory::builders()`.
6. Add the volume-profile field(s) your builder needs to `src/Domain/VolumeProfile.php` and its
   Small/Medium/Large values in `VolumeProfileFactory`.

## Adding an industry-specific "experience pack"

The architecture was designed for this: a new pack is a new set of phase builders (or a variant of the
existing ones) plus a new `GenerationPhase` set and `VolumeProfile` shape, wired into a parallel
`OrchestratorFactory`-style composition root. Nothing in `GenerationOrchestrator`, the registry, the
admin UI, or the console commands is specific to the current pack's content - they all operate on
`PhaseBuilderInterface`/`VolumeProfile` abstractions.

## Testing approach used during development

There is no automated test suite yet (see `ROADMAP.md`). Every builder was verified by running it
end-to-end against a live GLPI 11 instance and inspecting the actual resulting records - both via direct
SQL SELECTs (read-only) and the GLPI UI - not just "the code runs without throwing." For any change that
touches actor linking, always re-verify with `plugins:experiencekit:health-check` after a run; it is the
regression test for the §5 bug class specifically.

A throwaway cleanup pattern (delete every registry-tracked itemtype for a run, in reverse creation
order, then the registry/phase_progress/run rows) was used before `PurgeOrchestrator` existed; that
orchestrator is now the supported way to do this and should be used instead of writing ad-hoc scripts.

## Conventions

- Never write raw SQL for anything GLPI's own business-logic classes (`->add()`/`->update()`/
  `->delete()`) can do - the project's explicit mandate. The two documented exceptions
  (`ScenarioBuilder::forceAcceptValidation()`, working around a deliberate `canAnswer()` permission gate)
  are narrow, commented, and scoped to rows the same method just created.
- Shared taxonomy (States, Manufacturers, ITIL Categories, Calendars, SLAs, business rules, KB
  categories, Document templates) is created once and reused across runs via idempotent find-or-create,
  registered only when actually created by *this* run - see `CmdbBuilder`/`ItsmConfigBuilder`/
  `KbAttachmentSurveyBuilder` for the pattern. Duplicate parallel taxonomies across runs would conflict
  with each other in the same GLPI instance (e.g. two "In Use" States, ambiguous rule matching).
- Every GLPI class's "identifier" field is not always literally `id` - check `getIndexName()` before
  writing generic (registry-wide) code that queries or deletes by ID. See CHANGELOG.md for the concrete
  failure mode this caused twice.
