# Changelog

Release notes for every release (NFR-14). Newest first. Each entry names the tasks and requirement IDs it delivers. The version shown in the application comes from `APP_VERSION`; a version without the `-dev` suffix must have its own section here before it can pass the quality gates.

## [Unreleased]

### Added

- TASK-FND-001 to TASK-FND-011 (ADR-0001 to ADR-0008): Laravel 13, Inertia, React and Tailwind baseline; module and layer boundaries; MySQL 8 with property scope, ULID identifiers and optimistic locking; authentication, MFA and scoped RBAC; immutable audit and security events; idempotency; transactional outbox; private file storage; error envelope; health and alerts; encrypted backup, restore test and DR runbook.
- TASK-FND-012 (NFR-01, NFR-12, NFR-27): frontend query and table conventions, and shared UI primitives.
- TASK-FND-013 (NFR-12): Indonesian and English localization.
- TASK-FND-014 (NFR-26, BR-001): business date, calendar date and property time zone primitives.
- TASK-FND-015 (NFR-14): quality gates for formatting, tests, type checking, build and release notes.

### Known limitations

- Backup point-in-time recovery (RPO of 15 minutes, NFR-11) is not met and needs an owner decision (TASK-FND-011).
- The business date rollover rule is undecided (PRD Q-11); night audit (TASK-FO-028) is not started.
- No PHP static analysis tool is installed yet; adding one requires an approved dependency.
