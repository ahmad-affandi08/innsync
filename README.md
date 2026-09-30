# InnSYnc AI Agent Blueprint

This package turns the InnSYnc PRD into a development constitution for a Laravel 13 Domain-Driven Design modular monolith.

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

## How to install in the repository

Place `AGENTS.md`, `CLAUDE.md`, and the whole `docs/` directory at the repository root. An AI agent must begin at `AGENTS.md`.

## Documentation domains

- `docs/PRD`: canonical product requirements and requirement catalogs.
- `docs/TASK`: executable backlog, release phases, per-module work, UAT/migration/deployment tasks.
- `docs/ARCHITECTURE`: DDD boundaries, Clean Architecture, persistence, integration, hosting, security, test strategy, and ADRs.
- `docs/RULES`: hard engineering and product rules that code may not violate.
- `docs/DESIGN`: UI/UX and frontend interaction contract.
- `docs/SKILL`: repeatable agent operating procedures by specialty.

The package intentionally favors explicit constraints over cleverness. Changes to business rules or architecture happen through documented change control, not opportunistic refactoring.
