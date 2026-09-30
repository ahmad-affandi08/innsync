# ADR 0001 — Modular Monolith with DDD/Clean Architecture

**Status:** Accepted

## Decision

Use one Laravel deployable with strongly isolated contexts and inward dependency direction.

## Rationale

Fits cross-module transactional consistency and shared-hosting operational limits while preserving future extraction boundaries.

## Change rule

A different decision requires a superseding ADR and impact review against PRD/NFRs.
