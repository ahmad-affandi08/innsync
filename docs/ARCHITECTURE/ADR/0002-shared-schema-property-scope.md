# ADR 0002 — MySQL Shared Schema + Property Scope

**Status:** Accepted

## Decision

Use a shared MySQL schema; all property-owned data has explicit property scope and scoped repositories/policies.

## Rationale

MySQL shared hosting does not provide PostgreSQL RLS; explicit scope plus tests is portable and supports future multi-property.

## Change rule

A different decision requires a superseding ADR and impact review against PRD/NFRs.
