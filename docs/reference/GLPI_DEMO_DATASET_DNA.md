# GLPI Enterprise Demo Dataset — Completion Report & Plugin-Build Reference

**Purpose of this document:** a complete, reproducible record of what was generated, how it was generated, and every pitfall hit along the way — written so this can be re-implemented as a proper GLPI plugin (e.g. a `bin/console demodata:generate` command) instead of the ad-hoc CLI scripts used here.

---

## 1. Environment

| Component | Version / detail |
|---|---|
| GLPI | 11.0.8 (schema `11.0.8@7b129479687ebafebb6966eaa1d72cb683390613`) |
| PHP | 8.2.32 CLI (ZTS, Visual C++ 2019 x64) |
| MySQL | 8.4.3 (Laragon), database `glpi11_db` |
| `innodb_buffer_pool_size` | tuned 128MB → **512MB**, persisted in `my.ini` (default was too small even for this modest dataset) |
| Web server | Apache 2.4.66, vhost `glpi.test`, `DocumentRoot = C:/laragon/www/glpi/public` |
| Plugins installed | `multilingual` only (not exercised by this dataset — explicitly out of scope per instruction) |
| Final DB footprint | ~60.6MB, ~49.5K rows before actor-link remediation (grew modestly after; still trivial for the tuned buffer pool) |

---

## 1.5 Version compatibility — GLPI 11 only, verified by source diff

Everything in this document was built and tested against **GLPI 11.0.8**. Two load-bearing pieces are **confirmed absent in GLPI 10.x** by directly diffing source across tags on GitHub (glpi-project/glpi), not by assumption:

**(a) The `Glpi\Kernel\Kernel` bootstrap is new at the 11.0 major version boundary.**
- `10.0.0/bin/console` and `10.0.18/bin/console` (both checked): legacy bootstrap — `include_once GLPI_ROOT.'/inc/based_config.php'`, `inc/db.function.php`, then `new \Glpi\Console\Application(); $application->run();`. No `Glpi\Kernel\Kernel` reference anywhere in either version.
- `11.0.0/bin/console` (the very first 11.x release): `$kernel = new \Glpi\Kernel\Kernel($env); $application = new \Glpi\Console\Application($kernel);` — matches what this dataset's `bootstrap.php` relies on.
- **Consequence:** `bootstrap.php` as written in §2.1 will not run on GLPI 10.x. A v10-targeting plugin needs the legacy `inc/includes.php`-style init instead (still ends with the same `Auth`/`Session::init()` trick, just a different path to get `$DB`/`$CFG_GLPI` wired).

**(b) The entity-validity actor-link bug (§5) is also new in v11 — it would not reproduce on v10.**
- GLPI 11 `CommonITILObject::updateActors()` gates every User actor through `User::isValidUserForEntity($userId, $entitiesId)` before adding it — this is what silently dropped requester links in this dataset.
- GLPI 10.0.18's equivalent block is simply `if ($found === false) { $added[] = $actor; }` — no entity-validity check at all. `isValidUserForEntity` does not exist anywhere in `User.php` or `CommonITILObject.php` in that version.
- **Consequence:** a v10 plugin is more permissive here and wouldn't hit this specific failure mode — but still set `entities_id` from the actor's home entity anyway; it's correct ITSM modeling regardless of version, and relying on undocumented permissiveness is not a safe cross-version strategy.

**If the plugin must support both v10 and v11:** branch on `GLPI_VERSION` for (1) which bootstrap sequence to use, and (2) whether the entity/actor health-check query in §5 is even a meaningful regression test on that version (it's a real bug on 11.x, a non-issue on 10.0.18 as verified — unverified on other 10.x point releases).

---

## 2. Architecture: how data was injected

**Everything was created through GLPI's own business-logic classes (`->add()` / `->update()`), never raw SQL** — this preserves the rule engine, history logging, and referential integrity exactly as if a human had used the UI or the REST API.

### 2.1 CLI bootstrap pattern (the reusable recipe)

```php
<?php
// bootstrap.php
$glpiRoot = 'C:/laragon/www/glpi';
chdir($glpiRoot);
require_once $glpiRoot . '/vendor/autoload.php';

$kernel = new \Glpi\Kernel\Kernel('production');
$kernel->boot(); // wires DI container, $DB, $CFG_GLPI via PostBootListener chain

// CLI SAPI uses Session::initVars() (in-memory $_SESSION), not a real PHP session.
// Establish a full-rights session WITHOUT needing a password:
$user = new User();
$user->getFromDB(2); // built-in 'glpi' super-admin account

$auth = new Auth();
$auth->auth_succeded = true;
$auth->user = $user;

Session::init($auth); // sets $_SESSION['glpiID'], glpiactiveprofile (Super-Admin), glpiactiveentities, etc.
```

This is the same mechanism GLPI's own test suite uses. No password is ever needed since `Auth` is constructed directly rather than calling `Auth::login()`.

### 2.2 Generation phases (order matters — each depends on the previous)

| # | Script | Creates |
|---|---|---|
| 0 | `bootstrap.php` + smoke test | validates kernel boot + session + a throwaway `Entity` add/purge |
| 1 | `01_org_structure.php` (+ `01b_fix_onboarding_count.php` patch) | Entities, Locations, Groups, Users |
| 2 | `02_cmdb.php` | States, Manufacturers, PeripheralType, ContractTypes, SoftwareLicenseTypes, Suppliers, Software, SoftwareLicenses, Computers, Monitors, NetworkEquipment, Printers, Phones, Peripherals(Tablets), Contracts |
| 3 | `03_itsm_config.php` | ITILCategories, Calendars+Segments, SLM+SLAs, 22 RuleTicket business rules |
| 4 | `04_scenarios.php` (+ `04c`/`04e`/`07` patches) | 7 narrative scenario threads (see §4) |
| 5 | `05_bulk_tickets.php` | remaining statistical Incidents/Requests/Problems/Changes |
| 6 | `06a_kb_only.php`, `06b_attachments_surveys.php` (+`06c` fix) | KB articles, Document attachments, TicketSatisfaction surveys |
| 9 | `09_fix_actor_links.php` | **critical remediation** — see §5 |

Each phase persists a `state_phaseN.json` file (entity/group/user/category/SLA IDs) so later phases don't need to re-query — a pattern worth keeping in a plugin (makes phases resumable/idempotent).

---

## 3. Full data inventory (exact final counts)

| Object | Count | Detail |
|---|---:|---|
| Entities | 4 | Root "MarifeX" + 3 children: HQ‑New York (50% weight), West‑Austin (30%), EMEA‑London (20%) |
| Locations | 18 | 5 NY + 3 Austin + 2 London + 8 shared/global (DC, DR site, exec suite, etc.) |
| Groups | 21 | 12 department + 6 technician/support + 2 CAB + 1 VIP-tagging utility group |
| Users | 500 | 473 active / 27 exited (`end_date` + `is_active=0`); 450 Self-Service + 30 Technician + 10 Supervisor + 10 Admin; 18 tagged VIP (via group); 40 "onboarding cohort" grew headcount 460→500 over the timeline |
| — password | — | all synthetic users share `Demo!2026`; built-in `glpi`/`glpi` admin untouched |
| Assets | 980 | 400 Computer, 300 Monitor, 50 NetworkEquipment, 50 Printer, 150 Phone, 30 Peripheral(Tablet) |
| Manufacturers | 14 | Dell, HP, Lenovo, Apple, Cisco, Microsoft, Samsung, Brother, Netgear, Ubiquiti, Logitech, ASUS, Fortinet, Epson |
| Asset lifecycle States | 4 | In Use 75% / New‑Procurement 10% / In Repair 8% / Retired‑Disposed 7% |
| Software | 90 | | 
| SoftwareLicenses | 130 | across 5 SoftwareLicenseTypes (OEM, Volume, Subscription, Per Device, Per User) |
| Contracts | 30 | across 5 ContractTypes; ~10% pre-expired, ~10% expiring within 45 days (renewal-alert demo), rest healthy |
| Suppliers | 20 | |
| ITIL Categories | 26 | 5 trees × children — Hardware(5), Software(4), Network & Connectivity(4), Access & Account(4), Facilities(4) — all `is_helpdesk_visible=1` |
| Calendars | 3 | Standard Business Hours (Mon–Fri 08–18), 24/7 Support, EMEA Business Hours (Mon–Fri 08–17) |
| SLM | 1 | "Default SLM" |
| SLA | 16 | 4 tiers (Gold/Silver/Bronze/VIP) × 2 ticket types × {TTO,TTR} — Gold 1h/4h, Silver 4h/24h, Bronze 8h/72h, VIP 15min/2h |
| Business Rules | 22 | `RuleTicket`, condition=ONADD (see §6 for exact criteria/actions) + GLPI's own 2 stock rules |
| Tickets | 7,500 | 4,800 Incidents + 2,700 Requests |
| Problems | 131 | 130 planned + 1 patched (see §5) |
| Changes | 250 | |
| KB Articles | 180 | across 8 KB categories, ~30% flagged FAQ |
| Attachments | 2,250 tickets | ~30% of tickets, via 10 reusable `Document` templates (small `.txt` placeholders, not unique files per ticket) |
| Satisfaction Surveys | 2,099 | ~30% of 6,999 closed tickets; weighted 1★=5%, 2★=7%, 3★=18%, 4★=35%, 5★=35% |
| TicketValidation | 40 | onboarding manager approvals |
| ChangeValidation | 11 | 8 firewall (CAB) + 3 VPN emergency (expedited CAB) |
| Item_Ticket links | 43 | 16 printer-scenario + 27 laptop-replacement |
| Problem_Ticket links | 45 | 16 printer + 29 VPN |
| Change_Problem links | 3 | VPN emergency changes |
| Change_Ticket links | 27 | laptop replacement |
| Ticket/Problem/Change requester actor links | 7,500 / 131 / 250 | **100% coverage after remediation** (see §5) |

---

## 4. The 7 narrative scenarios (ITIL-chain design)

| Scenario | Volume | Chain |
|---|---|---|
| Monthly Windows patching | 24 Changes | 1/month, evening hours, Standard Change, priority 2, assigned Systems & Infrastructure Team |
| Quarterly firewall upgrade | 8 Changes | CAB-approved via `ChangeValidation` (`itemtype_target='Group'`, IT Change Advisory Board), priority 3 |
| Printer failure cluster | 16 Incidents → 3 Problems | 3 real Printer assets, 5–6 recurring incidents each, correlated into 1 Problem per printer, linked via `Problem_Ticket` + `Item_Ticket` |
| VPN outage | 3× (1 Major Incident + ~9 related) = 29 Incidents → 3 Problems → 3 Emergency Changes | Major Incident = priority 6 ("Major"); related incidents priority 5; Problem correlates all; emergency Change approved near-simultaneously via `Security & Infrastructure CAB` |
| Onboarding | 40 Service Requests | tied to the 40-user onboarding cohort's `begin_date`; `TicketValidation` from a random Supervisor |
| Exit/Offboarding | 27 Service Requests | tied to the 27 exited users' `end_date`; requester = random Supervisor; owned Computer/Phone/Peripheral unassigned (`users_id=0`) |
| Laptop replacement | 27 Requests + Changes | tied to the 27 computers in "Retired/Disposed" state (7% of 400); linked via `Change_Ticket` + `Item_Ticket`; old computer's comment updated |

---

## 5. ⚠️ The critical bug — read this before building a plugin

**Symptom:** ~99.8% of generated Tickets (and most Problems/Changes) had no requester actor link (`glpi_tickets_users` empty for that ticket) despite `_users_id_requester` being passed to every `Ticket::add()` call. No error, no exception — just silently missing.

**Root cause:** `CommonITILObject::updateActors()` (called from both `post_addItem()` and `post_updateItem()`) filters every candidate User actor through:

```php
if ($actor['itemtype'] === User::class && $actor['items_id'] > 0 && $found === false) {
    if (User::isValidUserForEntity($actor['items_id'], $this->fields['entities_id'])) {
        $added[] = $actor;   // only added if this passes
    }
}
```

`User::isValidUserForEntity($userId, $entityId)` checks whether the user's `glpi_profiles_users` entity **matches or is a recursive ancestor-relationship with** the ITIL object's `entities_id`. Every synthetic user in this dataset was scoped **non-recursively** to one of the 3 branch entities (2/3/4) — never to entity 0 (root). Nearly every generated ticket/problem/change was hardcoded to `entities_id => 0`. Root is the *parent* of the branches, not a descendant — recursive rights cascade **downward only** — so the check silently failed for virtually every combination.

**Fix, in two parts:**
1. **Prevention (do this in the plugin from day one):** never hardcode a shared `entities_id`. Always compute it from the chosen requester: `entities_id = <requester's glpi_profiles_users.entities_id>`. This is also the architecturally correct behavior — a ticket's entity should reflect who filed it.
2. **Retrofit (if you already have orphaned records):** do **not** try to fix via `CommonITILObject::update()` passing `_users_id_requester` again — if the record is closed and not new, `updateActors()`'s per-actor-type gate additionally requires `canUpdateItem()`, which can block re-adding actors on closed items. Instead, bypass `updateActors()` entirely and call the raw link class directly:
   ```php
   $tu = new Ticket_User();
   $tu->add(['tickets_id' => $tid, 'users_id' => $correctUserId, 'type' => 1]); // type 1 = requester
   ```
   `Ticket_User::add()` (and `Problem_User`, `Change_User`) do **not** replicate the entity-validity gate — confirmed empirically. Combine with a plain `update(['id'=>$tid,'entities_id'=>$correctEntity])` (no actor keys) to fix the entity field without re-triggering actor processing.

**Verification query pattern** (use this as a health check in the plugin):
```sql
SELECT COUNT(*) FROM glpi_tickets t
WHERE NOT EXISTS (SELECT 1 FROM glpi_tickets_users tu WHERE tu.tickets_id=t.id AND tu.type=1);
-- should be 0 (or equal only to intentionally-anonymous tickets)
```

---

## 6. Business rules — exact criteria/action field names (RuleTicket)

Confirmed-valid field keys (from reading `RuleCommonITILObject`/`RuleTicket` source, not guessed):

- **Criteria:** `name` (title), `content` (description), `itilcategories_id`, `_groups_id_of_requester`, `priority`, `type` (Ticket::INCIDENT_TYPE=1 / Ticket::DEMAND_TYPE=2), `slas_id_ttr`, `slas_id_tto`
- **Actions:** `itilcategories_id` (assign), `_groups_id_assign` (assign — target group must have `is_assign=1`), `priority` (assign), `slas_id_ttr` / `slas_id_tto` (assign)
- **Pattern operators used:** `Rule::PATTERN_CONTAIN` (2), `Rule::PATTERN_IS` (0), `Rule::PATTERN_UNDER` (11, for category-tree matching)
- **Condition:** `RuleCommonITILObject::ONADD` (1) — rules only fire on ticket creation, never on update, avoiding any risk of the fix-up scripts re-triggering rule evaluation.

22 rules total: 14 keyword/category routing (printer, VPN, wifi, password, install, error, laptop, network-outage, onboarding, offboarding, security, badge, mobile, upgrade), 1 urgent-keyword priority escalation, 2 VIP-requester SLA assignment (Incident/Request), 3 category-tier SLA defaults (Hardware→Gold, Network→Gold, Software→Silver), 2 fallback default SLA (Incident/Request→Bronze).

---

## 7. Known limitations (decide explicitly in the plugin, don't inherit silently)

1. **No OLA records** — only SLA (`glpi_slas`) was populated. `glpi_olas` is empty. If OLA breach tracking matters to the plugin's consumers, it must be added separately (`OLA`/`SLM` classes, same TTO/TTR pattern).
2. **Notifications intentionally left OFF** (`use_notifications=0`, `notifications_mailing=0`) — no SMTP was configured. A plugin should treat this as a configurable toggle, not silently flip it on (risk of mail-queue flooding on instances that *do* have SMTP configured).
3. **GLPI 11's new Form-based Service Catalog** (`Glpi\Form\ServiceCatalog`) was **not used** — deliberately. It's a complex dynamic-forms engine; building it correctly via blind API calls (no interactive testing) was judged too risky. Used the older, stable `ITILCategory.is_helpdesk_visible=1` mechanism instead. A plugin wanting a modern Service Catalog demo needs separate `Glpi\Form\Form` work.
4. **`items_id`/`itemtype` are NOT valid top-level `Ticket::add()` input** for auto-linking an asset, despite what the field names suggest — that data doesn't exist as columns on `glpi_tickets`. Must use the dedicated `Item_Ticket` class explicitly (`itemtype`, `items_id`, `tickets_id`).
5. **Two tickets in this DB are real, human-created data** (via the actual GLPI UI during testing), not part of the synthetic set — a plugin's "purge/reseed" logic must not assume every row is synthetic. Recommend tagging synthetic records (e.g., a marker in `comment` or a dedicated custom field) for safe purge targeting.
6. **Approval workflows are single-step** — no multi-stage chained approval sequences were modeled.
7. **Reproducibility is per-phase, not global** — each script seeds `mt_srand()` independently; re-running the full pipeline with the same PHP version reproduces the same data, but phases aren't cryptographically or globally seeded together.

---

## 8. Performance notes for the plugin

- Default `innodb_buffer_pool_size` (128MB) is inadequate even for this modest volume — bump to ≥512MB before bulk generation, revert or leave elevated after (leaving it elevated is recommended for ongoing usability).
- `Ticket::add()` costs ~150–400ms each due to full rule-engine evaluation (22 active rules) — budget accordingly. The 7,500-ticket bulk phase took ~48 minutes wall-clock.
- Direct actor-link inserts (bypassing `updateActors()`) are ~10× faster (~20–40ms each) — useful both for the entity-bug retrofit and for any future "add actor" bulk operation.
- Anything >1,000 records: run as a background/async job with progress logging, not a synchronous request — a plugin console command should chunk and report progress every 500 records.
- Objects with cascading side-effects (Tickets, in particular) are far more expensive per-record than simple dropdowns/assets — plan generation-time budgets accordingly (assets ≈ 80–90ms each, tickets ≈ 150–400ms each).

---

## 9. Recommended plugin shape

- Register as a Symfony Console command via the plugin's service definitions (so it runs through `bin/console <plugin>:demodata:generate`, inheriting `--env`, `--allow-superuser`, etc.) rather than a standalone script.
- Parameterize the volume profile (small/medium/large enterprise) — don't hardcode the counts in §3; make them config.
- **Always derive `entities_id` from the chosen actor**, never hardcode a shared entity (§5).
- Disable notifications for the duration of generation, restore the prior value afterward (don't assume off/on).
- Provide a matching "purge synthetic data" command that only targets tagged/marked records.
- Keep the phase-ordered, state-file-per-phase pattern (§2.2) — makes the generator resumable and testable phase-by-phase rather than one monolithic run.
- Add a post-generation health check that runs the query in §5 (and its Problem/Change equivalents) as an automated regression test, so this exact bug class can never ship silently again.
