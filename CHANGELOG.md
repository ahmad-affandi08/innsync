# Changelog

Release notes for every release (NFR-14). Newest first. Each entry names the tasks and requirement IDs it delivers. The version shown in the application comes from `APP_VERSION`; a version without the `-dev` suffix must have its own section here before it can pass the quality gates.

## [Unreleased]

### Added

- TASK-FND-001 to TASK-FND-011 (ADR-0001 to ADR-0008): Laravel 13, Inertia, React and Tailwind baseline; module and layer boundaries; MySQL 8 with property scope, ULID identifiers and optimistic locking; authentication, MFA and scoped RBAC; immutable audit and security events; idempotency; transactional outbox; private file storage; error envelope; health and alerts; encrypted backup, restore test and DR runbook.
- TASK-FND-012 (NFR-01, NFR-12, NFR-27): frontend query and table conventions, and shared UI primitives.
- TASK-FND-013 (NFR-12): Indonesian and English localization.
- TASK-FND-014 (NFR-26, BR-001): business date, calendar date and property time zone primitives.
- TASK-FND-015 (NFR-14): quality gates for formatting, tests, type checking, build and release notes.
- TASK-FND-016 (ADR-0003, ADR-0008): shared-hosting release profile for Git deployment on Niagahoster: verified production artifact published to a generated `release` branch, host release and rollback script with backup-first and preflight-on-new-code, `deploy:preflight` and `deploy:smoke` commands, release workflow, contingency (standby) procedure, and a deployment runbook.

### Known limitations

- Backup point-in-time recovery (RPO of 15 minutes, NFR-11) is not met and needs an owner decision (TASK-FND-011).
- The business date rollover rule is undecided (PRD Q-11); night audit (TASK-FO-028) is not started.
- PHP static analysis (Larastan) is approved but not installed yet; it could not be downloaded in the build sandbox.
- Production release is blocked on the open hosting items D4, D5, D6, D8 and D9 in `docs/OPERATIONS/DEPLOYMENT-RUNBOOK.md`; nothing has been run against the real Niagahoster plan.
