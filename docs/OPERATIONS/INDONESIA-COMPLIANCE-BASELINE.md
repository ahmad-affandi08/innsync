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

## Money, tax and rounding (PRD `Q-05`, `Q-13`; `BR-002`, ADR-0006) — baseline for Phase 1

Applied by the owner's instruction to follow Indonesian business practice. These are engineering defaults stored as **property configuration** (effective-dated, snapshotted on every posting), not constants in code.

- **Currency and minor unit.** IDR follows ISO 4217 with two minor digits, so the system stores sen (`150000` is Rp 1.500). In practice rupiah has no coins: amounts are whole rupiah and are shown without decimals (`Rp 1.500`); a stray sen would still be shown.
- **Rounding.** Every computed line (service charge, tax, discount, proration) is rounded **half away from zero to the whole rupiah** (increment 100 sen) at the line, never on the total. A reversal or correction is always the exact negative of the original. The increment and mode are property settings.
- **Order of calculation.** Service charge on the base price; regional tax on **base plus service charge** (the usual practice for hotel and restaurant tax under regional regulations; configurable per outlet because regional regulations differ). Rates are exact basis points, not floats.
- **Price display.** Both "++" (service charge and tax added) and "nett" (included) prices are supported per rate plan or outlet. For a nett price the base is derived from the total, the service charge from the base, and the **tax line absorbs the rounding difference**, so the parts always add up to the quoted total.
- **Rates are data.** The regional tax rate (PB1) is set by each regional regulation under UU 1/2022 (HKPD), with a statutory ceiling of 10 percent (confirm), and the service-charge scheme is a property decision; both are configured per property or outlet with an effective date, never hard-coded. Service charge is recorded separately from property revenue and distributed under the labour regulations and internal policy (`FR-HR`, `FR-FIN` tasks); the allocation formula (`Q-06`) stays open.

## Business date and night audit (PRD `Q-11`; `BR-001`)

- The business date is stored per property and **advances only through night audit**, never from the clock. It may lag the calendar date until the audit completes (an audit run at 01:00 closes the previous business date).
- Night audit may be started from a configurable earliest local time, default **23:00**, and has no latest time; the clock never starts it. The choice follows the common Indonesian hotel practice of auditing around midnight; the General Manager confirms or changes it per property.
- Check-in and check-out standard times default to **14:00** and **12:00** and are property settings.

## Booking policy (deposit, guarantee, cancellation, no-show) — suggested starting point (`FR-FO-009`)

Booking policy is a commercial decision of the hotel, not a legal one; Indonesian hotels commonly sell on these terms. The system **assumes nothing**: with no policy defined there is no deposit and no fee. The owner enters the policy on the booking policy screen (`/property/policies`), per rate plan and booking source, effective-dated and versioned; a reservation keeps the policy it was given. A suggested starting point to confirm or change:

- **Guarantee and deposit.** A guaranteed booking holds a deposit of the **first night** (including service charge and tax, because it is money received), due when the booking is made or a few days before arrival. A corporate booking with a letter of guarantee can be guaranteed without a deposit by a person with the override privilege and a reason.
- **Free cancellation.** Until **1 to 3 days before arrival** (so a cancellation on the day before is already late); a longer window for peak periods or groups is a separate policy for that rate plan.
- **Late cancellation and no-show.** A fee of **the first night** for a late cancellation and **the first night or all nights** for a no-show, on the room price **before** service charge and tax. Whether a fee carries tax or service charge is a Finance and tax-advisor decision (`Q-13`): the system adds none.
- **Waiving.** A person with the waive privilege may waive a fee with the reason recorded (force majeure, regular guests).
- OTA bookings usually follow the channel's own terms: define a policy for the `ota` source rather than the hotel's own.

## Not decided here

- The format of the foreign-guest report (`Q-09`) and whether electronic registration is accepted as the official procedure (`Q-17`) depend on the local authority and need a Front Office answer.
- The service-charge allocation formula (`Q-06`), accounting software (`Q-07`), door locks (`Q-08`), laundry pricing (`Q-10`), rate plans required at go-live (`Q-12`), payment gateway (`Q-04`, `Q-16`) and accounting mapping (`Q-18`) are business choices, not legal ones, and stay open until their tasks start.

## Tax and service charge obligations (FR-DSH-013, FR-DSH-014) — baseline for Phase 1

- **Reporting day.** The regional hotel tax is reported by the **15th of the following month** (operational choice; regional regulations differ and some use the 10th). A property setting from 1 to 28; the owner and the tax consultant confirm it.
- **Employees' share of the service charge.** An estimate of **60 percent** (operational choice; the allocation formula is PRD `Q-06` and stays open). A property setting from 0 to 100 percent. The system distributes nothing.
- **What is counted.** Tax and service charge on the charges posted on the business dates of the month, net of reversals, split by where they came from. A filing is only a record of who reported the month, when and under which reference.

## Lost and found (FR-HK-012) — baseline for Phase 1

- **Retention of the photo.** The photo of a found item is **erased 90 days after the item is closed** (returned or disposed): category `lost_found_photo`, anchor `closed_at`, adjustable from 0 to 365 days (operational choice; the photo may show personal belongings, so it is not kept longer than the item). The record itself (who found it, when, where, to whom it was returned) stays with the audit trail.
- **Time stored.** An item still stored after **90 days** is flagged for a decision (operational choice). Nothing is disposed of automatically.

## Checklist proof photos (FR-HK-006) — baseline for Phase 1

- **Retention.** A proof photo of a checklist item is **erased 90 days after the business date it was taken** (category `checklist_photo`, anchor `completed_at`, adjustable from 0 to 365 days; operational choice). The completion record (item, who, when, note) stays with the audit trail.

## Company and agent billing (FR-FO-035) — baseline for Phase 1

- **Credit limit.** The limit is a warning, not a block: a night is always charged by night audit and the hotel decides whether to keep a guest. Practice in Indonesian hotels is to agree the limit and the payment term (often 14 or 30 days) with the company in writing; **payment terms and ageing are not modelled** and are for the owner to confirm.
- **Invoice.** A company usually needs an invoice with its tax ID (NPWP) and, for a taxable company, a tax invoice (e-Faktur). The tax ID is kept on the profile; **numbering and issuing invoices or e-Faktur are not done here** and need counsel and the tax consultant to confirm.
