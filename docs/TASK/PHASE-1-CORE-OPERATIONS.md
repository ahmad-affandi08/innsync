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
