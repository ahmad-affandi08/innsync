# ADR 0004 — Inertia and TanStack Ownership Boundary

**Status:** Accepted

## Decision

Use Inertia for navigation/page payloads and TanStack Query for independently refreshed/cached async server state; never duplicate the same canonical state in both caches without an invalidation contract.

## Rationale

Avoids double fetching and cache incoherence.

## Change rule

A different decision requires a superseding ADR and impact review against PRD/NFRs.
