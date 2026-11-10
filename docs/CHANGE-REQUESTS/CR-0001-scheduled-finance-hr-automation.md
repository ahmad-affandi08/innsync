# CR-0001 — Scheduled and auto-matched work in Finance and HR

- CR ID: CR-0001
- Requested by: Ahmad Affandi (product owner), from the 2026-11-10 module scan
- Date: 2026-11-10
- Problem / reason: Finance and HR have no scheduled command at all (`routes/console.php` schedules nothing for them). Month-end work that is fully determined by data already in the books — a payroll draft, a service-charge draft, depreciation, matching a bank line to the single record of the same amount — is done by hand every month or every day. The product replaces Excel; today it still asks people to click through work the system can prepare.
- Current PRD/RULE/ADR affected:
  - `docs/OPERATIONS/BANK-RECONCILIATION.md`: "a person confirms, nothing is matched by itself". This is an implementation note (changeable by CR), not a PRD text.
  - `docs/OPERATIONS/FIXED-ASSETS.md`: "Running it is a monthly closing step for Finance". Fixed assets and depreciation have **no FR in the PRD** (the code and the note were added on 2026-10-10 without one). This CR asks the owner to bring them into scope or leave them out.
  - PRD BR-004 (maker-checker, no self-approval) and FR-FIN-005 (verify and lock the previous day): kept as they are, see "Proposed behavior" 4.
  - ADR-0003 / ADR-0008 (no permanent daemon): not affected, the work runs from the existing one-minute cron.
- Proposed behavior:
  1. **Payroll draft (FR-HR-031, FR-HR-037).** A monthly command creates the payroll run of the previous month in `draft` and runs the existing calculation, as the "Automation" system actor. It never reviews, approves or pays. A month that already has a run is skipped.
  2. **Service-charge draft (FR-HR-032, FR-HR-033).** The same command creates and calculates the previous month's distribution simulation. Approval by the General Manager stays a person's act.
  3. **Depreciation (needs the owner's decision on scope).** A monthly command runs the existing "depreciate through the last closed month" step as the system actor. The step is already idempotent (`Depreciation::due`) and writes new entries only.
  4. **Daily verification (FR-FIN-005).** The system only lists the days with no open blocker and offers "verify all clean days" as one action **of a signed-in person**, who must hold the verify permission. The system never verifies by itself.
  5. **Bank auto-match (FR-FIN-004).** On import, a line is matched automatically only when exactly one record has the same amount and direction within the existing 7-day window, and that record is not tied to another line. The match is marked `auto` with the system actor. The existing reopen (with a reason) is the undo. A "confirm all auto-matches" action lets a person review the batch. Lines with zero or several candidates stay open.
- FR/NFR impacted: FR-HR-031, FR-HR-032, FR-HR-033, FR-HR-037, FR-FIN-004, FR-FIN-005, BR-003, BR-004, BR-005, NFR (audit trail, idempotency). Tasks: TASK-HR-031, TASK-HR-037, TASK-FIN-004, TASK-FIN-005 (all `REVIEW`).
- Data migration / historical impact: none to existing rows. A new `matched_by` marker on bank matches is the only schema change expected (a new migration, not an edit of a deployed one).
- Security/privacy impact: the "Automation" account is shared by every scheduled job, and `SystemActors::ensure` only adds permissions, never removes them (`DatabaseSystemActors.php`). Payroll and finance jobs must therefore **never** ask for an `*.approve`, `*.verify` or `*.pay` permission, and each job asks for the narrowest set it needs. Open point D-8 in `docs/OPERATIONS/AUTOMATION-PLAN.md`: one account per job family (finance, HR, purchasing) instead of one shared account.
- Finance/stock/payment impact: payroll and service charge only create drafts. Depreciation posts new journal entries (never edits). Bank matching is a check and does not change the books (`BANK-RECONCILIATION.md`). No payment is made by the system.
- Hosting/infrastructure impact: new entries in `routes/console.php`, run by the existing cron. No daemon, no package.
- Backward compatibility: a per-property setting switches each job off; with all switches off behavior is as today. Manual buttons stay.
- Test/UAT plan: run each command twice and show nothing is created twice; a run for property A creates nothing in property B; the record names "Automation" and the audit trail shows it; the Automation account cannot approve what it drafted; a month with an existing run is skipped; bank lines with two candidates stay open; reopen of an auto-match works and is audited. UAT: Finance and HR review one month of drafts against their Excel.
- Rollback plan: switch the job off in the property setting; drafts and auto-matches are reversible with the existing reopen / correction paths; no historical fact is edited.
- Target phase/release: after Tahap 1 and 2 of the plan (`docs/OPERATIONS/AUTOMATION-PLAN.md`).
- Approvers: Owner / GM (scope and approval), Finance manager (items 3 to 5), HR manager (items 1 and 2).
- Decision: Pending
