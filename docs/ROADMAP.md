# Roadmap

Items below are deliberate scope decisions carried over from `docs/reference/GLPI_DEMO_DATASET_DNA.md`
§7 ("Known limitations - decide explicitly, don't inherit silently"), plus gaps identified while
building the plugin. None of these are silent omissions; each was a conscious call to ship v1.0 without
it.

## Near-term

- **Settings screen** - batch sizes (currently hardcoded per call site: 100 for the admin UI's "Run
  now"/"Purge now", 200 default for cron/console) and default volume profile are not yet
  admin-configurable.
- **Configurable organization name persistence** - the organization name is chosen per-run but there's
  no saved "default" for repeat use.
- **Automated test suite** - current verification is manual, against a live instance (see
  `DEVELOPER_GUIDE.md`). A PHPUnit suite (at minimum: `WeightedDistributor`, `RandomDataProvider`
  determinism, `VolumeProfileFactory` invariants) would catch regressions in the pure-PHP Domain layer
  without needing a full GLPI boot.

## Deliberately out of scope for v1.0

- **OLA records** - only SLA (`glpi_slas`) is populated; `glpi_olas` is empty. Would need `OLA`/`SLM`
  classes following the same TTO/TTR pattern as the existing SLA generation.
- **GLPI 11's Form-based Service Catalog** (`Glpi\Form\ServiceCatalog`) - a complex dynamic-forms engine;
  the older, stable `ITILCategory.is_helpdesk_visible=1` mechanism is used instead. A dedicated
  `Glpi\Form\Form` builder would be separate, substantial work.
- **Multi-step approval workflows** - all validations (onboarding, firewall/VPN CAB) are single-step.
- **GLPI 10.x support** - the `Glpi\Kernel\Kernel` bootstrap and the `User::isValidUserForEntity()` check
  this plugin structurally guards against are both new at the GLPI 11.0 boundary; a v10 target needs a
  different bootstrap and would not exercise the same actor-link failure mode (confirmed by diffing
  GLPI core source across versions - see `docs/reference/GLPI_DEMO_DATASET_DNA.md` §1.5).
- **Industry-specific experience packs** - the architecture supports this (see `DEVELOPER_GUIDE.md`,
  "Adding an industry-specific experience pack") but none beyond the general-enterprise pack exist yet.
- **Per-branch-entity topology customization** - the 3-branch, 50/30/20-weighted org structure is fixed;
  making the branch count/names/weights configurable would need `VolumeProfile` to carry a topology
  shape, not just counts.
