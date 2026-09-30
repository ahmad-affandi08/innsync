# ADR 0003 — Cron + Bounded Database Queue

**Status:** Accepted

## Decision

Use scheduler/cron and short-lived database queue workers; no permanent daemon assumption.

## Rationale

Target hosting supports cron but shared hosting is not a process-supervisor environment.

## Change rule

A different decision requires a superseding ADR and impact review against PRD/NFRs.
