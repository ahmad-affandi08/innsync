# Cache, Queue, and Scheduler

## Shared-hosting-safe baseline

- Cache: database/file by default; Redis only after infrastructure verification and ADR.
- Queue: database driver for async non-interactive work.
- Scheduler: one cron invokes Laravel scheduler every minute when plan supports it.
- Queue draining: cron launches a bounded worker (`--stop-when-empty`, bounded attempts/time). No permanent worker assumption.

## What may be queued

Reports, email, outbound webhook, thumbnails/exports, non-critical projections, retryable provider synchronization.

## What may not depend on eventual queue completion to preserve correctness

Payment/folio/stock atomic posting, room allocation lock, checkout close, approval decision, night-audit control totals. Their source-of-truth commit must be complete before returning success.
