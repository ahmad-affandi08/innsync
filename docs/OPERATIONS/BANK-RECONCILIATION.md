# Bank statement matching

Page `/finance/bank` (rights `finance.bank.view`, `finance.bank.manage`). Tables `fin_bank_imports`, `fin_bank_lines` (migration 142).

## What it does

The hotel downloads the statement of a bank account as a CSV, loads it here, and ties each line to the record of the books it belongs to. A line that matches nothing stays **open** until a person explains it. The books are not changed by matching: it is a check, kept with who did it and when.

## Rules (baselines)

- **The file.** CSV (comma, semicolon, tab or pipe), a header row, then a date, a description and either one signed amount or separate debit and credit columns (English and Indonesian header names, `CR`/`DB`, `1.234,56` and `1,234.56` are read). Dates as `2026-10-10`, `10/10/2026`, `10-10-2026`. At most 3,000 rows and 2 MB. A bank's own layout that differs (for example one with header lines above the table) is trimmed by hand first.
- **Check first.** *Check the file* reads it without loading anything and names every line that cannot be read; one bad line refuses the whole file, so a statement is never half loaded.
- **Loaded once.** Every line has a fingerprint (account name, date, direction, amount, description, reference and the number of times that same line occurs on the day). Loading the same file again, or an overlapping period, adds only the lines not loaded before. Two real identical lines on one day are kept as two.
- **Suggestions.** For each open line the books are searched for a record of exactly the same amount and direction within 7 days: money out — a supplier payment (not cash), a payroll payment, a commission payout by transfer; money in — a receivable receipt, a card or QRIS settlement. A record already tied to a line is not offered again.
- **Matching** needs the same amount and direction; a record belongs to at most one line. A wrong match is reopened (with a reason) and tied again.
- **Set aside** is for a line that belongs to no record (bank interest, a fee, not ours); a note of 3–200 characters is required. It can be reopened.
- Matched, set-aside and reopened are audited. Imported lines are never changed or deleted (database triggers allow only the status change).

## Not decided here

- A bank's own format (BCA, Mandiri, BNI, BRI, CIMB statement layouts or MT940/CAMT): the generic CSV only. A bank API feed is not connected.
- Matching one line to several records, or several lines to one (a lump deposit of several days): one to one only.
- Booking a line that has no record (a bank fee) as a journal entry: it is only set aside with a note.
