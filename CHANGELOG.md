# Changelog

Release notes for every release (NFR-14). Newest first. Each entry names the tasks and requirement IDs it delivers. The version shown in the application comes from `APP_VERSION`; a version without the `-dev` suffix must have its own section here before it can pass the quality gates.

## [Unreleased]

### Added

- TASK-FND-001 to TASK-FND-011 (ADR-0001 to ADR-0008): Laravel 13, Inertia, React and Tailwind baseline; module and layer boundaries; MySQL 8 with property scope, ULID identifiers and optimistic locking; authentication, MFA and scoped RBAC; immutable audit and security events; idempotency; transactional outbox; private file storage; error envelope; health and alerts; encrypted backup, restore test and DR runbook.
- TASK-FND-012 (NFR-01, NFR-12, NFR-27): frontend query and table conventions, and shared UI primitives.
- TASK-FND-013 (NFR-12): Indonesian and English localization.
- TASK-FND-014 (NFR-26, BR-001): business date, calendar date and property time zone primitives.
- TASK-FND-015 (NFR-14): quality gates for formatting, tests, type checking, build and release notes.
- TASK-FND-017 (NFR-04, NFR-18, NFR-19, NFR-20): offline operation envelope for POS and Housekeeping: idempotent batch synchronization, encrypted device queue, reconciliation records, `sync_backlog` health check, and a field-test page.
- TASK-FND-016 (ADR-0003, ADR-0008): shared-hosting release profile for Git deployment on Niagahoster: verified production artifact published to a generated `release` branch, host release and rollback script with backup-first and preflight-on-new-code, `deploy:preflight` and `deploy:smoke` commands, release workflow, contingency (standby) procedure, and a deployment runbook.
- TASK-FND-018 (NFR-06, BR-004): maker-checker approval engine: module contract (`ApprovalGate`), versioned per-property policies with amount bands and scope, no self-approval, distinct approvers, single-use consumption bound to the payload, immutable decision evidence with audit and security events, approver inbox in English and Indonesian, and currency-aware money display.
- TASK-FND-019 (NFR-07, NFR-08, NFR-24, NFR-29): retention catalogue with an Indonesian baseline, daily erasure of expired files with tombstones, legal holds, consent ledger, data subject request register with deadlines, sensitive export issuing and personal data access audit; backup sets kept default to 35.
- TASK-FND-020 (NFR-25, NFR-28, NFR-18): external integration conventions: single call executor with timeouts, idempotency key and circuit breaker, honest unknown outcomes with manual reconciliation and a health signal, signed idempotent webhook receiver with secret rotation, contract versioning policy and browser/device matrix.
- TASK-FO-010, TASK-FO-011, TASK-FO-014, TASK-FO-015, TASK-FO-016 (BR-009, NFR-07): guest registration and check-in into a free room, encrypted identity details with masking and audited reading, identity photo with retention starting at check-out, returning-guest recognition, document warnings, and check-out that settles folios and completes the reservation.
- TASK-FO-028 (BR-001, BR-002, BR-005): night audit that checks pending arrivals, overdue departures, folios and same-day stays (waivable only with a reason by a privileged person), posts one room charge per in-house night from the reservation's price snapshot, records an immutable report computed from the ledger, locks the closed day (database trigger) and is the only way the business date moves.
- TASK-HK-001, TASK-HK-002, TASK-HK-003, TASK-HK-004, TASK-HK-007, TASK-HK-018, TASK-FO-001 (BR-008): Housekeeping context with its own room status (dirty, cleaning, clean, ready, rework) separate from occupancy, tasks created at check-out and ordered by urgency, supervisor assignment, a phone screen for attendants with start and finish times, supervisor inspection with findings, rework and waiver by a privileged role, check-in only into ready rooms, and the front desk room board.
- TASK-HK-020 to TASK-HK-024, TASK-LDY-001 to TASK-LDY-004, TASK-LDY-011, TASK-LDY-012 (BR-002, BR-005): guest laundry with a property price list, hand-over by housekeeping for a room that has a guest, counting with a recorded difference, processing steps, a charge to the folio when ready from the counted quantities at the prices of the day of hand-over, delivery against a receipt, express and overdue ordering, and a stay that cannot be closed while laundry is active; service charge and tax can now be configured per scope (rooms, laundry).

### Known limitations

- Backup point-in-time recovery (RPO of 15 minutes, NFR-11) is not met and needs an owner decision (TASK-FND-011).
- The business date rollover rule (PRD Q-11) follows the Indonesian baseline in `docs/OPERATIONS/INDONESIA-COMPLIANCE-BASELINE.md` (earliest local start, default 23:00, no latest time) and needs the General Manager's confirmation; night audit (TASK-FO-028) only checks Front Office items, and outlet, laundry and approval items join the checks when those contexts exist.
- Offline operation (TASK-FND-017) has no concrete POS or Housekeeping operations yet, and real iOS/Android devices have not been tested (PRD Q-14 is open).
- PHP static analysis (Larastan) is approved but not installed yet; it could not be downloaded in the build sandbox.
- Retention periods and data subject deadlines are an Indonesian baseline from the owner's instruction and need confirmation by counsel (docs/OPERATIONS/INDONESIA-COMPLIANCE-BASELINE.md); only Front Office guest registration stores personal data so far (encrypted identity fields, photo retention from check-out).
- No external provider is configured or chosen yet (PRD Q-04, Q-07, Q-08, Q-16); the integration machinery has only been exercised with test providers, and Safari, Firefox and real mobile devices have not been tested.
- No business module uses the approval engine yet (TASK-FND-018); mandatory actions, thresholds and approver chains per property are still to be configured by owners, and rounding rules are PRD Q-13.
- Production release is blocked on the open hosting items D4, D5 and D6 in `docs/OPERATIONS/DEPLOYMENT-RUNBOOK.md`; nothing has been run against the real Niagahoster plan.
