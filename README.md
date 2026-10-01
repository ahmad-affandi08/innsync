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

Foundation tasks `TASK-FND-001` to `TASK-FND-019` are implemented; see `docs/TASK/PHASE-0-FOUNDATION.md` for status and acceptance evidence per task. Six are in review: `TASK-FND-011` awaits an owner decision on RPO, `TASK-FND-017` awaits real-device testing and the concrete POS and Housekeeping operations, `TASK-FND-015` awaits PHP static analysis (Larastan is approved but not yet installed), `TASK-FND-016` awaits the hosting decisions listed in the deployment runbook, `TASK-FND-018` awaits a first module workflow and the owners' approval policies, and `TASK-FND-019` awaits counsel's confirmation of the Indonesian retention baseline. Business modules have not started. Release notes are in [CHANGELOG.md](CHANGELOG.md).

## Local setup

Requirements: PHP 8.3+, Composer 2, Node.js with npm, and MySQL 8.

```bash
composer run setup
cp .env.example .env # only when setup did not create it
```

Configure the MySQL credentials in `.env`, create an empty database, then run `php artisan migrate`. The test suite uses the isolated `innsync_test` database and refuses to reset any other database.

Useful verification commands:

```bash
composer quality   # composer validate, Pint, all PHP test suites, route/config cache
npm run quality    # strict TypeScript, frontend logic tests, production build
```

These two commands are the quality gates that CI runs on every pull request and push to `main` (`.github/workflows/ci.yml`); a change cannot merge when either fails. They need a reachable MySQL 8 server with the `innsync_test` database (and `innsync_restore_test` for the backup tests). Individual pieces: `composer test`, `composer test:architecture`, `composer test:integration`, `composer lint`, `npm run typecheck`, `npm test`, `npm run build`.

## Releasing

`scripts/release/build.sh` builds a verified production artifact (Composer production dependencies and prebuilt assets, no secrets), `scripts/release/verify.sh` checks it, and on a `v*` tag CI publishes it to the generated `release` branch. The shared host is a Git clone of that branch and updates with `deploy/host-release.sh` (backup first, preflight on the new code, forward-only migrations, smoke test, rollback of code). `php artisan deploy:preflight` and `php artisan deploy:smoke <url>` gate a release. The procedure, open hosting decisions, and rollback are in [docs/OPERATIONS/DEPLOYMENT-RUNBOOK.md](docs/OPERATIONS/DEPLOYMENT-RUNBOOK.md).

## Documentation domains

- `docs/PRD`: canonical product requirements and requirement catalogs.
- `docs/TASK`: executable backlog, release phases, per-module work, UAT/migration/deployment tasks.
- `docs/ARCHITECTURE`: DDD boundaries, Clean Architecture, persistence, integration, hosting, security, test strategy, and ADRs.
- `docs/RULES`: hard engineering and product rules that code may not violate.
- `docs/DESIGN`: UI/UX and frontend interaction contract.
- `docs/SKILL`: repeatable agent operating procedures by specialty.

Changes to business rules or architecture happen through documented change control. Protected PRD, RULES, and accepted ADR documents must not be edited without an approved Change Request.
