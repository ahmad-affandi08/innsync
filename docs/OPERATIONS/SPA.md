# Spa

Pages `/front-office/spa` (the treatment rooms of the day, booking, payment), `/front-office/spa/manage` (set-up and report) and `/front-office/spa/me` (the therapist's phone). Rights `front-office.spa.view`, `.book`, `.manage` and `.operate`. Tables `spa_rooms`, `spa_treatments`, `spa_therapists`, `spa_attendance`, `spa_bookings` (migration 157, which also creates the rights, gives them to the starting roles and makes the role **Spa Therapist** in every property). The page is an optional one: switch it on in Set-up → How the property works → Extra pages (*Spa*). It is separate from *Spa and activities* (`docs/OPERATIONS/SPA-ACTIVITIES.md`), which sells tours and classes to guests of the house by capacity.

## How a treatment goes

1. **Set-up (manager).** Treatment rooms, treatments (a name, a length in minutes, a price before service charge and tax) and therapists. A therapist has a **commission rate in percent of the price** (0 to 100, set for each therapist) and may be linked to the account they sign in with on the phone: the account must hold the role *Spa Therapist* (right `front-office.spa.operate`), which a user administrator gives like any role.
2. **Booking (desk).** Touch a treatment room on the board: the start time, then the guest (a guest of the house, or a guest who is not staying with a name), the treatment, the therapist. A room and a therapist cannot be in two bookings at once (a booking may start when the one before ends); the check is made under a lock, so two people booking at the same moment cannot both get it. A booking is for today or up to 60 days ahead and must end the same day.
3. **The therapist's phone.** Clock in (attendance, once a day, shown to the desk as *here today*) → see the bookings given to them, with the guest and the room → **Accept** or **Decline** (with an optional reason) → **Start** → the time left counts down (the clock of the server, not the phone's) → **Finish**. A declined booking comes back to the desk in red to be given to another therapist (*Change therapist*), who must then accept it. Starting needs the therapist to be clocked in, and a room or a therapist can run one treatment at a time. A therapist cannot clock out while a treatment runs.
4. **Commission, as it happens.** The share is fixed when the treatment is finished: `price × rate`, rounded to the smallest unit, using the **rate the booking was made with** (changing a rate later changes later bookings only). While a treatment runs the phone shows the share growing with the minutes gone, and *Commission today* adds the finished ones.
5. **Money.**
   - **A guest of the house** is charged to the folio the moment the treatment is finished (source `spa`, code `SPA`, with the service charge and tax of the scheme in `SPA_CHARGE_SCOPE`, default `rooms`). If the folio cannot take it (the stay ended, a rule refused it) the treatment is still done and the desk sees *not paid yet*, to be collected as a guest who is not staying.
   - **A guest who is not staying** pays at the desk (*Take payment*: cash, card or QRIS; card and QRIS need the approval or QRIS reference). Cash goes in the drawer of the cashier's open shift, and the sale is revenue of the day under its own line **Spa** (service charge and tax apart), which reaches the revenue reports and the accounting journal (account `revenue.spa`, baseline 4-1160, changed in Finance → Export → Account codes) the way a restaurant bill does.
   - A manager can leave off the **service charge** or the **tax** of one booking when booking it (nobody else can); it is recorded on the booking and on the folio posting.
6. **Not taken up.** The desk cancels (with a reason) or marks *did not come* a booking that is still waiting, accepted or declined. Nothing is charged. A booking that has started cannot be cancelled.

## The manager's report (`/front-office/spa/manage`)

For a period (this month by default): treatments done, sales before and after the charges, how much was charged to rooms, paid at the desk and **not collected yet**; the **commission of each therapist** (treatments, sales, commission); and the **treatments sold, best first** (by number, then sales). Only a person with `front-office.spa.manage` sees rates and the report; the desk sees names and times only.

## Rules and limits

- Baselines in `config/frontdesk.php` (`spa.charge_scope`). Money is kept in the smallest unit (Rp 1 = 100); the commission is rounded half up.
- A booking keeps the treatment name, length, price, therapist name and rate it was made with; a booking row is never deleted (database trigger), and every step is in the audit trail.
- The phone page looks again at the server every 15 seconds and the board every 30, so a screen left open shows a new booking or a free room.

## Not decided here

- **Commission on tips, on products sold with a treatment, a different rate for one treatment or one therapist-and-treatment pair, a minimum guaranteed pay, and paying the commission out** (it is reported; payroll takes it from the report by hand). The report is the base to build them on.
- **The spa's own stock (oils, towels), its assets, maintenance list and purchase ordering.** The spa uses the hotel's inventory, maintenance and purchasing as another department would; a department *Spa* of its own in those modules is not made yet.
- **Memberships and packages (5 massages for the price of 4), a deposit for a booking, and a booking made by the guest on the guest's phone.**
- **Refund of a treatment already paid:** a correction of the day in Finance, with its approval, like a day use.
- **Billiards or other timed rooms.** The board, the time left and the booking by room are written for treatment rooms; a billiard table uses the same shape (a room, a length, a price, an attendant) and would be set up as another kind of room in a later change.
