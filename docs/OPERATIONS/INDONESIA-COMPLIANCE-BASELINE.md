# Indonesia Compliance Baseline (draft for owner and counsel review)

Status: **owner-delegated baseline, not legal advice.** On 2026-10-01 the product owner instructed that open policy questions be resolved "according to Indonesian business regulations". This document records what that means for the engineering defaults. It does not edit the PRD or any accepted ADR: the PRD asks for **configurable** retention, so these are the shipped defaults and floors in `config/retention.php`. Each property can lengthen a period (and shorten it only where no floor applies); the floor of a statutory category cannot be lowered in the application.

The legal references below come from the author's knowledge of Indonesian law and could not be checked against the official texts from the build environment (no access to peraturan.go.id). A lawyer or the property's tax consultant must confirm every row marked "confirm" before go-live. Where no statute with a number could be named, the row says **operational choice** instead of citing one.

## Retention (PRD `Q-15`, `NFR-07`, `NFR-08`, `NFR-29`)

| Category | Default | Floor / ceiling | Basis | Confidence |
| --- | --- | --- | --- | --- |
| `financial_record` (invoices, folios, payments, refunds, tax documents) | 10 years (3650 days) | floor 10 years | UU 8/1997 on Corporate Documents (financial records and their supporting documents, 10 years); UU 6/1983 on General Tax Provisions and Procedures, art. 28(11) as amended (books, records and supporting documents, 10 years) | statutory, confirm |
| `payroll_record` | 10 years | floor 10 years | same, as supporting documents of withholding tax (PPh 21) and company accounts | statutory, confirm |
| `audit_trail` | 10 years | floor 10 years | audit entries are the change history of financial records, so they follow them; `NFR-10` forbids deleting them | operational choice following the statutory period |
| `approval_evidence` | 10 years | floor 10 years | same reasoning (`BR-004`, `NFR-29`) | operational choice following the statutory period |
| `security_event` | 5 years (1825 days) | floor 1 year | no statutory period was identified; UU 27/2022 (PDP) accountability and incident investigation | operational choice, confirm |
| `guest_identity_document` (ID photo, passport scan) | 90 days after check-out | ceiling 1 year | UU 27/2022 (PDP) purpose and storage limitation: keep only while needed for registration, foreign-guest reporting to the authorities and disputes. The registration data that supports an invoice stays inside `financial_record` | operational choice, confirm |
| `hr_personnel_document` | 5 years after employment ends | floor none, ceiling 10 years | UU 27/2022 (PDP) storage limitation; payroll data is kept separately for 10 years | operational choice, confirm |
| `sensitive_export_file` | 1 day | ceiling 7 days | `NFR-24` expiry; UU 27/2022 (PDP) security of processing | operational choice |

Mechanics: the audit and security-event writers stamp `minimum_retention_until` from these defaults (`config/evidence.php`). Immutable evidence is never deleted by the application. A stored file past its `expires_at` has its encrypted blob erased and a tombstone recorded; the metadata row stays as proof that the file existed and was erased (`NFR-10`). A **legal hold** (a dispute, an audit, a police or court request) blocks erasure until released.

Backups: `NFR-11` backups contain data that was already erased from the live system. Erased personal data therefore disappears from backups only when the backup set is rotated out. The runbook recommends keeping daily sets for 35 days (`BACKUP_KEEP_LAST=35` with the daily schedule), which is an operational choice, and the privacy runbook tells staff to tell a data subject that deletion in backups completes within that window.

## Data subject rights and incidents (UU 27/2022 on Personal Data Protection)

| Request | Internal target | Reference | Confidence |
| --- | --- | --- | --- |
| Correction | 24 hours | art. 30 | confirm |
| Access / copy | 72 hours | art. 32 | confirm |
| Withdraw consent | 72 hours | art. 40 | confirm |
| Delete / end processing | 72 hours | art. 43 (no implementing regulation was final when this was written) | confirm |
| Object to automated decision | 72 hours | art. 10 | confirm |
| Breach notification to the data subject and the authority | 72 hours from discovery | art. 46 | confirm |

The register records when a request was received and when it is due and what was decided. A deletion that conflicts with a statutory retention floor is **refused with the legal basis recorded** (the data is restricted and kept for the statutory period), never silently ignored. The data protection authority (Lembaga PDP) had not been established when this was written; the runbook says to record the notification channel once it exists.

## Not decided here

- Tax rates and rounding (`Q-05`, `Q-13`): hotel and restaurant tax (PB1) is a regional tax set by each regional regulation under UU 1/2022 (the statutory ceiling is 10 percent); the rate and the service-charge scheme are property data, not constants in code. Rounding rules are decided when the Finance tasks start and are recorded in the same way.
- The format of the foreign-guest report (`Q-09`) and whether electronic registration is accepted as the official procedure (`Q-17`) depend on the local authority and need a Front Office answer.
- Business-date cut-off (`Q-11`) is an operating decision, not a legal one.
