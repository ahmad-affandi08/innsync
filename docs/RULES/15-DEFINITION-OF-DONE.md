# Definition of Done

A requirement/task is DONE only when:

1. PRD acceptance behavior is implemented without violating cross-module invariants.
2. Authorization and property scope are enforced.
3. Validation covers transport and domain invariants.
4. Transaction, idempotency, concurrency, audit, and history behavior are correct for the risk level.
5. Loading/empty/error/conflict/permission UI states exist where relevant.
6. Automated tests cover happy path, negative path, and critical edge cases.
7. Static analysis, format, typecheck, frontend build, and test suites pass.
8. Database indexes/migrations are reviewed and deployable.
9. Logs/observability avoid PII and expose actionable failures.
10. Documentation/task traceability and release notes are updated.
11. No Critical/Blocker defect remains; High risk has written owner decision.
12. A Wajib FR has UAT evidence before its release gate is passed.
