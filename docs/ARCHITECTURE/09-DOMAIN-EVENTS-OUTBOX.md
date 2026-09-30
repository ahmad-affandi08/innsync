# Domain Events and Transactional Outbox

Use events to decouple contexts, but do not use events to hide core transaction flow.

## Rules

1. Critical state mutation commits in one application transaction.
2. Domain events are collected from aggregates.
3. Events needing asynchronous processing are written to an `outbox_messages` table in the same DB transaction.
4. A scheduled short-lived worker dispatches outbox messages.
5. Consumers are idempotent and record processed message IDs where duplicate delivery would be harmful.
6. Payloads are versioned and include `event_id`, `event_type`, `aggregate_id`, `property_id`, `occurred_at`, `correlation_id`.
7. External webhook delivery never occurs inside the originating DB transaction.

Use synchronous application orchestration where the user-facing operation cannot be considered successful unless both writes succeed atomically.
