# Phase 0 — Engineering Foundation

These tasks exist before business modules can safely scale.

| Task | Scope | References | Status |
| --- | --- | --- | --- |
| TASK-FND-001 | Bootstrap Laravel 13 + Inertia + React TS + Tailwind 4 + shadcn | ADR-0001 | DONE |
| TASK-FND-002 | Enforce module/layer namespaces and architecture tests | NFR-14 | DONE |
| TASK-FND-003 | MySQL 8 baseline, property scope, ULID, migrations | NFR-16, NFR-19, ADR-0002/0005 | DONE |
| TASK-FND-004 | Auth, session security, RBAC and scoped policies | NFR-05/06/22 | DONE |
| TASK-FND-005 | Audit trail + security log + correlation IDs | NFR-10/20/29 | DONE |
| TASK-FND-006 | Idempotency middleware/application service + table | NFR-18 | DONE |
| TASK-FND-007 | Transactional outbox + database queue + cron drain | NFR-17/25, ADR-0007 | DONE |
| TASK-FND-008 | Private file storage and authorized download | NFR-07/08/24 | DONE |
| TASK-FND-009 | Error envelope, validation, conflict semantics | NFR-19 | DONE |
| TASK-FND-010 | Observability, health endpoint, critical alerts | NFR-20 | DONE |
| TASK-FND-011 | Backup/restore and DR runbook | NFR-11/21/30 | REVIEW |
| TASK-FND-012 | Frontend query/table conventions and shared UI primitives | NFR-01/12/27 | REVIEW |
| TASK-FND-013 | i18n framework ID/EN | NFR-12 | TODO |
| TASK-FND-014 | business date/timezone primitives | NFR-26 | TODO |
| TASK-FND-015 | CI quality gates: Pint, static analysis, test, TS typecheck, build | NFR-14 | TODO |
| TASK-FND-016 | deployment pipeline/profile for shared hosting | ADR-0003/0008 | TODO |
| TASK-FND-017 | offline operation envelope for POS/HK | NFR-04/18/19 | TODO |
| TASK-FND-018 | approval/maker-checker engine | NFR-06 | TODO |
| TASK-FND-019 | retention/privacy/export policy mechanisms | NFR-07/08/24/29 | TODO |
| TASK-FND-020 | external integration adapter conventions | NFR-25/28 | TODO |

## TASK-FND-001 acceptance evidence

- Completed: 2026-09-30.
- Traceability: `TASK-FND-001`, `ADR-0001`; no business `FR-*` or `BR-*` behavior is implemented by this bootstrap task.
- Runtime: Laravel 13 / PHP 8.3 baseline with Inertia middleware and an Inertia-rendered smoke page.
- Frontend: React + strict TypeScript, Tailwind CSS 4 semantic tokens, a locally owned shadcn/ui-compatible button primitive, TanStack Query provider, and TanStack Table dependency.
- Deployment constraint: client-side Inertia rendering is the default; SSR is disabled so the shared-hosting profile does not require a permanent Node process (`ADR-0003`, `ADR-0008`).
- Security/scope: no authentication, property-owned data, PII, financial behavior, mutation endpoint, or external integration was introduced.
- Database: MySQL is the application default; schema/property scope/ULID work remains explicitly owned by `TASK-FND-003`.
- Automated evidence: `composer validate --strict`, `vendor/bin/pint --test`, `php artisan test`, `npm run typecheck`, and `npm run build` pass.
- Rollback: remove the scaffold/runtime files and restore the documentation-only repository; no database or external state was changed.

## TASK-FND-002 acceptance evidence

- Completed: 2026-10-01.
- Traceability: `TASK-FND-002`, `NFR-14`, `ADR-0001`; no business `FR-*` or `BR-*` behavior is changed.
- Boundaries: approved bounded-context names and `Domain`, `Application`, `Infrastructure`, and `Presentation` module layers are enforced against filesystem namespaces.
- Dependency direction: Domain and Application are framework-independent; outward-layer dependencies, invalid Shared dependencies, and cross-context Infrastructure access are rejected.
- Structure: the stock Laravel user persistence record now resides in `IdentityAccess/Infrastructure`; the generic root `app/Models` location is no longer used.
- Security/scope: authentication behavior, permissions, property scope, PII, and session policy remain owned by `TASK-FND-004`; this task changes placement only.
- Transaction/idempotency/concurrency: no mutation workflow or transaction boundary was introduced.
- Database/migration: no schema or migration changed; ULID and property-scope migrations remain owned by `TASK-FND-003`.
- Automated evidence: the Architecture suite contains positive and negative fixtures for namespace, layer, framework, cross-context, strict-types, grouped-import, and fully-qualified-reference enforcement. Full PHPUnit, Pint, Composer validation, TypeScript typecheck, and production build pass.
- Rollback: restore the prior user record namespace and remove the architecture suite/script; no database or external state was changed.

## TASK-FND-003 acceptance evidence

- Completed: 2026-10-01.
- Traceability: `TASK-FND-003`, `NFR-16`, `NFR-19`, `ADR-0002`, and `ADR-0005`; no business `FR-*` or `BR-*` behavior is introduced.
- Database baseline: all application and framework tables run on MySQL 8 with InnoDB, `utf8mb4`, and `utf8mb4_0900_ai_ci`; the development database migration completed successfully.
- Identity: aggregate roots use lowercase ULID values stored as `CHAR(26)`; users and properties no longer depend on auto-incrementing domain identifiers.
- Property scope: a request-scoped property context, fail-closed Eloquent global scope, automatic property assignment, immutable `property_id`, and write/delete scope checks prevent accidental cross-property access.
- Concurrency: the reusable `lock_version` implementation performs compare-and-swap updates and raises an explicit conflict when a stale record attempts to save.
- Scope boundary: users remain global; property membership, authentication, RBAC, and policy resolution remain owned by `TASK-FND-004`. No property, timezone, or currency seed/default business policy was guessed.
- Migration safety: destructive test migration is hard-guarded to the dedicated `innsync_test` database. Foreign keys and rollback order were verified against MySQL.
- Automated evidence: 26 PHPUnit tests with 66 assertions pass, including real MySQL migration/rollback, engine/collation/ULID checks, tenant isolation, cross-property write rejection, and stale-write conflict. Composer validation, Pint, TypeScript typecheck, and the production frontend build also pass.
- Rollback: roll back batch 1 only while the baseline contains no retained application data, or restore from backup after data exists; the migration removes the newly created baseline tables in dependency-safe order.

## TASK-FND-004 acceptance evidence

- Completed: 2026-10-01.
- Traceability: `TASK-FND-004`, `NFR-05`, `NFR-06`, `NFR-22`, `BR-004`, `ADR-0002`, and `ADR-0005`; no business `FR-*` workflow is introduced.
- Authentication: generic credential failures avoid account enumeration; persistent failed-attempt lockout and hashed per-identity/IP rate-limit keys limit brute-force attempts. Password changes enforce a 12-character mixed-case/number/symbol policy and revoke other sessions.
- MFA: standards-compatible TOTP and one-time recovery codes are supported. Secrets and recovery-code hashes are encrypted at rest, and every active role with `requires_mfa` forces setup/challenge before application access.
- Authorization: least-privilege permission codes are feature/action-specific. Role grants are property-owned and can be narrowed to exact property, outlet, or department scope; property-level grants are hierarchical only inside their own property.
- Property isolation: users must select an authorized active property. Request middleware resolves a fail-closed property context, while server-side permission middleware rejects missing privileges and cross-property/resource-scope access.
- Session security: database sessions retain the framework idle timeout, encrypted HttpOnly/SameSite cookies, production-default secure cookies, CSRF-protected web routes, password-hash session invalidation, active-device listing, owner-scoped revocation, and recent-password confirmation for sensitive actions.
- UI evidence: accessible Inertia pages cover login, MFA enrollment/challenge/recovery display, property selection, password confirmation/change, session listing/revocation, authentication redirects, validation errors, empty property access, and processing states.
- Schema: users gained account-lock/MFA/security timestamps; `roles`, `permissions`, `role_permissions`, and `user_role_assignments` use ULIDs, property scope, foreign keys, composite indexes, uniqueness, optimistic locking, and MySQL CHECK constraints. Migration batch 2 completed on the development database.
- Policy boundary: no default account, role, permission, or managerial policy is seeded. Resource ownership checks for future outlet/department records remain mandatory in their owning module policies. `BR-004` approval workflows remain owned by `TASK-FND-018`; audit/security-event persistence remains owned by `TASK-FND-005`.
- Automated evidence: the full PHPUnit suite passes with 45 tests and 146 assertions, covering RFC 6238 vectors, lockout across IP addresses, login throttling, encrypted MFA storage, strong passwords, recent-password enforcement, inactive-session revocation, session ownership, database constraints, cross-property and exact-scope denial, and server-side permission middleware. Composer validation, Pint, route/config cache, TypeScript typecheck, and production build also pass.
- Rollback: roll back migration batch 2 only before identity assignments are relied upon. After role/assignment data exists, deploy a forward migration or restore a verified backup rather than dropping authorization state.

## TASK-FND-005 acceptance evidence

- Completed: 2026-10-01.
- Traceability: `TASK-FND-005`, `NFR-10`, `NFR-20`, `NFR-29`, `BR-009`, `ADR-0002`, and `ADR-0005`; no business `FR-*` workflow is introduced.
- Audit evidence: application-layer `AuditTrail` records actor, action, aggregate, property, before/after state, reason, approval reference, UTC timestamp, correlation ID, retention floor, and payload checksum. Property-owned writes fail closed without the matching property context and participate in the caller's database transaction.
- Security evidence: authentication, account denial, MFA, password confirmation/change, logout, property selection, session revocation, and permission denial emit separate security events. Source IPs are stored only as keyed hashes; credentials, tokens, recovery codes, card data, and unmasked identity-document fields are rejected from audit/security payloads.
- Immutability: MySQL `BEFORE UPDATE` and `BEFORE DELETE` triggers reject changes to `audit_entries` and `security_events`; no product deletion endpoint or Eloquent mutation model exists. Foreign keys restrict deletion of referenced actors/properties so historical attribution cannot be silently nulled.
- Correlation and logging: every HTTP request receives or validates a ULID correlation ID, returns it in `X-Correlation-ID`, adds it to Laravel Context for automatic queue propagation, and carries it into JSON application logs and persisted evidence. Invalid inbound IDs are replaced. Structured log processors recursively redact sensitive context keys.
- Retention boundary: `Q-15` remains unresolved, so no retention duration was guessed. Blank retention configuration records an indefinite retention floor; positive environment-configured days are supported and invalid/non-positive values fail closed. No purge mechanism is included until an approved policy exists.
- Schema: immutable ULID-keyed evidence tables use InnoDB/`utf8mb4`, UTC microsecond timestamps, JSON snapshots/metadata, checksums, outcome/state constraints, required foreign keys, and property/actor/aggregate/event/correlation/retention indexes. Migration batch 3 completed on the development database.
- NFR boundary: this task implements the structured-log, audit/security-log, and trace/correlation portions of `NFR-20`. Health checks, metrics, and alert thresholds remain explicitly owned by `TASK-FND-010`.
- Automated evidence: the full PHPUnit suite passes with 60 tests and 192 assertions, including correlation propagation on normal/error responses, secret redaction/rejection, stable checksums, configurable/indefinite retention, atomic rollback, cross-property rejection, DB constraints/triggers, real authentication events, and permission-denial events. Composer validation, Pint, route/config cache, TypeScript typecheck, and production build also pass.
- Rollback: roll back migration batch 3 only before audit/security evidence is relied upon. Once evidence exists, retain it and use a forward migration; dropping these tables destroys compliance evidence and is not an operational rollback.

## TASK-FND-006 acceptance evidence

- Completed: 2026-10-01.
- Traceability: `TASK-FND-006`, `NFR-18`, `NFR-20`, `BR-005`, `BR-010`, `ADR-0002`, and `ADR-0005`; no business `FR-*` workflow is introduced.
- Application contract: the transport-neutral `IdempotentExecutor` claims a key, executes the caller's authorized mutation, and stores its logical result in one bounded MySQL transaction. Exceptions roll back both the claim and business writes so a safe retry can execute again.
- Retry behavior: the first successful request returns a new result; an equivalent retry returns the stored result without invoking the operation again. Canonical payload hashing treats associative key order as equivalent, while changed payloads and changed actors fail closed with an explicit conflict.
- Scope and authorization boundary: every record is property-owned and requires the matching active property context. Key uniqueness spans property, operation, and keyed hash; the original actor remains bound to the key. Calling use cases must complete server-side authorization before entering the executor.
- Data minimization: raw keys and request payloads are never persisted. Keys and request fingerprints use HMAC-SHA-256 with a separately configurable stable secret; replay results are encrypted with the application encrypter. Application/security logs receive only the key hash and non-sensitive conflict metadata.
- HTTP adapter: route middleware requires a 16–128 character safe `Idempotency-Key` header, exposes the validated key only through a request-scoped application context, and clears it after the request. Correlation ID remains a tracing identifier and is never silently substituted for an idempotency key.
- Concurrency and observability: a MySQL unique constraint is the final duplicate defense and replay reads use `SELECT ... FOR UPDATE`. Bounded transaction deadlock retry is enabled. Original/replay correlation IDs, replay count, and replay time are retained; actor/payload conflicts and unreadable stored results create persistent security events without exposing the key or payload.
- Schema: `idempotency_operations` uses ULID identity, property/actor foreign keys, explicit completion consistency checks, encrypted versioned results, UTC microsecond timestamps, and indexes for property/time, actor/time, correlation, completion, and the unique operation key. Migration batch 4 completed on the development database.
- Lifecycle boundary: no expiry or purge duration was guessed. Completed keys remain durable so an old retry cannot silently recreate a financial/stock operation; any future cleanup policy requires an explicit safety decision. Standard conflict/error response envelopes remain owned by `TASK-FND-009`, and concrete offline/import/webhook/payment use cases remain owned by their feature tasks.
- Automated evidence: the full PHPUnit suite passes with 73 tests and 229 assertions, including middleware validation/context cleanup, canonical fingerprints, encrypted result replay, single-effect retry, payload/actor conflict, atomic rollback/retry, missing and cross-property context denial, operation/property key isolation, database uniqueness, tampered stored-result safety, and security-event persistence. Composer validation, Pint, architecture tests, route/config cache, TypeScript typecheck, and production build also pass.
- Rollback: roll back migration batch 4 only before business operations rely on stored keys. After use, dropping the table removes duplicate-detection memory and can permit repeated posting; retain records and use a forward migration instead.

## TASK-FND-007 acceptance evidence

- Completed: 2026-10-01.
- Traceability: `TASK-FND-007`, `NFR-17`, `NFR-18`, `NFR-20`, `NFR-25`, `ADR-0002`, `ADR-0005`, and `ADR-0007`; no business `FR-*` workflow is introduced.
- Publish contract: `OutboxPublisher` persists a versioned `OutboxEvent` inside the caller's `TransactionRunner` transaction. Publishing outside a transaction or under a mismatched property context fails closed. Persisted envelope is encrypted; event data must stay minimal and secret-free.
- Drain/queue: `outbox:drain` atomically moves pending messages to the database queue (`outbox` queue, bounded batch). Scheduler runs drain plus a bounded `queue:work --stop-when-empty --max-time` every minute with `withoutOverlapping`; no permanent daemon is required (shared-hosting profile).
- Consumers: registered in `config/outbox.php`. A `(event_id, consumer)` receipt is claimed in the same transaction as the consumer effect, so retries cannot repeat a committed effect. External network delivery stays owned by `TASK-FND-020`.
- Failure handling: configurable attempts and backoff delays, then dead letter. `outbox:retry {property_id} {event_id}` requeues a reviewed dead letter and records a security event. Unreadable payloads and unhandled messages fail visibly.
- Schema: migration `0001_01_01_000007_create_outbox_tables` (batch 5 ran on development database).
- Automated evidence: full PHPUnit suite passes with 87 tests and 295 assertions, including unit, console, and pipeline integration tests. Pint passes; composer validate, config cache, and route cache pass.
- Rollback: roll back the migration only while no pending/dead-letter messages exist; otherwise use a forward migration to avoid losing unpublished messages.

## TASK-FND-008 acceptance evidence

- Completed: 2026-10-01.
- Traceability: `TASK-FND-008`, `NFR-07`, `NFR-08`, `NFR-24`, `BR-009`, `ADR-0002`, and `ADR-0005`; no business `FR-*` workflow is introduced. Concrete uploads (`FR-FO-011`, HR, work-order evidence) remain owned by their module tasks.
- Storage: dedicated `private_files` local disk under `storage/app/private-files` (no `url`, not served, not in `filesystem.links`, outside `public/`). Blobs are encrypted with the application encrypter, written under a random 256-bit key with fan-out directories; client names never reach the path.
- Upload: `StoreFile` takes a module-declared `FilePolicy` (explicit MIME allow-list, size ceiling, sensitivity). MIME is detected from bytes via `finfo`, never from the claimed name/header; empty, oversized, wrong-type, and already-expired uploads are rejected before anything is written. Display names are sanitized. If the metadata transaction fails, the unrecorded blob is discarded. Caller must authorize the attach action first.
- Download: `DownloadFile` requires a module-supplied `FileAccessPolicy` (object-level authorization, IDOR defense), property-scoped lookup (cross-property reads fail closed), expiry check, and SHA-256 integrity verification. Every success writes a `file.downloaded` audit entry; denials and expired access write `file.download-denied` security events; integrity failures write `file.integrity-failed`. Expired and foreign files surface as not-found. `StoredFileResponse` builds attachment responses with `nosniff`, sandbox CSP, `no-store`, and no permanent public URL.
- Retention boundary: Q-15 is unresolved, so no retention period or purge job was guessed. `expires_at` is nullable (null = no decision) and blocks download once reached; policies for sensitive exports can set `requiresExpiry` so the owning task must supply one. Physical deletion after retention is owned by `TASK-FND-019`.
- Schema: `stored_files` (ULID, property FK, unique storage key, check constraints, UTC microsecond timestamps, owner/purpose/expiry indexes) is append-only via update/delete triggers, consistent with the audit tables. Migration batch 6 ran on the development database.
- Automated evidence: full PHPUnit suite passes with 99 tests and 331 assertions, including encryption-at-rest, random naming, byte-sniffed MIME rejection, size/empty/expiry rejection, orphan cleanup, authorized and denied download, audit and security events, expiry, cross-property isolation, tamper/missing-blob detection, append-only metadata, and private-disk configuration. Pint, architecture tests, Composer validation, config cache, and route cache pass.
- Rollback: drop `stored_files` only before any file is recorded; afterward use a forward migration, since blobs without metadata are unreadable and metadata is audit evidence.

## TASK-FND-009 acceptance evidence

- Completed: 2026-10-01.
- Traceability: `TASK-FND-009`, `NFR-19`, `NFR-18`, `NFR-20`, `ADR-0004`; supports the design rule that conflicts are surfaced, not hidden (`docs/DESIGN/05-FORMS-VALIDATION.md`). No business `FR-*` workflow is introduced.
- Contract: JSON/API requests (`api/*` or `Accept: application/json`) receive `{"error": {code, message, status, retryable, correlation_id, fields?, conflict?}}`. Clients branch on `code` and `conflict.{reason,action}`; `message` is fixed human text (localization is `TASK-FND-013`). `correlation_id` matches the `X-Correlation-ID` header.
- Conflict semantics: `OptimisticLockConflict`, `IdempotencyConflict` (request/actor mismatch), and an in-flight idempotent operation map to HTTP 409 `conflict` with a reason and a user action (`refresh`, `review`, or `retry`, the latter flagged `retryable`). No last-write-wins path is introduced.
- Validation: 422 `validation_failed` with per-field messages. Other mapped outcomes: 401, 403 (cross-property and missing-policy collapse to `forbidden`; no property context is `property_context_required`), 404, 405 (with `Allow`), 419, 429 (with `Retry-After`), 5xx.
- Safety: unexpected errors become a generic 500 envelope; exception messages, record IDs, SQL, and file names never reach the body (asserted by leak tests). Expected outcomes (conflicts, denials, rejected files, scope violations) are excluded from error reporting; real defects are still reported.
- Web/Inertia: non-JSON requests keep framework behavior (validation redirects, error pages). Inertia mutations hitting an expected domain exception get a 303 redirect back with the message in the `error` bag, preserving the form instead of a raw error modal.
- Frontend: `resources/js/shared/lib/api-error.ts` provides the typed envelope, guard, and `isConflict`. Rendering conflict/error UI states remains owned by `TASK-FND-012` and module screens.
- Automated evidence: full PHPUnit suite passes with 114 tests and 468 assertions (new `ErrorEnvelopeTest`: all mappings, no-leak, conflict reason/action, validation fields, HTTP status/headers, Inertia redirect, reporting exclusion). Pint, TypeScript typecheck, production build, config cache, and route cache pass.
- Rollback: remove the `render`/`dontReport` registration in `bootstrap/app.php`; no database or external state changed.

## TASK-FND-010 acceptance evidence

- Completed: 2026-10-01.
- Traceability: `TASK-FND-010`, `NFR-20`, `NFR-23`, `NFR-25`, `ADR-0003`, `ADR-0008`; no business `FR-*` workflow is introduced.
- Health contract: `HealthCheck` port (`name`, `check` returning ok/degraded/down with a safe summary and non-sensitive numeric context). Built-in checks: database, scheduler heartbeat, failed jobs, outbox backlog/dead letters, storage capacity/writability, and unexpected-error rate. Other tasks plug in `payment unknown`, `sync backlog`, `backup failure`, night-audit, and provider-degradation checks through `config/observability.php` `checks`. A check that throws becomes `down` and never breaks the run.
- Endpoints: liveness `/up` stays framework-provided. `GET /health` is a stateless readiness probe (no session/cookies, `no-store`, rate limited) returning only overall status; 503 only when `down`. `GET /health/details` returns per-check detail only with the `HEALTH_TOKEN` bearer secret (constant-time compare); it is 404 when no secret is configured, and failed attempts become `health.details-denied` security events.
- Alerts: `EvaluateAlerts` keeps one open alert per key (`operational_alerts`, unique open key) and notifies on raise, escalation, and resolution only, so a persistent fault does not repeat every tick. Delivery is a structured log line (critical for `down`, warning for `degraded`) carrying the correlation context. Scheduler runs `health:heartbeat` every minute and `health:alerts` every five minutes without overlap; `health:check [--json]` exits non-zero when any check is down.
- Error rate: reported (unexpected) exceptions are counted per minute bucket; expected conflicts, denials, and rejections are excluded by the `dontReport` list from `TASK-FND-009`. Recording failures are swallowed so observability cannot cause a second error.
- Shared-hosting fit: no daemon, no new package or service; everything runs from the existing cron and database/cache stores. The shared `Clock` port moved to `App\Shared\Application\Time`.
- Operational thresholds are env-tunable defaults, not hotel policy. Boundaries left open on purpose: pushing alerts to e-mail/chat needs an approved provider and recipients (`TASK-FND-020`); database size/quota is hosting-specific and not guessed; backup, payment, sync, and night-audit signals are owned by `TASK-FND-011`, `TASK-FND-017`, and their module tasks.
- Automated evidence: full PHPUnit suite passes with 130 tests and 584 assertions (new `HealthAlertingTest` and `HealthEndpointTest`: alert lifecycle and dedupe, throwing check, each built-in threshold, heartbeat staleness, error-rate counting, commands, probe secrecy/secret/rate limit/security event). Pint, TypeScript typecheck, config cache, and route cache pass; migration batch 7 ran on the development database.
- Rollback: remove the routes/commands/schedule entries and drop `operational_alerts`; alert rows are operational data and may be dropped.

## TASK-FND-011 acceptance evidence

- Status: `REVIEW`, not `DONE`. Backup, restore test, monitoring, and runbook are implemented and verified, but `NFR-11` RPO ≤ 15 minutes is **not** met and needs an owner/infrastructure decision (see runbook "Open decisions"). Completed work: 2026-10-01.
- Traceability: `TASK-FND-011`, `NFR-11`, `NFR-20`, `NFR-21`, `NFR-23`, `NFR-30`, `ADR-0003`, `ADR-0008`. Runbook: `docs/OPERATIONS/DR-RUNBOOK.md`.
- Backup: `backup:run` (daily schedule) streams `mysqldump --single-transaction` (routines, triggers, events) and an archive of the private-files directory through libsodium secretstream encryption with a dedicated `BACKUP_ENCRYPTION_KEY` (not `APP_KEY`). No plaintext dump is written to disk; credentials reach child processes only via `MYSQL_PWD`. Sets are 0700/0600 and carry an HMAC-signed manifest (artifact SHA-256, tables, migrations, row counts, file counts).
- Fail-closed configuration: nothing runs without an absolute, writable destination outside the application tree and a valid 32-byte key; the restore target must be a dedicated `*_restore_test` database different from the live one.
- Restore evidence: `backup:verify` (weekly when configured) verifies signature and checksums, imports into the scratch database, and checks table set, migration list, append-only row-count windows (`audit_entries`, `security_events`, `stored_files`), and restored file count/bytes. Duration is recorded in `backup_runs` as RTO evidence. `backup:decrypt` verifies a set and decrypts one artifact for manual disaster recovery.
- Monitoring: health check `backup` (NFR-20 "backup failure") reports overdue/failed backups and stale restore tests; unconfigured backups are `down` in production, `degraded` elsewhere.
- Real-run evidence (development database): backup of the live schema plus restore into `innsync_restore_test` passed in about 4.6 s; a wrong key is rejected at the manifest signature.
- Automated evidence: new `BackupCipherTest` (round trip beyond one chunk, empty stream, wrong key, bit flip, truncation, dropped tail, trailing data, key length) and `BackupRestoreTest` (encrypted/signed output, DB+files restore, triggers survive, artifact/manifest tampering, wrong key, unsafe destination/target, pruning, health states, decrypt command). Existing health tests updated for the new check. Integration tests need the `innsync_restore_test` scratch database (created if the test user may; otherwise skipped).
- Not guessed (unresolved policy): backup retention (Q-15; `BACKUP_KEEP_LAST` unset keeps every set), off-box destination, and PITR. The manual-fallback procedures in the runbook are a draft for owner/operations review; automated reconciliation of fallback transactions is owned by `TASK-FND-017`.
- Rollback: remove the schedule/commands and drop `backup_runs`; existing backup sets on the destination are independent files and remain restorable with the key.

## TASK-FND-012 acceptance evidence

- Status: `REVIEW`, not `DONE`. The query/table conventions and the primitives below are implemented and verified, but part of the `docs/DESIGN/04-COMPONENTS.md` component list is deliberately deferred (see "Not included"). Completed work: 2026-10-01.
- Traceability: `TASK-FND-012`, `NFR-01`, `NFR-12`, `NFR-27`, `NFR-19`, `ADR-0004`; implements `docs/RULES/07`, `08`, `09` and `docs/DESIGN/04`-`07`, `09`. No business `FR-*` or `BR-*` behavior is introduced.
- Query convention (`shared/api`): `createResourceKeys(module, resource)` builds hierarchical keys (`all > lists > list > details > detail`) that always carry the active property id and refuse a blank one, so cached data cannot cross a property switch. Parameters are normalized so equal filters share a key. `apiRequest` is the transport-only JSON client: JSON headers, Laravel XSRF cookie on mutations, optional `Idempotency-Key` (`newIdempotencyKey`, to be reused across retries of one intent), and every failure becomes an `ApiError` carrying the `TASK-FND-009` envelope. Unrecognised bodies, HTML and exception text never reach the UI. `createAppQueryClient` retries only transient failures (offline, 429, retryable 5xx, at most 2 times) and never retries conflict, validation, permission, or session errors; mutations are never retried implicitly (NFR-19).
- Failure model (`shared/lib/api-error`): `Failure.kind` is one of validation, conflict, forbidden, unauthenticated, session-expired, not-found, rate-limited, offline, server-error. `ErrorState` renders a dedicated state per kind, offers refresh (not blind retry) for a conflict, a sign-in action for an expired session, retry only when retryable, and shows only the correlation id for support.
- Table convention (`shared/table`, `components/ui/data-table`): `TableQueryState` (page, page size, sort, filters) is parsed from and serialized to the URL (`useTableQuery`, an Inertia client-side visit with no server round trip; rows come from TanStack Query keyed on the same state, so a dataset is not loaded through both mechanisms). URL input is untrusted: page size, sort column, and filter keys are clamped to the screen's allow-lists, filter length and page number are capped, and any filter/sort/size change returns to page 1. `DataTable` runs TanStack Table v9 in manual server mode (`manualPagination`, `manualSorting`, server `rowCount`), requires a typed column model and a stable `getRowId`, shows loading skeleton, empty, filtered-empty (with clear action), error, and refreshing states, keeps stale rows visible if a refresh fails, recovers from a stale deep link past the last page, exposes `aria-sort`, a labelled keyboard-scrollable region, and an optional compact list below `md`. Only columns declared in `sortableColumns` get a sort control. Column visibility is presentation only and never replaces authorization.
- Primitives (`components/ui`): `Button` (variants, `loading` that blocks duplicate submission and sets `aria-busy`), `IconButton` (required accessible label), `Input`, `Textarea`, `Select` (native), `Label`, `FormField` (associates label/hint/error through `htmlFor`, `aria-describedby`, `aria-invalid`, explicit required marker), `Badge`, `StatusBadge` (icon + required text; `unknown` is visually distinct from success and pending), `Skeleton`, `Alert`, `EmptyState`, `ErrorState`, `Pagination` (server total only), `FilterBar`, `PageHeader`, `Metric`, `Dialog` and `ConfirmDialog` (native `<dialog>`: focus trap, Escape, focus return; the alert variant ignores backdrop clicks and states the consequence). Only semantic design tokens are used; touch targets are at least 40-44 px.
- No new package: no dependency was added. Dialog and Select use native elements instead of new Radix packages, which would need an approved dependency.
- Not included (deferred on purpose): `Combobox`, `DatePicker`, `Drawer/Sheet`, `DropdownMenu`, `Tabs`, `Toast` (each needs a new package or its own accessible design pass); `MoneyInput`/`QuantityInput` (depend on the currency/rounding policy, ADR-0006 and open PRD questions, not guessed); `Timeline`/`AuditTrail`/`ApprovalPanel` (own `TASK-FND-018` and the module screens). All user-facing text is passed in by the screen, because the i18n framework is `TASK-FND-013`; there is no built-in Indonesian/English copy yet. The `NFR-01` response-time target and the `NFR-12` one-hour training goal need real screens and are not measurable from primitives.
- Automated evidence: `npm test` (Node built-in runner, no dependency) passes 34 tests across the failure mapping, query keys, HTTP client, retry policy, and URL table state, including hostile URL input, property-scoped keys, XSRF/idempotency headers, offline and non-JSON failures. `npm run typecheck` (strict) and `npm run build` pass.
- Interaction evidence: a throwaway harness rendered in Chromium (not committed) passed 28 checks covering server-driven sort and paging, URL serialization, filter reset to page 1, filtered-empty and clear, loading `aria-busy` and announcement, empty, conflict with refresh and correlation id and no retry, offline retry, field error association, modal alert dialog with Escape and no backdrop dismissal, and the mobile list replacing the table. There is no component-level DOM test runner in the repository; adding one (for example Vitest plus Testing Library) needs an approved dependency. This is the gap that keeps the task in `REVIEW`.
- Not verified: Pint and PHPUnit were not run because the change touches no PHP and `vendor/` is not installed in this environment. Screen reader and contrast audits were not performed.
- Rollback: remove the files added under `resources/js/components/ui`, `resources/js/shared/api`, `resources/js/shared/table`, `resources/js/test-support`, restore `button.tsx`, `input.tsx`, `app.tsx`, `api-error.ts`, `tsconfig.json`, and the `test` script; no database or external state changed.

## NFR coverage

| NFR | Category | Requirement |
| --- | --- | --- |
| NFR-01 | Kinerja | Halaman POS dan pelacak Housekeeping merespons dalam waktu kurang dari 2 detik; dashboard selesai dimuat kurang dari 3 detik pada koneksi 4G. |
| NFR-02 | Kapasitas | Mendukung hingga 150 kamar, 50 pengguna bersamaan, dan 5.000 transaksi POS per hari tanpa penurunan kinerja berarti. |
| NFR-03 | Ketersediaan | Ketersediaan layanan target minimal 99,9% per bulan di luar jendela pemeliharaan terjadwal. Degradasi fungsi non-kritis tidak boleh memblokir check-in, checkout, POS, atau room status. |
| NFR-04 | Ketahanan luring | POS dan Housekeeping tetap dapat mencatat aktivitas tanpa jaringan minimal 4 jam. Data lokal dienkripsi, memiliki status sync, retry, idempotency key, dan aturan konflik; kegagalan sinkronisasi tidak boleh silently discard data. |
| NFR-05 | Keamanan akses | Autentikasi dengan kata sandi kuat, penguncian akun setelah percobaan gagal berulang, dan verifikasi dua langkah untuk peran manajerial. |
| NFR-06 | Otorisasi | Kontrol akses berbasis role hingga tingkat fitur dan scope property/outlet/department. Aksi sensitif menggunakan maker-checker/approval dan, bila approval diwajibkan, pembuat tidak boleh menjadi satu-satunya approver. |
| NFR-07 | Kerahasiaan data | Data dienkripsi saat transit dan saat disimpan; foto identitas tamu disimpan terenkripsi dengan akses terbatas dan masa retensi yang dapat dikonfigurasi. |
| NFR-08 | Kepatuhan privasi | Mendukung privacy notice/consent, pembatasan tujuan, hak akses/koreksi/penghapusan sesuai kebijakan yang berlaku, data retention configurable, serta audit atas akses dan ekspor PII. |
| NFR-09 | Pembayaran | Sistem tidak menyimpan nomor kartu; transaksi kartu diselesaikan melalui perangkat atau gerbang pembayaran bersertifikat. |
| NFR-10 | Jejak audit | Seluruh perubahan data transaksional tercatat lengkap dan tidak dapat dihapus oleh pengguna mana pun. |
| NFR-11 | Cadangan data | Backup otomatis mencakup full backup harian dan mekanisme point-in-time/incremental yang mencapai RPO ≤15 menit. Target RTO ≤4 jam. Restore test dijalankan berkala dan hasilnya dicatat. |
| NFR-12 | Kegunaan | Antarmuka dua bahasa (Indonesia dan Inggris), rancangan mengutamakan ponsel untuk staf lapangan, dan pelatihan dasar cukup satu jam per peran. |
| NFR-13 | Kompatibilitas | Mendukung peramban terkini pada Android dan iOS, printer thermal standar ESC/POS, pemindai barcode, dan layar dapur. |
| NFR-14 | Terpelihara | Pemisahan tegas antara logika dan tampilan, penomoran versi aplikasi yang tampil pada antarmuka, serta catatan perubahan setiap rilis. |
| NFR-15 | Dokumentasi | Tersedia panduan penggunaan berbasis peran di dalam aplikasi, bukan sekadar dokumen instalasi. |
| NFR-16 | Skalabilitas | Struktur data disiapkan untuk pengoperasian banyak properti dalam satu akun pada rilis berikutnya. |
| NFR-17 | Konsistensi transaksi | Operasi lintas modul yang bersifat finansial atau stok menggunakan atomic transaction/outbox yang sesuai; sistem tidak boleh meninggalkan partial posting tanpa exception yang terlihat. |
| NFR-18 | Idempotensi | Endpoint/action kritis, offline sync, import, webhook, dan payment callback wajib aman terhadap retry menggunakan idempotency key/correlation ID. |
| NFR-19 | Konkurensi | Bill, folio, reservasi/availability, stock count, dan approval memakai optimistic/pessimistic control yang mencegah lost update; konflik harus ditampilkan kepada pengguna. |
| NFR-20 | Observability | Tersedia structured logs, audit/security logs, health check, metrics, trace/correlation ID, serta alert untuk payment unknown, sync backlog, error rate, job failure, backup failure, dan kapasitas kritis. |
| NFR-21 | Disaster recovery | Prosedur DR terdokumentasi, dependency dan credential recovery diuji, serta simulasi restore dilakukan minimal triwulanan atau sesuai kebijakan operasional properti. |
| NFR-22 | Keamanan sesi | Session timeout, revocation, secure cookie/token storage, device/session list, rate limiting login, CSRF protection untuk web, dan forced re-authentication pada aksi sensitif diterapkan sesuai risiko. |
| NFR-23 | Manajemen rahasia | Secret, API key, private key, dan credential tidak disimpan pada source code/log; rotasi dan pencabutan dapat dilakukan tanpa redeploy penuh bila memungkinkan. |
| NFR-24 | Keamanan ekspor | File ekspor sensitif memiliki akses terbatas, expiry, dan audit download; tautan publik permanen untuk data tamu/karyawan dilarang. |
| NFR-25 | Ketahanan integrasi | Integrasi eksternal memiliki timeout, retry dengan backoff, circuit breaker/queue bila relevan, dead-letter handling, dan rekonsiliasi manual untuk state unknown. |
| NFR-26 | Waktu & zona | Semua timestamp disimpan konsisten dan ditampilkan menurut zona waktu property; business date dipisahkan dari clock date untuk night audit dan laporan. |
| NFR-27 | Aksesibilitas | Antarmuka back office dan tamu menargetkan praktik aksesibilitas modern: keyboard navigation, label form, kontras, focus state, pesan error yang dapat dipahami, dan dukungan pembaca layar untuk alur utama. |
| NFR-28 | API & kompatibilitas | API/integration contract memiliki versioning dan backward-compatibility policy; browser/device support matrix didokumentasikan dan diuji pada versi minimum yang disepakati. |
| NFR-29 | Retensi audit | Audit trail, security event, dan evidence approval memiliki retensi minimum yang dapat dikonfigurasi dan tidak dapat dihapus oleh pengguna operasional biasa. |
| NFR-30 | Pemulihan operasional | Untuk kegagalan layanan kritis, tersedia runbook, fallback manual, serta mekanisme rekonsiliasi saat layanan pulih agar transaksi fallback tidak hilang atau terduplikasi. |
