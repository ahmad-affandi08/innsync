# Inertia + TanStack Query/Table Rules

## Inertia owns

Navigation, page entry payload, server-rendered app shell, conventional form submissions, redirects/flash, and route-level authorization results.

## TanStack Query owns

Independently refreshed server state: dashboard widgets, long-lived lists, background polling, dependent async data, optimistic flows that have a defined rollback, and reusable query caches.

Do not fetch the same canonical dataset through both mechanisms without an explicit invalidation strategy.

## TanStack Table

- Column definitions are typed.
- Large datasets use server-side pagination/filter/sort.
- URL query parameters are the shareable source for table filters where appropriate.
- Export is server generated; never assume the browser has all rows.
- Column visibility does not replace authorization.
