# ADR 0007 — Transactional Outbox

**Status:** Accepted

## Decision

Persist async domain/integration messages inside the same database transaction as source state, then dispatch later.

## Rationale

Prevents partial state where DB commit succeeds but message publication is lost.

## Change rule

A different decision requires a superseding ADR and impact review against PRD/NFRs.
