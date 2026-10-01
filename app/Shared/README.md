# Shared source boundary

Shared PHP source is limited to `Domain`, `Application`, and `Infrastructure`. Shared code must be genuinely cross-cutting and may not become a generic dumping ground for module behavior.

Dependency direction remains inward: Infrastructure may depend on Application and Domain; Application may depend on Domain; Domain remains framework-independent.

## Transactional outbox usage

Authorized application use cases publish `OutboxEvent` through `OutboxPublisher` inside the same `TransactionRunner` callback as the source mutation. Publishing outside a database transaction or under a mismatched property context fails closed. Event data must be versioned, minimal, free of secrets, and safe to retain; the persisted envelope is encrypted.

Module consumers implement `OutboxConsumer` and are registered in `config/outbox.php`. A consumer must perform transaction-local database work only. The processor claims a unique `(event_id, consumer)` receipt in the same transaction as the consumer, so a retry cannot repeat a committed effect. External network delivery must instead create its own integration delivery record/job and is implemented under `TASK-FND-020`.

The scheduler atomically drains pending messages into the database queue and starts a bounded `queue:work --stop-when-empty` process each minute. Reviewed dead letters can be returned to the pending queue with `php artisan outbox:retry {property_id} {event_id}`; the action is recorded as a security event.

## Private file storage usage

Modules store files only through `StoreFile` with their own `FilePolicy` and read them only through `DownloadFile` with their own `FileAccessPolicy`; never use the `public` disk or `Storage::url()` for guest, employee, or financial files. Authorize the actor before calling `StoreFile`. Retention (Q-15) is undecided: pass `expiresAt` only when the owning task has an approved period. Controllers return `StoredFileResponse::attachment()`.
