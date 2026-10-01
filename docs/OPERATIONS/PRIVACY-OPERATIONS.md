# Privacy Operations

Procedures for personal data under UU 27/2022 (Personal Data Protection) and the retention periods in `docs/OPERATIONS/INDONESIA-COMPLIANCE-BASELINE.md` (a draft that counsel must confirm). Mechanisms: `TASK-FND-019`.

## Roles and permissions

| Permission | Holder (suggested) | Allows |
| --- | --- | --- |
| `privacy.retention.manage` | General Manager, data protection officer | Change a retention period inside its bounds (a statutory minimum cannot be lowered) |
| `privacy.legal-hold.manage` | General Manager, owner | Place and release a legal hold |
| `privacy.request.manage` | Front Office Manager, HR Manager, data protection officer | Receive, handle and decide data subject requests |

No role or permission is seeded; the owner assigns them per property.

## Data subject requests

1. A person asks to see, correct, delete or stop using their data, or to withdraw consent or object. Staff records the request in the register the same day (`DataSubjectRequests::open`). Record **how the person was identified** (for example "ID shown at the front desk"); do not release anything to someone who has not been identified.
2. The register sets the response target from the request type (correction 24 hours; access, deletion, withdrawal and objection 72 hours). Start the request when someone takes it.
3. The module that owns the data carries it out. Complete the request with a note of what was done.
4. A deletion that conflicts with a statutory period (invoices, folios, payroll: 10 years) is **refused with the legal basis recorded**; tell the person which data is kept and why, and which data was removed. Do not delete financial records to satisfy a request.
5. The `privacy_requests` health check turns `down` when a request is past its target; treat it as a missed legal deadline and escalate the same day.

## Retention and erasure

- Owners set `expires_at` on a stored file from `RetentionPolicies::expiryFor` (for example the check-out date plus the property's `guest_identity_document` period).
- `retention:purge` runs daily after the backup. It erases the encrypted blob, keeps the metadata row as a tombstone (without the file name) and writes an audit entry. Check the command output (`erased`, `held`, `failed`); a non-zero exit code means at least one property failed and is retried the next day.
- Erased data remains in backup sets until they rotate out (35 daily sets by default). Tell a person whose data was deleted that deletion in backups completes within that window.
- Audit entries, security events and approval evidence are immutable and are not deleted by the application. Their minimum retention is stamped on each row; a purge of evidence past its minimum is a deliberate future decision and is not automated.

## Legal holds

Place a hold when a dispute, a tax or other audit, or a police or court request needs data that would otherwise be erased. A hold covers the whole property, one purpose (for example `guest.dispute`) or one owner record. Erasure of covered files is skipped (counted as `held`) until the hold is released with a reason. Holds are never deleted.

## Exports of personal data

Use `IssueSensitiveExport`. The file is private and encrypted, belongs to the person who issued it, needs a reason, and expires by the property's `sensitive_export_file` retention (1 day by default, at most 7). Every download is audited. Never email an export or put it on a public link.

## Personal data breach

UU 27/2022 art. 46 requires written notification to the data subjects and to the authority within 72 hours of discovering a failure of personal data protection (confirm with counsel). Until the data protection authority exists, record the channel counsel names.

1. Contain: revoke the sessions or credentials involved (`/account/sessions`), rotate secrets (NFR-23), and place a legal hold on the affected data.
2. Record the time of discovery, what data and which people are affected, and the correlation IDs from the audit trail and security events.
3. Within 72 hours: notify the affected people and the authority through counsel, stating what happened, which data, the likely consequences and what was done.
4. Afterwards: restore from a verified backup if needed (`docs/OPERATIONS/DR-RUNBOOK.md`) and file the incident record with the owner.

## Open points for owner and counsel

- Confirm every "confirm" row in the baseline document, especially the guest identity document period and the personnel file period.
- Name the data protection officer (UU 27/2022 requires one in some cases) and the notification channel to the authority.
- Provide the privacy notice text and its version so `ConsentLedger` can reference it.
