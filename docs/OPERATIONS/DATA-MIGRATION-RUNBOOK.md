# Data migration and cutover runbook (Phase 1)

Status: **procedure and tooling for the room master only.** The other data classes in `docs/TASK/DATA-MIGRATION-CUTOVER.md` (rate plans and prices, tax and service-charge schemes, laundry prices, users and roles, and opening reservations, in-house guests and folio balances) have no import tool yet; for them the owners must either enter the data through the screens (each change is audited) or ask for a tool before the freeze window. Nothing here has been run against the real Niagahoster plan or real source data: the figures below come from the sample files in `docs/OPERATIONS/samples/` on a development database.

No migration is successful because rows were imported. It is successful when the **control totals** reconcile with the figures the data owners signed off and a business sample was checked by a person.

## What the tool does (`php artisan import:room-master`)

```
php artisan import:room-master <property-id> <user-id> room_types.csv rooms.csv [--dry-run] [--expect-types=N] [--expect-rooms=N]
```

- Files: `room_types.csv` with the header `code,name,max_adults,max_children,sort_order` and `rooms.csv` with `number,type_code,floor` (UTF-8, comma separated; a byte order mark is accepted; at most 2,000 rows each; the samples are in `docs/OPERATIONS/samples/`).
- Every row goes through the same service a person uses (`RoomCatalogService`), so the same rules, the permission `property.catalog.manage` of the named user and the audit entries apply; each created record carries the reason `Cutover import <batch id>`.
- `--dry-run` runs exactly that path and rolls it back, so it cannot approve a row the real run would refuse. Nothing is kept except one row in `import_batches` (status `validated` or `rejected`) as evidence of the rehearsal.
- A run is all or nothing. A file that cannot be read at all (wrong header, empty, too many rows) stops it; a bad row does not, so one pass lists every problem with its file and line.
- Control totals: number of room types, number of rooms and rooms per type, plus a fingerprint (SHA-256) of the two files. `--expect-types` and `--expect-rooms` are the signed-off figures; a difference makes the run `rejected` and keeps nothing.
- The same two files cannot be applied twice to a property (the fingerprint of an applied batch is checked), so a retried step during the cutover is safe.
- Exit code 0 means `validated` or `applied`; anything else is non-zero. The report is printed as JSON.
- `import_batches` is append-only (update and delete are refused by triggers).

## Rehearsal log (development database, sample files)

| Run | Command | Result |
| --- | --- | --- |
| Dry run 1 | `--dry-run --expect-types=3 --expect-rooms=5` | `validated`: 3 types, 5 rooms (STD 2, DLX 2, STE 1); expectations ok; nothing created |
| Dry run 2 | `--dry-run --expect-types=3 --expect-rooms=6` (a wrong sign-off figure) | `rejected`: rooms expected 6, actual 5 |
| Real run | `--expect-types=3 --expect-rooms=5` | `applied`: 3 types and 5 rooms; 8 audit entries with the batch reason |
| Repeat | same files | `rejected`: "These files were already applied to this property."; nothing changed |

These are rehearsals of the tool, not Dry Run 1 and Dry Run 2 of the hotel's own data; those need the hotel's real files and a sign-off by the data owners.

## Cutover procedure (checklist)

1. **Owners and scope.** Name an owner for each data class; decide which transactions are opened at go-live (this product assumes the opening state is only the room master, rates, tax and service-charge schemes, prices and users, with no historic folios; in-house guests at the moment of cutover are re-entered as new reservations and checked in, with their balance posted as an opening charge or deposit by an authorized person and a reason).
2. **Prepare files** from the source system; keep the originals and their SHA-256 in the cutover record.
3. **Dry run 1 and dry run 2** on a copy of production data (or an empty production database), fix the source files until a dry run is `validated`, and compare the control totals with the source system's own counts. Record both runs.
4. **Sign-off.** The owners sign the control totals (types, rooms, rooms per type) and a business sample (for example five rooms checked by the front office manager).
5. **Freeze window.** Stop entry in the old system; list open transactions; take the production backup (`php artisan backup:run`, then `backup:verify`) and note its set name. Confirm free disk space and that the preflight passes (`php artisan deploy:preflight`).
6. **Cut over.** Run the real import with the signed-off `--expect-*` figures. Then set the **business date** once (`/property/settings`, one-time go-live step), configure the tax and service-charge schemes (rooms and laundry) effective that date, the rate plans and prices, the laundry price list, the users, roles and approval policies.
7. **Smoke test the critical paths** with a test reservation that is cancelled afterwards: availability, a reservation, check-in, a charge and payment, check-out, laundry hand-over, the room board, a night audit on a closed test day is **not** run before go-live (it advances the business date and cannot be undone); use the rehearsal environment for that.
8. **Reconcile** the control totals again on production (`rooms`, `room_types` counts and the batch row), sign the cutover record, and start hypercare (daily review of the exceptions on the dashboard, the audit trail and the health checks).

## Rollback

- **Trigger:** a control total that does not reconcile, a failed smoke test of a critical path, or a data owner who refuses the sign-off, before the first real transaction.
- **Before go-live (no real transactions yet):** restore the backup taken in step 5 using the documented restore procedure (`docs/OPERATIONS`, backup and restore runbook), or discard the empty production database and redeploy. The import itself is all or nothing, so a failed import leaves nothing to undo.
- **After real transactions exist:** do not restore over them. Records are never deleted (immutable by design); correct by deactivating a room or type and entering the right one, each with a reason, and decide with the owners whether to roll the business back to the old system for the affected days. This decision belongs to the General Manager and is recorded.

## Not decided here

- The format of opening folio balances and the opening of reservations (owners decide whether any are migrated at all).
- Retention of the source files containing guest data, which follow `PRIVACY-OPERATIONS.md`.
