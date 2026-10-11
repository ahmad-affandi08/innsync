# Market readiness (state on 2026-10-07, feature list updated 2026-10-11)

An honest list of what stands between the code and a first paying hotel. It separates what the repository proves, what only a person can decide, and what only a real hotel can prove. Nothing here is a promise; `docs/OPERATIONS/NFR-EVIDENCE.md` holds the measurements.

## Verdict

The software is feature-complete for the scope of the PRD except two tasks that wait on the hotel (below), and it is ready for a **supervised pilot in one or two properties**. It is **not** ready to be sold as a finished product to hotels that will run it alone, because the proofs in section 3 do not exist yet and the commercial items in section 4 are undecided. Selling it before a pilot would be selling an untested claim.

## 1. What the repository proves

- 300 backlog tasks: 298 implemented and awaiting acceptance, 2 blocked on the hotel (section 2). By the project's own rule none is "done" until accepted on real devices.
- The full automated suite, `composer quality` and `npm run quality` (see the release record in `CHANGELOG.md` for the last run).
- Measured: 150 rooms and 5,000 bills in a day; 50 concurrent sessions, 0 errors; first visit under 3 s on Slow 4G; no WCAG 2.1 A/AA violation on 162 pages (`NFR-EVIDENCE.md`).
- First-time setup without a developer: checklist, role set, default approvers, profiles for hotel, small resort and villa, imports from CSV for rooms, staff, suppliers and menu, email and WhatsApp chosen on screen, a System status screen.
- Operations: encrypted daily backup with a recorded restore test, health checks and alerts, deploy and rollback runbooks for shared hosting, offline queue with conflict recording.
- Control: maker-checker on the 11 mandatory subjects, audit of every sensitive change and read, attendance integrity review, role and menu by permission.

## 2. Only the hotel (or the owner) can decide these

| Open item | Why it blocks | Owner |
| --- | --- | --- |
| Tax rate and service charge scheme (PRD Q-05) | Bills and tax reports are wrong until the rates are entered | Finance / HR |
| QRIS provider and settlement bank (Q-04, Q-16) | `FBS-013`; until chosen the cashier records a reference number by hand | Finance / IT |
| Foreign guest report format required by the authority (Q-09) | `FO-041`; not guessed | Front Office Manager |
| Who approves what, with at least two approvers each | Approvals refuse to complete with a single approver | Owner |
| Product **license terms**: `composer.json` now declares `proprietary` (it said `MIT` until 2026-10-08), but the licence agreement itself, support terms and data processing agreement are not written | A hotel cannot be asked to sign what does not exist | Owner and lawyer |
| What to send guests by WhatsApp, and the consent wording (PDP law) | Nothing sends WhatsApp on its own until decided | Owner / legal |
| Face matching for attendance: **built 2026-10-08**, off until switched on | Still needs the agreement wording and privacy notice checked by a lawyer, and the distance calibrated with real staff in real light (`FACE_MAX_DISTANCE`) | Owner and lawyer |

## 3. Only a real hotel can prove these

1. **Staff acceptance test** on real phones: `STAFF-ACCEPTANCE-TEST.md` (10 scenarios) and `CEKLIST-UJI-FITUR.xlsx` (91 items). Nobody has run them.
2. **iPhone Safari**: only a phone-sized Chromium has been tested.
3. **MariaDB on the real plan**: all 131 migrations and the whole automated suite (1,441 tests) pass on MariaDB 10.11.10 (2026-10-08), after the open-bills query and a few MySQL-only test assumptions were fixed. Migrations 132 to 146 were added after that run and have **not** been run on MariaDB (some drop and add CHECK constraints and widen a column, which the two databases word differently): run the suite on MariaDB again before the pilot. Still to rehearse: a full install on the actual hosting plan, whose MariaDB version may differ. Set `DB_COLLATION=utf8mb4_unicode_ci` before the first migrate (see the deployment runbook).
4. **Bad signal in the field**: the offline queue is tested in code, not in a basement kitchen.
5. **Real load on real data** after a month of use (the 50-user run was against an almost empty database).
6. **Email and WhatsApp with live accounts**: each provider's request shape is tested against faked answers; no live account was used.
7. **Recovery rehearsal** on the real plan, scheduled quarterly (`DR-RUNBOOK.md`). The 15-minute recovery point (NFR-11) is **not met** on shared hosting; say so in the contract.

## 4. Commercial and legal items to settle before the first sale

Not code, and not decided here. Each needs the owner, and legal items need a lawyer.

- License and ownership of the code (see section 2).
- Pricing and who pays for hosting, mail and WhatsApp providers.
- Terms of service, a privacy notice for the product as a processor, and a data processing agreement with each hotel (the hotel is the controller of guest data under the PDP law; InnSYnc is the processor).
- What support is promised: channel, hours, response time, and the recovery point honestly stated.
- Who holds the hotel's `APP_KEY` and backups, and what happens to the data when a hotel leaves (export exists; the promise does not).
- Trademark check for the name and logo.

## Features a hotel may ask for that are not built (checked 2026-10-11)

Added since the pass over the PRD of 2026-10-10: installable app (home screen, offline page), bookings from an online travel agent's CSV file, accounting journal export with an account-code mapping, sealed staff bank accounts with a payroll transfer file, payroll for people who joined or left in the month, a do-not-rent flag, e-mail notices for maintenance, key jobs for door locks (generic bridge), the register and the housekeeper's rooms opening from nothing with no network, gift cards and vouchers, a waiting list, travel-agent and channel commissions, fixed assets with depreciation, bank statement matching, loyalty points, spa and activities, day use, SMS, and notices on phones (web push). Each has its own page under `docs/OPERATIONS/` and **none of them has been tried by a real hotel or on real devices**. What remains:

| Missing | Why it is not built |
| --- | --- |
| Two-way channel manager (rates and availability pushed to Booking.com, Agoda, Traveloka; bookings pulled) | Owner decision 2026-10-11: later. Needs a provider's contract and certification; the file import covers one-way bookings. Overbooking control stays with the allowance in `BR-007` |
| Door lock makers' own interfaces (VingCard/Visionline, Onity, SALTO, dormakaba, Häfele; for Indonesia the common ones are VingCard, Onity and Häfele) | The key jobs, the token and the bridge contract are built (`docs/OPERATIONS/DOOR-LOCKS.md`, `tools/door-lock-bridge-example.mjs`); the call into a maker's software is not, because it needs that maker's interface documentation and a lock to try it on. The hotel names the make and the small program beside its lock system is written against it |
| Import into one named accounting package (Accurate, Zahir, Jurnal) | Each has its own format and a licence for its import tool. The general journal CSV with account codes is the common input; a package-specific column order is a small change once the hotel names the package |
| Bank-specific layouts: the bulk transfer file (BCA KlikBisnis, Mandiri Cash Management) and the statement to import | Both are generic CSV; each bank's tool maps the columns. A fixed layout (or MT940) is added when the hotel's bank is known |
| Loyalty tiers and expiry of points, points on food and spa, spa therapists and rooms as resources, spa for guests who are not staying | The first versions are deliberately small (see the "Not decided here" part of each page); each needs a policy from the hotel |
| Words inside a web push, more kinds of push notice, SMS or WhatsApp notices to staff | Push carries no words on purpose (privacy, no library); only approvals and work orders notify so far; staff have no phone number on the account yet |
| Live accounts of SMS providers (Zenziva, Twilio, Vonage) and real push services | Request shapes follow the providers' public documentation and are tested against faked answers; use **Send test** with a live account before relying on them |

## Property limit (licensing)

One purchase is normally one property; a client with several hotels buys several, or agrees otherwise. `INNSYNC_MAX_PROPERTIES` (server environment, default `0` = no limit) enforces the agreed number when a property is created. It is not tamper-proof: whoever controls the server's `.env` can change it. If the agreement must hold against a client that runs its own server, a signed license key checked by the application is needed; that is a design and key-management decision for the owner, not made here.

## 5. Recommended path

1. Owner settles section 2 (a one-hour decision meeting; every item has a named owner).
2. Fix the license; draft the section 4 documents with a lawyer.
3. Install on the real hosting plan, rehearse the install, backup and restore there, and record it.
4. Pilot with one property for 4 weeks: staff run `CEKLIST-UJI-FITUR.xlsx`, the owner reviews the System status and Attendance review screens weekly, and every confusing screen becomes a ticket.
5. Fix what the pilot found, then add a second property. Sell only after two properties have run a full month without help.
