# PHP / Laravel Coding Standards

- PHP strict typing in project-owned domain/application code.
- PSR-12/Laravel Pint formatting.
- Descriptive domain names; avoid generic `Helper`, `Manager`, `CommonService`, `Utils` classes.
- Constructor injection over service locator calls.
- `env()` only inside configuration files.
- Controllers are thin adapters: authorize, validate/adapt input, invoke use case, return response.
- Form Requests validate transport shape; domain validates business invariants.
- Eloquent is Infrastructure, not Domain.
- Do not return Eloquent models from domain repository interfaces.
- Prefer immutable DTOs/value objects for application boundaries.
- Exceptions have semantic meaning; do not swallow them or use exceptions for ordinary branching.
- Any non-trivial query must be reviewed for indexes and N+1 behavior.
