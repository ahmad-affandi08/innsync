# Spa and activities

Page `/front-office/activities` (rights `front-office.activity.view`, `front-office.activity.book`, `front-office.activity.manage`). Tables `activity_services`, `activity_bookings` (migration 144).

## Rules (baselines)

- **Services.** A code, name, category (spa, tour, sport, other), length (15 minutes to 8 hours), price **for each guest** (before service charge and tax) and **how many guests can take part at the same time** (1 to 50). A price, length or capacity changed later counts for new bookings; bookings made keep their price. A service is paused, not deleted.
- **Bookings are for guests in the house.** A booking names the guest by the reservation, a day (today up to a year ahead, not after the guest leaves), a time (`HH:MM`) and how many take part. It is refused when, at any moment of it, the guests together with those already booked would pass the capacity (a booking ending when another starts does not overlap it; the service must end before midnight). Only booked and given bookings hold a place; cancelled and no-show ones give it back. A booking request sent twice with the same key makes one booking. The number is `ACT-000001`.
- **Given.** *Given, charge to room* (on or after the day) charges the price × guests to the guest's open folio, once per booking, with the service charge and tax of the scheme in `ACTIVITIES_CHARGE_SCOPE` (default `rooms`; `laundry` and `fnb` are the other schemes the property has), posting source `activity`, charge code `ACT`. A price of zero charges nothing. A guest with no open folio is refused with the reason.
- **Not taken up.** Cancel with a reason, or mark the guest did not come (on or after the day). Nothing is charged for either.
- **Settled bookings** (given, cancelled, no-show) cannot be changed or deleted (database trigger). A charge made by mistake is reversed on the folio in the ordinary way, with its approval.
- **Books and reports.** The charge is an ordinary folio posting; it reaches the day's revenue and the accounting journal through the night audit, under **other** revenue (a separate spa outlet line is not built).

## Not decided here

- Guests who are not staying (walk-ins paying at the spa): not supported here — they would be sold through the restaurant register or a gift card; and booking before the guest has checked in.
- Therapists, rooms and resources as separate things with their own calendar: capacity is one number for the service.
- Packages, memberships, commissions to therapists, and a deposit when booking.
- A separate revenue outlet and tax scheme for spa (`rooms` / `laundry` / `fnb` schemes only).
