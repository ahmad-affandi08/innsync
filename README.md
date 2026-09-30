# InnSYnc

InnSYnc is a hotel operating system implemented as a Laravel 13 DDD modular monolith. The repository is governed by [AGENTS.md](AGENTS.md) and the executable product, architecture, design, task, and engineering contracts under `docs/`.

## Target stack

- Laravel 13 / PHP 8.3+
- MySQL 8 / InnoDB
- Inertia.js
- React + TypeScript (strict)
- Tailwind CSS 4
- shadcn/ui
- TanStack Query
- TanStack Table
- Deployment target: Niagahoster/Hostinger-style shared web hosting unless an ADR explicitly changes the infrastructure profile.

## Current implementation status

- `TASK-FND-001`: DONE — approved application stack bootstrapped.
- `TASK-FND-002`: DONE — module/layer namespaces and architecture boundaries enforced.
- `TASK-FND-003`: DONE — MySQL 8, property scope, ULID, and optimistic-locking baseline implemented.
- `TASK-FND-004`: DONE — authentication, MFA, session security, scoped RBAC, and server-side authorization implemented.
- Next task: `TASK-FND-005` — audit trail, security log, and correlation IDs.
- Business modules have not started.

## Local setup

Requirements: PHP 8.3+, Composer 2, Node.js with npm, and MySQL 8.

```bash
composer run setup
cp .env.example .env # only when setup did not create it
```

Configure the MySQL credentials in `.env`, create an empty database, then run `php artisan migrate`. The test suite uses the isolated `innsync_test` database and refuses to reset any other database.

Useful verification commands:

```bash
composer test
composer test:architecture
composer test:integration
vendor/bin/pint --test
npm run typecheck
npm run build
```

## Documentation domains

- `docs/PRD`: canonical product requirements and requirement catalogs.
- `docs/TASK`: executable backlog, release phases, per-module work, UAT/migration/deployment tasks.
- `docs/ARCHITECTURE`: DDD boundaries, Clean Architecture, persistence, integration, hosting, security, test strategy, and ADRs.
- `docs/RULES`: hard engineering and product rules that code may not violate.
- `docs/DESIGN`: UI/UX and frontend interaction contract.
- `docs/SKILL`: repeatable agent operating procedures by specialty.

Changes to business rules or architecture happen through documented change control. Protected PRD, RULES, and accepted ADR documents must not be edited without an approved Change Request.
