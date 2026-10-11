# Gift cards and vouchers

Traceability: PRD gap review of 2026-10-10. Page `/front-office/gift-cards` (rights `front-office.gift-card.issue`, `.void`, `.view`).

## The rules (baselines the hotel can change)

- **A card is a debt, not revenue.** Selling one brings money in and creates a liability to the holder; revenue is recognised when the card pays a folio or a bill. In the accounting journal (`docs/TASK/MODULE-10-FINANCE.md`) a sale is *debit cash or bank, credit gift card liability* (`gift_card_liability`, baseline `2-1600`), a payment *by* a card is the folio's or the bill's payment line with the method `gift_card` debited to that same liability account, and a voucher the hotel gives away (*complimentary*) is debited to promotion expense (`5-8000`) at the sale. The account codes are the hotel's to set in Finance → Export.
- **The code** is `GC-XXXX-XXXX-XXXX` from an alphabet without look-alikes, shown **once** when the card is made, and kept only as a keyed hash (the same protection as other identifiers); the last four characters are kept to recognise the card. A sale sent twice makes one card and answers the second time without the code. A lost code cannot be looked up: **void the card** (the rest is paid back or written off, with a reason) and sell a new one.
- **Paying with a card.** The folio payment dialog and the restaurant bill's payment dialog have *Gift card or voucher*: the code is typed, the card pays up to its balance in the property's currency, only if it is active and not past its last day. The card is charged in the same transaction as the payment, once per payment; the folio and the bill keep a masked reference (`GC-...1234`). A wrong code, an expired, void or too small card all answer the same: *this gift card cannot pay this*.
- **Giving it back.** Reversing a folio payment made with a card, or refunding a restaurant bill paid with one, puts the money back on the card (one *restore* entry per payment). Money is never paid back *onto* a card as a refund method.
- **Cash for a card is cash in the drawer.** A card sold for cash is counted in the front office cashier shift (it needs an open shift where the property requires one) and so in the expected cash when the shift is closed.
- **Last day.** Twelve months from the sale unless the sale says otherwise (`GIFT_CARD_VALID_MONTHS`, at most five years). A card past its last day cannot pay; what is left on it stays a liability until someone voids it and chooses *written off* (other revenue) or *paid back*. Nothing expires a balance by itself.
- **Every movement is an entry** (issue, redeem, restore, void) that is never changed or deleted (database triggers), with the balance after it, and is audited. Redeeming and restoring are idempotent per source.

## Not decided here

- Terms of use of the cards (refund policy, non-transferability, the legal last day) are the hotel's, with its lawyer; the baselines above are a starting point, not legal advice.
- Selling a card online, to someone who is not at the desk, is not built (it would need a payment provider, PRD Q-04).
- Voiding a card with a balance is not maker-checker: it needs its own right and a reason, and is audited.
