# Architecture — InnSYnc

## Architectural style

InnSYnc is a **DDD modular monolith** implemented in Laravel 13. It uses Clean Architecture dependency direction inside each bounded context. This is deliberate: the PRD requires strong cross-module consistency while the target environment is shared web hosting where independently deployed services and long-running workers add operational risk.

## Core decisions

- Single deployable Laravel application; strongly separated bounded contexts.
- MySQL 8 shared schema with explicit `property_id` scope on property-owned data.
- Domain layer is framework-independent PHP; no Eloquent, HTTP, Inertia, queue, cache, or filesystem dependencies inside Domain.
- Application layer owns use-case orchestration and transaction boundaries.
- Infrastructure implements repository/integration contracts.
- Presentation layer adapts HTTP/Inertia/CLI/cron to application use cases.
- Cross-context writes happen through an owning use case or explicit domain/application event contract; never by reaching into another module's Eloquent model.
- Critical financial/stock operations are atomic and idempotent.
- Shared-hosting profile forbids architecture that requires permanent daemons.

Read the ADR index before introducing a structural change.
