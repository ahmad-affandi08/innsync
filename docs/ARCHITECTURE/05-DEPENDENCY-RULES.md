# Dependency Rules

## Allowed

- Presentation -> Application DTO/Command/Query
- Application -> Domain
- Infrastructure -> Domain interfaces and Application ports
- Infrastructure Eloquent models -> infrastructure mappers
- Reporting query services -> read-only DB/query projections

## Forbidden

- Domain -> `Illuminate\*`, Eloquent, HTTP Request, Cache, Queue, Storage, Inertia
- Controller -> direct business calculation or multi-repository workflow
- React -> direct business invariant or authoritative monetary/tax calculation
- Context A -> Context B Eloquent model write
- Repository interface -> framework-specific return type
- Domain event -> anonymous associative array without a versioned payload contract

Architecture tests should enforce the most important namespace dependency rules.
