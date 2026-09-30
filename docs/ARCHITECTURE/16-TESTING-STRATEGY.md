# Testing Architecture

## Pyramid

- Domain unit tests: state transitions, money/tax/service-charge rounding, availability, costing, payroll rules.
- Application tests: use cases, transaction boundaries, approvals, idempotency.
- Persistence integration: repositories, locks, unique constraints, outbox.
- HTTP/Inertia feature tests: authorization, validation, response contract.
- Frontend component/unit tests where interaction complexity warrants them.
- E2E/UAT: critical hotel workflows.
- Security, performance, backup/restore, offline/sync test suites for NFR gates.

Every bug that violates an invariant must produce a regression test before the fix is considered complete.
