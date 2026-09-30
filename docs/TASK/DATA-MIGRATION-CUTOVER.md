# Data Migration, Cutover, and Rollback Tasks

- Inventory source systems/files and assign data owners.
- Finalize import schema/version for property/rooms/rates/outlets/items/UOM/warehouses/vendors/assets/employees/roles/tax/service-charge.
- Define opening/outstanding transaction scope.
- Implement dry-run validation with per-row errors.
- Run Dry Run 1 and Dry Run 2.
- Define control totals (counts and amounts) and obtain sign-off.
- Prepare freeze window, backup, open-transaction list, rollback trigger, and re-entry reconciliation procedure.
- Cut over, smoke test critical paths, reconcile, and enter hypercare.

No migration is "successful" solely because rows imported; control totals and business sampling must reconcile.
