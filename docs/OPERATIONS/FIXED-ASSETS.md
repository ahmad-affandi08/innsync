# Fixed assets and depreciation

Page `/finance/assets` (rights `finance.asset.view`, `finance.asset.manage`). Tables `fin_fixed_assets`, `fin_depreciation_entries` (migration 141).

## Rules (baselines)

- **The register.** An asset has a code, name, category (building, furniture, equipment, vehicle, IT, other), the day acquired, its cost, what it is worth at the end of its life (0 by default), and its life in months. The cost, the value at the end, the life and the day acquired never change (database trigger); a mistake is a new asset and the wrong one leaving. The life is filled with the usual one of the category (furniture and IT 4 years, equipment and vehicles 8, buildings 20 — the tax groups) and the hotel changes it to its own policy.
- **Straight line, whole months.** `(cost − value at the end) ÷ life` rounded down each month, the last month takes the difference so the total is exact. The month of acquisition is depreciated in full; the month an asset leaves is not.
- **A month is depreciated after it ends.** *Depreciate through <last closed month>* adds one entry per asset for every month not yet depreciated, once (a second run changes nothing); entries are never changed or deleted. Running it is a monthly closing step for Finance.
- **An asset leaves** (sold, scrapped, lost) with the day, a reason and, for a sale, what the buyer paid and how. The depreciation through the month before must be booked first. Afterwards it is not depreciated again and nothing about it changes.
- **Books.** The journal carries `DEP-<month>-<code>` on the last day of each month (*debit depreciation expense `5-6000`, credit accumulated depreciation `1-3900`*) and `DSP-<code>` on the day an asset leaves (*debit accumulated depreciation and the money received, credit the asset at cost `1-3000`, and the difference to the book value as gain `4-2000` or loss `5-6100`*). Account codes are the hotel's to set in Finance → Export.
- **Buying an asset** is not booked here: record the supplier invoice to an expense account whose code in your accounting software is the fixed asset account (Finance → Expense accounts), so the purchase is on the balance sheet, not in the month's expense.

## Not decided here

- Declining balance, other methods, half-month conventions and tax depreciation different from the books: straight line by whole months only.
- Revaluation and impairment.
- Linking an asset to the maintenance register (`maintenance_asset_id` is kept free for it).
