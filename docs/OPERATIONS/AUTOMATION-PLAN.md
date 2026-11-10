# Automation and menu plan (Tahap 0 output, 2026-11-10)

Source: the module scan of 2026-11-10 (menus too many, too much manual input). This note is the traceability map and the list of decisions that must be taken **before** each phase is built. It changes no behavior. It is an implementation note, so it ranks below the PRD, RULES and ADRs and cannot override them.

Rules every item follows (`AGENTS.md`, `docs/RULES/13-AI-AGENT-GUARDRAILS.md`): cron or outbox only (ADR-0003, ADR-0008); no new package; automation only **creates drafts or new facts**, never edits posted history (BR-003); maker-checker stays (BR-004); every job idempotent (BR-005) and property-scoped; a task that touches money, payroll or permissions gets a second-pass security/invariant review before it is called DONE (`15-DEFINITION-OF-DONE.md`). Each commit names its FR / NFR / BR / TASK / ADR ids.

## What already exists (do not rebuild)

- **System actor.** `App\Shared\Application\Security\SystemActors` with `automation()`, implemented in `IdentityAccess/Infrastructure/Authorization/DatabaseSystemActors.php`. It is an inactive account nobody can sign in to, so a record names "Automation" and every permission check still runs. `RestockDraftService` already uses it. Decided (owner, 2026-11-10): keep the one shared "Automation" account. Because permissions are only ever **added** to it (`ensure()`), every job asks for the narrowest set it needs, and **no job may ask for an `approve`, `verify` or `pay` permission**. A test per job asserts this.
- **Scheduler.** `routes/console.php` runs from one cron (ADR-0003). Finance and HR have no scheduled command yet.
- **Outbox consumers.** `config/outbox.php` lists 22 consumers; new consumers register there.

## Gate for each proposal

`None` = may be built from a TASK once its FR/TASK ids are read. `CR-n` = needs the approved Change Request first. `D-n` = needs the answer to that decision (hotel policy is never guessed, `00-NON-NEGOTIABLES.md` rule 11). `BLOCKED` = depends on an open PRD question.

| Phase | Proposal | FR / BR / ADR (verified in the PRD) | Gate |
| --- | --- | --- | --- |
| 1 | ~~Receipt lines prefilled~~ → **done as one explicit button** "accept the remaining quantity on every line" (a line already had its own button). A default would let stock be posted without counting, so nothing is prefilled | purchasing receiving (read the FR from `TRACEABILITY-MATRIX.md`) | Done 2026-11-10 |
| 1 | Check-in: look up the returning guest when the ID number field is left (done 2026-11-10). Applying the stored profile stays an explicit click, because a mistyped ID would otherwise overwrite the name and address silently. Prefill from the self check-in submission: **not needed**. A submitted self check-in is verified in `/guest/checkins/{id}`, and that action itself checks the guest in (`SelfCheckInDeskService::checkIn`); the manual form is only used when there is no submission | FR-FO-015 | Done |
| 1 | Cashier opening float from the last closed shift (done 2026-11-10). **Dropped:** deposited cash prefilled with the system amount. FR-FIN-003 compares the *counted* cash with the system cash; a prefilled count is a rubber stamp | FR-FO-036, FR-FIN-003 | Done / dropped |
| 1 | Laundry promised time from the standard turnaround; room from QR | FR-HK-020 to FR-HK-022 | Done 2026-11-10: new table `laundry_settings` (usual 24 h and express 6 h unless the property sets others), set on the price list page with a reason and audited; the intake screen suggests the promised date and time and follows the express switch until the attendant types a time. Room from QR: not done |
| 1 | Payable expense account: the supplier's last account is **suggested** on the next payable (done 2026-11-10); it is saved only when the person presses Save | payables (read the FR from `TRACEABILITY-MATRIX.md`) | Done |
| 1 | Lost & found: link the item to the guest of the room's current or last stay | FR-HK-012, FR-HK-008, BR-009 | Done 2026-11-10: `lost_found_items.stay_id` (migration 153, no foreign key into Front Office), the stay found through the new port `Housekeeping\RoomStayReader` (in-house stay of the room, else its latest). Only the stay id is stored; the guest's name is shown only to holders of `housekeeping.lostfound.manage` (BR-009). It is a suggestion to check with the guest, and a guest who moved to another room earlier is not found. The damage report needs no stay |
| 2 | Filter sidebar links by the page's own permission; hide menus of features switched off | NFR-06 (server checks stay) | Done 2026-11-10 for 104 links, from the permission catalog; loyalty hidden while `LOYALTY_ENABLED` is off. Door locks stay listed (their page holds the switch) |
| 2 | Remove links duplicated across modules | `DESIGN/03-LAYOUT-NAVIGATION.md` | Done 2026-11-10: three duplicates removed. The department-filtered links to purchasing, requisitions and counts stay (they open a different, filtered view). Laundry hand-over stays in housekeeping (FR-HK-020) |
| 2 | Merge pages of one module into tabs; fewer top-level entries | `DESIGN/03-LAYOUT-NAVIGATION.md` | Replaced by group headings over the same pages (done 2026-11-10 for front office, inventory, HR, finance, property), so D-1 is not needed. Real tabs and fewer top-level entries are still open |
| 3 | Monthly payroll draft and service-charge draft | FR-HR-031, FR-HR-032, FR-HR-033, FR-HR-037 | CR-0001 |
| 3 | Monthly depreciation | no FR exists | CR-0001 |
| 3 | Stay-over housekeeping tasks each day | FR-HK-003 (priority order only), FR-HK-017 (DND) | D-2 |
| 3 | Scheduled stock counts | FR-INV-006 ("terjadwal"), FR-INV-012 | None (frequency is a setting) |
| 3 | Auto reminders: deposit past due date, tomorrow's arrival without a room | existing `frontdesk:auto-reminders` | None |
| 3 | Night-audit pre-check notice | FR-FO-028, BR-001 | BLOCKED (Q-11) |
| 4 | Out-of-range temperature opens a work order | FR-KIT-008, FR-MTC-001 | D-3 |
| 4 | Menu item marked sold-out automatically when an ingredient reaches zero | FR-KIT-005 (**Wajib, says "otomatis"**) | None. Treat as a gap in TASK-KIT-005, not a new feature. Verify what the code does first |
| 4 | Approved request becomes a draft PO | FR-PUR-003, FR-PUR-011 | D-4 |
| 4 | Supplier quote feeds the PO price | FR-PUR-005 (Sebaiknya) | D-4 |
| 4 | Expected linen/amenity usage posted from finished room tasks | FR-HK-009, FR-HK-019 | D-5 |
| 4 | Expired lots suggested as waste (a person confirms) | FR-KIT-009, FR-INV-008 | None |
| 5 | Bank lines auto-matched when exactly one candidate | FR-FIN-004 (Sebaiknya), BR-004 | CR-0001 |
| 5 | "Verify all clean days" as one person's action | FR-FIN-005, BR-004 | CR-0001 |
| 5 | Card/QRIS settlement created from a bank credit | FR-FIN-004, Q-04, Q-16, Q-18 | BLOCKED (provider and bank formats undecided; `MODULE-10-FINANCE.md` says the same). Only a prefill from `pending()` may go ahead |
| 6 | Starter checklists, duty plans, temperature points per profile | FR-HK-005, FR-MTC-011, FR-KIT-008 | D-6 |
| 6 | CSV import for assets and temperature points; rates and tax in setup | FR-FIN-034 area; Q-01, Q-05 | None |
| 6 | Account created from an HR employee record | NFR-06, BR-009 | CR-0002 |
| 6 | Role-aware "Today" page | FR-DSH-001 to FR-DSH-022 | Done 2026-11-10: 11 `TodayTasks` classes (front office, web bookings, housekeeping, laundry, kitchen, F&B, maintenance, inventory, finance, HR, approvals), see `DESIGN/03-LAYOUT-NAVIGATION.md`. Counts are not yet checked against real data |
| — | Not now: auto-assign a technician; auto take a room off sale | FR-MTC-003, FR-MTC-006, BR-008 | Not proposed. Both change who does the work or whether a room can be sold, which is a hotel policy |

## Decisions

Owner instruction of 2026-11-10: D-8 is closed (one shared Automation account, narrow permissions, no approve/verify/pay) and the rest follow common Indonesian hotel practice. The defaults below are **starting values, each a property setting a manager can change**; they are not a PRD change. They stay open for the named manager to confirm at UAT.

| Id | Default taken |
| --- | --- |
| D-1 | Tabs may group closely related list pages of one module, each tab keeping its own URL. `DESIGN/03-LAYOUT-NAVIGATION.md` is to be amended in the Tahap 2 commit |
| D-2 | Stay-over rooms are made up every morning unless DND or refused service is flagged; linen is changed every 3rd night, towels on request or when left on the floor |
| D-3 | An out-of-range reading notifies the supervisor and opens one maintenance work order per excursion, not per reading |
| D-4 | Supplier: preferred supplier, then the lowest valid quote, then the price list; one PO per supplier. The result is always a draft |
| D-5 | The expected usage (par x rooms cleaned) fills the usage form; the attendant confirms or edits it. Nothing is posted without that confirmation |
| D-6 | Starter checklists and duty plans in Bahasa Indonesia, marked as examples, editable, approved by the department manager |
| D-7 | Still open (PRD Q-11). Blocks night-audit automation |
| D-8 | Closed, see above |

The original questions follow for the record.

- **D-1 Menu tabs.** `DESIGN/03-LAYOUT-NAVIGATION.md` says tabs split lists and reports and "are not for the pages of a module, which stay in the sidebar or the rail". The Tahap 2 merge (for example Finance 27 links to about 9) contradicts that sentence. DESIGN is not a protected path, but the owner should confirm the new rule: *tabs may group closely related list pages of one module, each tab keeping its own URL.* Without it, Tahap 2 is limited to removing duplicates and permission filtering.
- **D-2 Stay-over service.** Which nights get a service task, whether DND and refused-service skip it, and the linen-change cadence. Ask the Housekeeping Manager.
- **D-3 Temperature alert.** Open a work order, only notify, or both? Notify whom? Once per excursion or on every reading? Ask Engineering and the Executive Chef.
- **D-4 Draft PO from an approved request.** When an item has several suppliers: preferred, cheapest valid quote, or last used? One PO per supplier? Does a valid quote override the price list? Ask Purchasing.
- **D-5 Linen and amenity usage.** Post the expected quantity (par x rooms cleaned) as the usage, or only suggest it for the attendant to confirm? The first makes the variance report meaningful, the second keeps a person in the loop.
- **D-6 Starter templates.** Wording of example checklists is hotel policy. Ship them as clearly marked editable examples, and have a manager approve the list.
- **D-7 Q-11 (open in the PRD).** Business-date cut-off and whether night audit may run before calendar midnight. Blocks any night-audit automation.
- **D-8 System actor.** Keep one shared "Automation" account, or one per job family (purchasing, HR, finance)? Recommended: per family, with no `approve`, `verify` or `pay` permission on any of them.

## Order of work

0. This note, CR-0001 and CR-0002 (drafts, decision `Pending`).
1. Prefill and defaults (no gate).
2. Menu (permission filter and duplicates now; tabs after D-1).
3. Scheduled jobs (payroll after CR-0001; stay-over after D-2).
4. Outbox consumers (temperature after D-3; PO after D-4).
5. Finance matching (after CR-0001).
6. Starter data, import, accounts from HR, "Today" page.

Before the first line of any phase: read the exact `TASK-*` row in `docs/TASK/MASTER-BACKLOG.md` and its module file, and write the guardrail list (requirement ids, context and aggregate owner, layers, scope/security, transaction and idempotency, test plan, open questions).

## Starter data (done 2026-11-10, first part)

`innsync:install-starter-data` writes, for a property that has none, the 25 usual expense accounts (`ExpenseAccountService::BASELINE`), and the shifts, kinds of leave, kinds of pay and appraisal form that HR already offered as buttons. Modules offer their parts through `Shared\Application\Setup\StarterData` (tag `starter-data`); the actor is the property's administrator; a department the property does not use is skipped; a part with anything in it is left alone. `innsync:create-admin` runs it. Check-in 14:00, check-out 12:00 and night audit from 23:00 already come from `PropertySettings::defaults()`, and `create-admin` already sets the business date, so those steps need no data. Not done yet: checklist templates and temperature points (D-6), the tax and BAR rate proposals (Q-05), adding rooms by range.
