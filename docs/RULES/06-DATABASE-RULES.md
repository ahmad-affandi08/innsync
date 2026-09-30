# Database Rules

- Migration names and constraints are explicit.
- Every FK/index needed by a hot query is part of the same change set.
- Use unique constraints as the final defense for business uniqueness/idempotency.
- Never rely only on "check then insert" for uniqueness.
- Migrations must be production-safe and have rollback/forward-fix strategy.
- Never rename/drop a heavily used column in the same release that all code stops reading it unless the deployment process proves atomic compatibility.
- Seeders are for reference/dev data, not production historical facts.
- `property_id` scope is mandatory on property-owned tables.
