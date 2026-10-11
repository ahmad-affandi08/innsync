# Commissions to travel agents and booking channels

Page `/front-office/commissions` (rights `front-office.commission.view`, `.manage`). Tables `commission_partners`, `commission_accruals`, `commission_statements` (migration 140).

## Rules (baselines)

- **Partner and rate.** A partner is a company or agent profile (`Front Office → Companies`) or a booking channel as an imported file names it (`booking_com`, `agoda`…). Each has one rate, more than 0 and at most 50 percent, and can be switched off. A new rate counts from the next check-out; what was accrued keeps its rate.
- **Accrual.** When a guest checks out (`frontoffice.stay.checked_out`), `CommissionAccrualConsumer` finds the partner the reservation came through (the company it is linked to, else the channel it was imported from) and accrues, once per reservation, `rate × room revenue`, rounded half up. The room revenue is the **base** the night audit charged to the reservation's folios (`source = night_audit`, reversals taken off): before service charge and tax, and for the nights actually charged, not the nights booked. Nothing is accrued for a partner without a rate or switched off, or when no room revenue was charged.
- **Payment.** A statement (`KOM-000001`) pays all the accrued commissions of a partner by cash or bank transfer (a transfer needs its reference), on a day not in the future. Statements and accruals are never changed or deleted (database triggers); an accrual changes only from *owed* to *paid* or *void*.
- **Not owed.** A commission that is not owed (a booking cancelled by the channel after check-out, a dispute) is voided with a reason. A paid one is not voided: it is settled with the partner and corrected in the books.
- **Books.** In the accounting journal an accrual is *debit commission expense (`5-3000`), credit commission payable (`2-1700`)* on the check-out day, and a statement is *debit payable, credit cash or bank* on the day paid. Account codes are the hotel's to set in Finance → Export.
- **Export.** The accruals as a spreadsheet-safe CSV (check-out, partner, reservation, room revenue, rate, commission, status), audited, for the partner's statement.

## Not decided here

- Whether a commission is on room revenue only (built) or also on food and beverage, and whether it is net or gross of the service charge: the baseline is room base before service charge and tax; a different base is a change in `CommissionStore::roomBaseOf`.
- Commission tax (withholding on a payment to an agent): not calculated; the hotel's accountant decides and books it.
- A rate per room type, per season or per rate plan: one rate per partner.
