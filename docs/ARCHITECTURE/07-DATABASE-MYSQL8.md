# MySQL 8 Persistence Contract

## Baseline

- InnoDB only.
- `utf8mb4` character set; one approved collation for the project.
- Every property-owned table carries `property_id` and relevant composite indexes.
- Use ULID identifiers (`CHAR(26)`) for domain aggregates to support sortable IDs and offline/client-safe creation where necessary.
- Monetary amounts are stored as integer minor units (`BIGINT`) plus ISO currency code. Exchange rates/percentages use DECIMAL, never FLOAT/DOUBLE.
- Store timestamps in UTC. Store `business_date` explicitly where operational closing/reporting depends on it.
- Foreign keys are required for local relational integrity unless a documented high-volume exception exists.

## Transactional facts

Financial and stock history is append/correct, not destructive edit. Transaction tables do not use normal hard delete flows. Master/reference data may be archived/disabled; soft deletes are not a substitute for transaction audit.

## Concurrency

- Unique constraints back idempotency keys.
- Use `lock_version` optimistic concurrency for user-edited aggregates where collisions are expected.
- Use `SELECT ... FOR UPDATE` / transaction locking for critical posting windows such as folio close, payment settlement, stock posting, night audit, and sequential allocation when optimistic control is insufficient.
- Catch deadlocks, retry a bounded number of times, and preserve idempotency.

## Schema ownership

Tables are owned by one bounded context. Other contexts may read through contracts/projections but must not mutate them directly.
