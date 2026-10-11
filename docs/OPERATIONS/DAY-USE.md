# Day use

Page `/front-office/day-use` (rights `front-office.day-use.view`, `.book`, `.manage`). Tables `day_use_rates`, `day_use_bookings` (migration 148, which also creates the rights and gives them to the starting roles).

A day use is a room rented for some hours of the day with no night in it, for a guest who is not staying. It is **not a stay**: it has no reservation, no folio, no night charge, and it is not in the occupancy of the night audit.

## Rules (baselines, `config/frontdesk.php`)

- **Price.** Each room type has a price for the whole booking (before the service charge and tax) and the longest it can be rented, 1 to 12 hours. A room type with no price, or with its price paused, cannot be rented by the hour. A price changed later counts for new bookings; a booking keeps the price it was made with.
- **Hours.** Between `DAY_USE_EARLIEST_START` (06:00) and `DAY_USE_LATEST_END` (21:00), so the room is back before the evening; a booking can be made today up to `DAY_USE_MAX_ADVANCE_DAYS` (60) days ahead.
- **A day use never takes a room the night needs.** A booking is refused when: a guest of the house has the room (the day a guest leaves, it starts at `DAY_USE_AFTER_CHECKOUT`, 12:00, or later); a reservation has been given the room for that night; the room is out of order; another day use of the room overlaps it (one may end when the next starts); or the hotel has **no spare room of that type for that night** (every room of the type is sold, held or blocked). The last rule is conservative on purpose: a day use is sold only when the night is not full.
- **Paying.** The guest pays when let in (*Let in and take payment*, on the day itself): the price with the service charge and tax of the scheme in `DAY_USE_CHARGE_SCOPE` (default `rooms`), by cash, card or QRIS (card and QRIS need the approval or QRIS reference). The room must have no guest of the house in it and be clean (housekeeping *ready*). Cash is in the drawer of the cashier's open shift.
- **Revenue.** The payment is revenue of the day under its own line **Day use** (service charge and tax apart), reaches the revenue reports and the accounting journal (account `revenue.day_use`, baseline 4-1150, changed in Finance → Export → Account codes) the same way a restaurant bill does, and is not counted as a night.
- **Leaving.** *Guest has left* hands the room to housekeeping (dirty, with a departure task). A day use still in the room **blocks the night audit** (the check *Day use still in the room*, which a manager can waive with a reason like the others).
- **Not taken up.** Cancel with a reason, or mark the guest did not come (on or after the day), only before the guest is let in. Nothing is charged.
- A paid booking cannot be changed or deleted (database trigger).

## Not decided here

- **Refunds of a day use that was paid.** None on this screen: a mistake is corrected with a correction of the day in Finance, with its approval.
- **Day use for a guest of the house, charged to the room,** and day use sold by the hour more than once a day for the same guest.
- **Per-hour or per-extra-hour prices, packages with food, a price that depends on the day of the week.** One price a room type.
- **Door lock key for a day use, a registration card, and the guest's identity document** (a day use guest is a name and an optional phone number).
- **Channel sales of day use** (the online booking page and channel imports sell nights only).
