# Market readiness (state on 2026-10-07)

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
| Product **license**: `composer.json` says `MIT`, which lets anyone copy and resell it | Must be proprietary before anything is sold | Owner |
| What to send guests by WhatsApp, and the consent wording (PDP law) | Nothing sends WhatsApp on its own until decided | Owner / legal |
| Whether face matching for attendance is wanted | Needs employee consent and a retention rule (specific personal data, PDP law) | Owner |

## 3. Only a real hotel can prove these

1. **Staff acceptance test** on real phones: `STAFF-ACCEPTANCE-TEST.md` (10 scenarios) and `CEKLIST-UJI-FITUR.xlsx` (91 items). Nobody has run them.
2. **iPhone Safari**: only a phone-sized Chromium has been tested.
3. **MariaDB**: Niagahoster plans often run MariaDB; migrations 114 and later are tested on MySQL 8 only. Rehearse a full install on the target plan before the pilot.
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

## Property limit (licensing)

One purchase is normally one property; a client with several hotels buys several, or agrees otherwise. `INNSYNC_MAX_PROPERTIES` (server environment, default `0` = no limit) enforces the agreed number when a property is created. It is not tamper-proof: whoever controls the server's `.env` can change it. If the agreement must hold against a client that runs its own server, a signed license key checked by the application is needed; that is a design and key-management decision for the owner, not made here.

## 5. Recommended path

1. Owner settles section 2 (a one-hour decision meeting; every item has a named owner).
2. Fix the license; draft the section 4 documents with a lawyer.
3. Install on the real hosting plan, rehearse the install, backup and restore there, and record it.
4. Pilot with one property for 4 weeks: staff run `CEKLIST-UJI-FITUR.xlsx`, the owner reviews the System status and Attendance review screens weekly, and every confusing screen becomes a ticket.
5. Fix what the pilot found, then add a second property. Sell only after two properties have run a full month without help.
