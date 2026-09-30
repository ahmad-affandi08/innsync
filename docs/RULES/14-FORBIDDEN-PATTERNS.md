# Forbidden Patterns

- Fat controllers / God services / God models.
- Repository returning unbounded Eloquent builders to Application/Domain.
- Cross-module `Model::query()->update()` against another context.
- `float` money.
- Hard delete of posted transaction/audit records.
- Magic status strings scattered across UI/backend.
- `request()->all()` mass assignment into transactional models.
- Hidden business behavior in Eloquent observers/listeners with no explicit use-case trace.
- External HTTP call inside a transaction that holds critical locks.
- Last-write-wins for monetary/stock/availability conflicts.
- Unbounded retry loops.
- PII in browser localStorage unless explicitly designed/encrypted under the offline contract; identity documents are forbidden there.
- WebSocket/Octane/Horizon requirement on the initial shared-hosting profile.
- N+1 list endpoints and browser-side loading of all rows just to paginate.
- UI-only permissions.
- "Temporary" TODO that disables authorization/audit/idempotency in production paths.
