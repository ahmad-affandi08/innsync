# Phase 1 — Core Operations (Months 1–4)

Primary scope from PRD: master data/users, Front Office, Housekeeping, Laundry, room board, early Dashboard, guest/room-revenue reporting.

## Mandatory implementation order

1. Foundation + identity/property configuration.
2. Room/type/rate master and business date.
3. Reservation/availability and room state dimensions.
4. Check-in/stay/folio/deposit/payment foundation.
5. Housekeeping assignment/status/inspection.
6. Guest laundry and folio posting.
7. Night audit and daily controls.
8. Dashboard read models and Front Office reports.
9. Phase UAT, migration dry run, cutover rehearsal.

Gate: all Wajib FRs selected for the phase pass UAT and end-to-end reservation → check-in → ancillary charge → payment → checkout → night audit reconciles.

## Phase 1 progress log

Policy decisions this phase relies on are recorded in `docs/OPERATIONS/INDONESIA-COMPLIANCE-BASELINE.md` (money, rounding, tax order, business date, standard times), taken under the owner's instruction to follow Indonesian business practice. Each entry below says what is verified and what is not.

### Step 1–2 groundwork: property configuration, money types, business date (2026-10-01)

- Status: groundwork for `TASK-FO-007` (inventory per room type) and `TASK-FO-008` (rate plans). Neither task is DONE: availability, overbooking and rate plans are not built yet. This entry changes no FR status.
- Traceability: `BR-001`, `BR-002`, `BR-003`, `BR-006`, `BR-008`, `NFR-16`, `NFR-19`, `NFR-26`, `ADR-0006`; `docs/ARCHITECTURE/03` (Property Configuration context).
- Money (`App\Shared\Domain\Money`): `Money` (integer minor units, exact arithmetic, overflow refused, mixed currencies refused, largest-remainder `allocate` for split bills), `Percentage` (basis points parsed from text without floats), `RoundingRule` (increment and mode; half-up, half-even, down, up; symmetric around zero so a reversal is the exact negative), `ChargeScheme` and `ChargeBreakdown` (service charge then regional tax, tax on base only or on base plus service charge, "++" or nett prices; the parts always add up to the total, a nett price must be a whole rounding unit). 29 unit tests, including a seeded 3000-case property test over rates, modes, increments and both price styles.
- Property settings (`property_settings`, `PropertySettingsService`): standard check-in 14:00, check-out 12:00, earliest night audit 23:00, whole-rupiah half-up rounding, 365-day availability horizon, all changeable with a reason, audit entry and optimistic locking; the business date is null until go-live, is set once by an authorized person, and afterwards a database trigger allows it only to move forward (never back, never cleared). `BusinessDateProvider` is the port other contexts use; nothing derives it from the clock. Changing settings needs a recent password confirmation.
- Room master (`room_types`, `rooms`, `RoomCatalogService`): types with a unique code and occupancy, rooms with a unique number per property, never deleted (triggers) but deactivated; a type with active rooms cannot be deactivated, an inactive type cannot receive rooms; every change needs `property.catalog.manage` and a reason and is audited; viewers need `property.catalog.view`; `RoomCatalogReader` is the read-only port for other contexts. Property scope and cross-property type use are refused.
- UI: `/property/rooms` (types and rooms, create, edit, activate, deactivate with consequence dialog and reason) and `/property/settings` (times, rounding, horizon, one-time business date), English and Indonesian, standard error and conflict states, and a generic `useServerAction` hook plus `/reconfirm` (same-site return only) for a lapsed password confirmation.
- Automated evidence: PHPUnit 435 tests (1 skipped, pre-existing) and `npm test` 87 tests pass; new `PropertyConfigurationTest` has 15 MySQL feature tests (permissions, validation with field errors, duplicates, cross-property isolation, deactivation rules, stale edits as 409, undeletable rows, defaults, business date once and forward-only at the database, reconfirm redirect safety). Browser evidence in real Chromium against MySQL: empty states, validation, creating a type and a room, a duplicate number refused inline, deactivation refused while a room is active, setting the business date, saving settings, the Indonesian UI; no unexpected console errors (the 4xx responses are the refusals under test).
- Not included: night audit (`TASK-FO-028`), tax and service-charge configuration per outlet with effective dates (comes with the first charge posting), room status dimensions (Housekeeping/Front Office), and moving a room to another type while reservations exist (decided with reservations).
