# Domain Modeling Patterns

Use explicit domain types for concepts that carry rules:

- `PropertyId`, `ReservationId`, `StayId`, `FolioId`, `PaymentId`, `StockMovementId`, etc.
- `Money(amountMinor, currency)` — never float.
- `BusinessDate` distinct from wall-clock date.
- `DateRange` for stay/rate validity.
- `RoomNumber`, `Barcode`, `TaxRate`, `ServiceChargeRate`, `Quantity`, `UnitOfMeasure`.
- enums for stable state machines.

Historical commercial facts are snapshots. A folio item stores the rate/tax/service-charge values applied at posting time; changing master configuration must not rewrite historical transactions.

State transitions occur through named methods (`confirm`, `checkIn`, `postCharge`, `settle`, `void`, `close`) that validate invariants. Avoid public setters for aggregate state.
