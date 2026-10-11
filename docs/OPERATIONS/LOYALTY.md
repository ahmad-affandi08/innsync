# Loyalty points

Page `/front-office/loyalty` (rights `front-office.loyalty.view`, `front-office.loyalty.redeem`, `front-office.loyalty.adjust`). Tables `loyalty_accounts`, `loyalty_entries` (migration 143).

## Rules (baselines, `config/frontdesk.php`)

- **Off until switched on.** `LOYALTY_ENABLED=true` starts the programme. While it is off no points are given; the accounts and what was earned stay.
- **Earning.** When a guest checks out, one point for every `LOYALTY_EARN_PER_POINT_MINOR` (default Rp 10.000) of the room revenue charged to the stay — the base before service charge and tax, as the night audit posted it. Once per reservation, whatever happens to the message that carries the event.
- **Who is a guest.** The name and the phone number together (the same rule as the guest notes and the do-not-rent flag); they are kept as a hash plus the name and the last four digits of the phone for the screen. A booking without a phone number earns nothing, because the name alone cannot tell two guests apart.
- **Spending.** Points are turned into a **voucher** (a complimentary gift card, `docs/OPERATIONS/GIFT-CARDS.md`) worth `LOYALTY_POINT_VALUE_MINOR` per point (default Rp 100, about one percent back), at least `LOYALTY_MIN_REDEEM_POINTS` (default 100) at a time. The code is shown once. The person needs both the loyalty right and the right to sell gift cards, because a voucher is money to spend. A request sent twice with the same key takes the points once and answers with the same voucher, without its code.
- **Books.** Nothing is booked when points are earned (no liability is carried). The cost appears when points are spent: the voucher is a complimentary card, and the accounting journal books it as promotion expense against the gift card liability, then clears the liability when the card is spent.
- **Corrections.** A person with the adjust right adds or takes points with a reason (never below zero); it is audited.
- **Entries** (earn, redeem, adjust) are never changed or deleted (database triggers).

## Not decided here

- Points that expire, tiers (silver, gold), points for food and beverage or spa spend, bonus campaigns: none. One rate on room revenue only.
- Taking points back when a stay is corrected after check-out: use a correction with the reason.
- Spending points directly against a folio: they become a voucher first.
