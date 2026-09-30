# Non-Negotiables

1. Never violate a PRD invariant or Wajib requirement to make implementation easier.
2. Never put business rules in controllers, React components, Eloquent observers, migrations, Blade/Inertia page props, or database triggers as hidden behavior.
3. Never let Domain depend on Laravel.
4. Never mutate another bounded context's tables directly.
5. Never use floating point for money.
6. Never hard-delete or silently overwrite financial/stock/audit history.
7. Never process retryable critical operations without idempotency.
8. Never query property-owned data without property scope.
9. Never trust frontend authorization.
10. Never log secrets, passwords, tokens, card data, full identity documents, or excessive PII.
11. Never guess unresolved hotel policy.
12. Never introduce a permanent-daemon dependency while shared hosting is the active deployment profile.
13. Never mark a task DONE without tests/evidence required by Definition of Done.
