# Observability and Audit

Maintain separate concepts:

- **Application log:** operational diagnostics; no secrets or excessive PII.
- **Security log:** authentication, privilege and suspicious access events.
- **Audit trail:** immutable business evidence of changes/approvals.
- **Correlation ID:** follows one request/job/integration chain.

Minimum health signals: payment unknown, sync backlog, queue failures, exception rate, failed login spikes, backup failure, storage/database capacity, night audit failures, and external provider degradation.

Audit entries identify actor, action, aggregate, property, before/after where appropriate, reason, time, correlation ID, and approval reference. Users cannot delete audit records through the product UI.
