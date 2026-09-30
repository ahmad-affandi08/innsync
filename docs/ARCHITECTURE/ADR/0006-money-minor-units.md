# ADR 0006 — Money in Minor Units

**Status:** Accepted

## Decision

Store money as integer minor units plus currency. Use DECIMAL only for rates/exchange ratios.

## Rationale

Eliminates floating-point drift and supports explicit currency.

## Change rule

A different decision requires a superseding ADR and impact review against PRD/NFRs.
