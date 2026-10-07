# First-Time Setup of a Property

Traceability: owner instruction of 2026-10-07 ("the setup is still very hard"), `NFR-06`, `BR-004`; related: `ACCESS-ADMINISTRATION.md`, `DEPLOYMENT-RUNBOOK.md`, `docs/TASK/DATA-MIGRATION-CUTOVER.md`. This document records what the application does so a hotel can start with little work; it is not a PRD edit.

## The checklist: Property settings menu → First-time setup (`/setup`)

One ordered list with the status of every step, read from the data (so it is always current), each step opening the screen where it is done. It needs the permission `property.settings.manage`. Code: `app/Shared/Application/Setup/SetupChecklist.php` (the order and the rules), `app/Shared/Infrastructure/Setup/DatabaseSetupFacts.php` (the counts).

Required steps (they count towards progress), in order: check-in and check-out times; the business date (go live); room types and rooms; rate plans and prices; service charge and tax; roles; people (more than the administrator); an approver for every mandatory action.

Listed but not required: deposit and cancellation rules; restaurant and bar (outlets and menu); laundry prices; housekeeping checklists; stock items and suppliers; employees; expense accounts. A hotel that does not use a department ignores it.

## What is already done for a new property

| Item | How | Where to change it |
|---|---|---|
| 19 starting roles | `innsync:install-default-roles`, migration 119, `innsync:create-admin` | Roles screen |
| A starting approver for each of the 11 mandatory actions | `innsync:install-default-approvals`, migration 120, `innsync:create-admin` | Approval policies screen |
| The administrator account | `innsync:create-admin` (once, on the server) | People & access |

### Starting approvers

Without a policy, a mandatory action (void, comp or refund of an F&B item or bill, folio reversal or refund, laundry left at check-out, attendance correction, leave, payroll run, service charge distribution) is refused, never allowed. A new property would meet "not allowed" with no explanation. Each now has one level and one approval, from any person holding the manager permission named in `app/Modules/IdentityAccess/Application/Access/DefaultApprovalPolicies.php`; the maker never approves their own request (BR-004).

| Action | Approver holds |
|---|---|
| Folio reversal, laundry left at check-out | `front-office.folio.correct` |
| Folio refund | `front-office.folio.refund` |
| F&B item void, bill cancel, comp, refund | `fnb.refund.apply` |
| Attendance correction | `hr.attendance.manage` |
| Leave | `hr.leave.manage` |
| Payroll run | `finance.payroll.verify` |
| Service charge distribution | `hr.service-charge.manage` |

These are a first proposal; the owner decides who approves what and changes it on the Approval policies screen. A property that already has a policy for an action keeps it. The policies are recorded under the property's administrator (the oldest active person with the Administrator role); a property without one gets them when `innsync:create-admin` creates it. With a single manager who holds the permission, that manager cannot approve their own request: add a second person or change the policy.

The service charge distribution is approved by the General Manager in FR-HR-033; the starting approver is anyone with `hr.service-charge.manage`, which the HR Manager also holds. **Open:** whether only the General Manager may approve it.

## Rooms from a file (Property settings → Rooms → Import from file)

Two CSV files (room types, then rooms; templates can be downloaded on the screen). The screen first **checks** the files and changes nothing, listing every bad row with its file and line; only then **imports** all rows or none. The same files are not applied twice. It is the same service as `php artisan import:room-master`, so the same rules, permission (`property.catalog.manage`), audit entries and password confirmation apply. Up to 2000 rows.

## Several accounts at once (People & access → Add several)

Paste one person per line (`Name, email`, or a copy from a spreadsheet); all get the chosen role and scope; all are created or none (a repeated or existing email names its row). Each gets a temporary password shown once on a list that can be copied; each person must choose their own at the first sign-in. At most 50 at a time.

## Not guessed

Tax and service charge rates (PRD `Q-05`, Finance), who approves what (the owner), the layout of the foreign-guest report (`Q-09`) and the payment provider (`Q-04`, `Q-16`) are never filled in by the application. The checklist only says they are missing.
