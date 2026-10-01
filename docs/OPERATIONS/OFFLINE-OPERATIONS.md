# Offline Operation Guide (POS and Housekeeping)

Traceability: `TASK-FND-017`, `NFR-04`, `NFR-18`, `NFR-19`, `NFR-20`, `NFR-07`, `NFR-09`, `BR-005`, `BR-010`; `docs/ARCHITECTURE/10-OFFLINE-IDEMPOTENCY-CONCURRENCY.md`, `docs/DESIGN/07-STATES-FEEDBACK.md`, `docs/DESIGN/08-RESPONSIVE-PWA.md`. Draft for owner and operations review.

Offline capability exists for POS and Housekeeping only. The foundation provides the envelope, the encrypted queue, synchronization, and the reconciliation record. A module adds only its own business rule.

## How it works

1. A screen calls `useOfflineQueue().enqueue({ type, payload, payloadVersion, baseVersion })`. The change is **encrypted on the device** (AES-256-GCM, a fresh IV per message, bound to the entry, user and property) and stored in IndexedDB with a client-generated operation ID (ULID) and the device's next sequence number. It is shown as *waiting*, never as done.
2. The queue syncs on sign-in, when the network returns, when the tab becomes visible, after each change, and when a delayed entry falls due. `POST /sync/batch` carries up to 50 items.
3. For every item the server re-checks, in order: the item is well formed and holds no card data, identity documents or secrets; its property is the active property; its author is the signed-in user; the handler exists and supports the payload version; the user holds the handler's permission **on the server**. Then the handler runs inside one idempotent transaction keyed by the operation ID.
4. The answer per item is `accepted`, `conflict`, `rejected`, `retry_later` or `deferred`. A retry of an item that already ran returns the **same logical result** and applies nothing twice, so a lost response is harmless.
5. Conflicts and rejections are stored in `offline_sync_exceptions` (payload encrypted, never deletable) so a manager can reconcile them. The device keeps its own copy, visibly, until a person removes it.

## Rules the foundation enforces for you

- A device's items are applied **in its own order**. A transient failure stops that device's line; an item can never overtake an earlier one that is waiting, in flight or stalled. Conflicts and rejections do not block the entries behind them, so a handler must validate dependencies (for example "pay bill" for a bill that was never opened) and return a conflict or rejection.
- A lost network is not an entry's fault and is not counted. Server-side transient failures are counted; after a bound the entry becomes *stalled* (kept, visible, a person can retry). Nothing is silently dropped.
- Items recorded by another user on a shared device wait for their author; they are never applied under someone else's name.
- There is no "overwrite" outcome. Money, stock, room availability and approval changes are accepted on deterministic rules or returned for a person. No last-write-wins.
- A payment whose provider outcome is unknown must be accepted as a payment in `Unknown` state by the handler; the queue state `accepted` only means the server recorded the operation, and no screen may show a queue state as "paid".

## Adding an offline operation (module authors)

1. Implement `OfflineOperationHandler`: a stable `type()` (for example `fnb.pos.sale`), the `payloadVersions()` still supported, the `permission()` required (`null` only for operations any signed-in user of the property may do), and `handle()`. `handle()` runs in the idempotency transaction together with your own writes, must be deterministic, and must not trust anything the client claims about identity, permissions or time. Device time is recorded for forensics only; never derive a business date or ordering from it.
2. Register it in `config/offline.php` under `handlers`.
3. Return `OfflineOutcome::accepted(result, serverVersion)`, `conflict(code, action, serverVersion)`, or `rejected(code)`. Reason codes are short lowercase identifiers; add their user-facing text to the dictionaries.
4. Put external I/O after commit (outbox, `ADR-0007`).
5. Test: duplicate delivery applies once, a conflict is recorded, a missing permission is rejected, an exception rolls back. `tests/Feature/Foundation/OfflineSyncTest.php` shows each with a test handler.
6. On the screen, show `SyncStatus` and `SyncPanel` and let the operator keep working; never block on the network for a queueable action.

## Field test: `/offline-check`

Sign in on the real device and open `/offline-check`. It records harmless `system.echo` changes, so staff and IT can prove the whole path on the device and network the property actually uses: turn the network off, save a few test changes, turn it back on, and watch them sync. The page also reports whether the browser provides IndexedDB, encryption and a secure connection, and whether storage is protected from automatic cleanup. Collect these results from every device model: they are the evidence for the open question `Q-14` (minimum devices and realistic offline duration).

## Monitoring

Health check `sync_backlog` reports **degraded** while a conflict or rejection is open or a device reports a queue older than 15 minutes, and **down** when an exception has been open more than a day or a device reports an unsent queue older than 4 hours (the `NFR-04` minimum offline window). Thresholds are environment-tunable defaults in `config/offline.php`, not hotel policy. A device that is offline cannot report; a stale report is itself the signal. Each batch and heartbeat carries the device's queue depth.

## Operator notes

- **Sign-in required.** If the session expires while offline, entries stay on the device; the status says to sign in again, and they sync afterwards.
- **Needs your attention.** A conflict or rejection stays visible with its reason code. Remove it from the device only after reading it; the server keeps its record, so a manager can still review it.
- **Browsers.** The queue needs a current browser over HTTPS. Browsers may delete a site's storage under pressure unless the site is granted persistent storage; the app asks for it, and the field test shows the answer. Do not rely on a device whose answer is "Not available" for long offline periods until its behavior is verified.
- **Shared devices.** Each user's entries are separate. Sign out only after the status shows everything synced; if not, the unsent entries remain on the device for that user.

## Security notes (read before relying on it)

The device key is a non-extractable key held by the browser for this origin. It keeps the queue from being readable as plain text in storage, in backups, or in the browser's storage tools. It does **not** protect against script running in this origin or against a fully compromised device. A stronger scheme (a key delivered by the server per session, or unlocked by a PIN, with a rule for what happens to unsent entries at sign-out) is a separate security decision and is not made here. Card numbers, identity documents and secrets are refused at the queue and again on the server, and a test keeps the two lists identical (`NFR-07`, `NFR-09`).

No service worker replays writes (`docs/DESIGN/08`): the queue is replayed only by the application code, entry by entry, with idempotency.
