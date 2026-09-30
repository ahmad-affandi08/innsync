# Offline, Idempotency, and Concurrency

Offline capability is limited to PRD-approved critical flows: POS and Housekeeping.

## Offline envelope

Each client mutation carries a client-generated operation ID/idempotency key, actor/session reference, property scope, device time, server-known version, and payload version. Sync results return accepted/conflict/rejected status and server canonical version.

## Safety

- Never silently drop failed sync items.
- Never mark `Unknown` payment as `Paid`.
- Do not cache guest identity documents or unnecessary PII for offline use.
- Duplicate retries must resolve to the same logical result.
- Conflicts that can change money, stock, room availability, or approval state require deterministic server-side resolution or user intervention; no last-write-wins.
- Client queue entries have visible state and bounded retry/backoff.
