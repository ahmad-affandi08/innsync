# First-Time Setup of a Property

Traceability: owner instruction of 2026-10-07 ("the setup is still very hard"), `NFR-06`, `BR-004`; related: `ACCESS-ADMINISTRATION.md`, `DEPLOYMENT-RUNBOOK.md`, `docs/TASK/DATA-MIGRATION-CUTOVER.md`. This document records what the application does so a hotel can start with little work; it is not a PRD edit.

## The checklist: Property settings menu → First-time setup (`/setup`)

One ordered list with the status of every step, read from the data (so it is always current), each step opening the screen where it is done. It needs the permission `property.settings.manage`. Code: `app/Shared/Application/Setup/SetupChecklist.php` (the order and the rules), `app/Shared/Infrastructure/Setup/DatabaseSetupFacts.php` (the counts).

Required steps (they count towards progress), in order, after the optional first step "How the property works": check-in and check-out times; the business date (go live); room types and rooms; rate plans and prices; service charge and tax; roles; people (more than the administrator); an approver for every mandatory action.

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

## Staff, suppliers and menu from a file

Staff (HR → Staff), Suppliers (Inventory → Suppliers) and the menu (F&B → Menu) each have **Import from file**. Download the example file, fill it in a spreadsheet, save as CSV (comma, semicolon or tab). **Check file** changes nothing and lists every bad row by line; **Import now** is all or nothing. Rows pass the same rules as the form: a department that does not exist, a contract without an end date, a duplicate supplier code are all refused with their line. The menu file names the outlet and category by code, so create those first; the price is typed in whole currency units (`25000`), and a grouped number such as `1.500` is refused rather than guessed. At most 300 rows per file.

## The hotel's own logo

Property settings → **Logo**. Upload a wide logo on a transparent or white background (PNG, JPEG, WebP or SVG up to 512 KB). It replaces the InnSYnc logo in the header and prints at the top of every bill, receipt, pay slip and report. Remove it to go back to the InnSYnc logo. A small "Powered by InnSYnc" line stays at the bottom of pages and printed documents; switch it off on the same screen if the hotel prefers. The logo also shows on the sign-in page (when the installation has one property) and on the guest pages.

## Booking from the hotel's own web page

Property settings → **Online booking**. Off until the hotel switches it on. Choose the rate plan guests book at (their prices come from it), the most nights in one booking, an email for new requests and a short message for the guest, then switch it on. The page shows the address (`/book/<property>`): put it on a button or link on the hotel's website or send it to guests. A guest picks dates and a room, sees the full price with tax and service charge, and sends a request. It becomes a **tentative** reservation made by an account called "Online booking"; staff confirm it from Front Office → Reservations (the bell shows how many wait). Nothing is charged online: the guest pays at the hotel. A request never takes a room beyond what is free, and the privacy notice agreed to is recorded with its version. The page limits how fast one address can send requests, has a hidden field only programs fill, and refuses a person who already has three requests waiting. Not included: online payment and a link to Traveloka or other agencies.

## Guests who paid an online travel agency

When a guest already paid the agency (for example in the Traveloka app), the cashier must not charge them for the room. Create the agency under Front Office → Companies with the type **Travel agent (including OTA)** and let it take the rooms. On a reservation that came from an agency, the reservation page says so and offers the agency first under "Bill to"; the room charge then goes to the agency's folio and becomes a receivable at check-out. When the agency pays, record it in Finance → Receivables; the commission the agency keeps is entered there as a credit note, so the receivable closes exactly.

## A forgotten password

The sign-in page has **Forgot your password**. It needs the hosting mail settings (the System status screen says whether email will be delivered). The link works once and lasts 60 minutes; the person's other sessions end when it is used. An administrator can still reset a password from People & access.

## A small resort or a villa

A property says how it works in the first step of the checklist, **How the property works** (`PUT /property/profile`, needs `property.settings.manage`, a reason, a recent password confirmation; audited as `property.profile.changed`). Code: `PropertyProfileService`, `app/Shared/Application/Setup/PropertyProfiles.php`.

| Profile | Departments off at the start | Roles |
|---|---|---|
| Hotel | none | the 19 starting roles |
| Small resort | stock and purchasing, HR and payroll | 4 roles for a small team: Resort Manager, Front Desk & Cashier, Housekeeping Team, Kitchen & Bar |
| Villa | laundry, restaurant and bar, kitchen, maintenance, HR, stock | the same 4 roles |

The owner can switch any of the seven optional departments (laundry, restaurant and bar, kitchen, maintenance, HR, stock and purchasing, finance) on or off whatever the profile. Front office, housekeeping, reports and property settings are always on.

- **Only the menu changes.** A department that is off is left out of the menu and has no step in the checklist. Its screens still open by address, no data is removed and no rule is relaxed; switching it on again shows it. (Blocking the screens on the server was considered and not done: it touches most routes and gives no safety the permissions do not already give.)
- **Roles for a small team.** The four roles are created when a small profile is chosen. The starting hotel roles that nobody holds and nobody changed are switched off (not deleted), so the team is not offered nineteen roles; a role somebody holds or changed stays. Choosing Hotel again creates the missing hotel roles but does not switch the others back on; do that on the Roles screen.
- **No change to maker-checker (BR-004).** A person never approves their own request, in a small property too. With two people who can approve (the owner, who has every permission, and the Resort Manager) each approves the other's requests. The checklist shows an approval step as not done while any mandatory action has only one person who can approve, and says so. If the owner wants to approve their own requests, that is a change to BR-004 and needs a Change Request.
- **Open:** whether a small property may run the night audit in a simpler way is not decided; the night audit is unchanged.

## Not guessed

Tax and service charge rates (PRD `Q-05`, Finance), who approves what (the owner), the layout of the foreign-guest report (`Q-09`) and the payment provider (`Q-04`, `Q-16`) are never filled in by the application. The checklist only says they are missing.
