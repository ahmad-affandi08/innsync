# Finance — Task Contract

**Bounded context:** Finance
**Critical note:** Operational finance, settlement, liabilities, management P&L; not statutory GL unless scope changes.

## Candidate aggregates / read models

- `FinancialPosting`
- `Settlement`
- `Payable`
- `Receivable`
- `Expense`
- `TaxLiability`
- `ServiceChargeLiability`

## Requirement backlog

| Task ID | FR | Priority | Requirement | Status |
| --- | --- | --- | --- | --- |
| TASK-FIN-001 | FR-FIN-001 | Wajib | Menerima pembukuan pendapatan otomatis dari Front Office dan seluruh POS outlet, terpisah antara nilai dasar, pajak, dan service charge. | REVIEW |
| TASK-FIN-002 | FR-FIN-002 | Wajib | Menerbitkan laporan pendapatan harian per outlet dan per metode pembayaran, serta rekapitulasi bulanan. | REVIEW |
| TASK-FIN-003 | FR-FIN-003 | Wajib | Melakukan rekonsiliasi setoran kasir: kas fisik yang disetor dibandingkan dengan kas sistem per shift dan per kasir, dengan pencatatan selisih. | REVIEW |
| TASK-FIN-004 | FR-FIN-004 | Sebaiknya | Merekonsiliasi penerimaan QRIS dan kartu terhadap mutasi rekening bank, termasuk pemotongan biaya transaksi. | TODO |
| TASK-FIN-005 | FR-FIN-005 | Wajib | Memverifikasi dan mengunci transaksi hari sebelumnya setelah night audit sehingga tidak dapat diubah tanpa jurnal koreksi. | REVIEW |
| TASK-FIN-006 | FR-FIN-006 | Wajib | Setiap posting keuangan menyimpan property, business date, event time, source document, actor, dan correlation ID agar rekonsiliasi lintas modul dapat dilakukan tanpa ambigu. | IN_PROGRESS |
| TASK-FIN-010 | FR-FIN-010 | Wajib | Mengelola daftar akun biaya sederhana yang dikelompokkan per department dan per kategori. | REVIEW |
| TASK-FIN-011 | FR-FIN-011 | Wajib | Mencatat hutang kepada pemasok dan vendor secara otomatis dari penerimaan barang dan faktur, lengkap dengan syarat pembayaran dan tanggal jatuh tempo. | REVIEW |
| TASK-FIN-012 | FR-FIN-012 | Wajib | Menampilkan jadwal jatuh tempo pembayaran dan laporan umur hutang, serta mengirimkannya sebagai peringatan ke dashboard. | REVIEW |
| TASK-FIN-013 | FR-FIN-013 | Wajib | Mencatat pembayaran kepada pemasok dan vendor, baik penuh maupun sebagian, beserta bukti pembayaran. | REVIEW |
| TASK-FIN-014 | FR-FIN-014 | Wajib | Mengelola piutang dari perusahaan, agen perjalanan, dan kanal pemesanan daring beserta umur piutang dan penagihan. | TODO |
| TASK-FIN-015 | FR-FIN-015 | Wajib | Mengelola kas kecil (petty cash): pengisian, pengeluaran dengan bukti, dan pertanggungjawaban. | TODO |
| TASK-FIN-016 | FR-FIN-016 | Sebaiknya | Mencatat biaya tetap berulang seperti sewa, listrik, air, dan langganan, dengan pengingat jatuh tempo. | TODO |
| TASK-FIN-017 | FR-FIN-017 | Sebaiknya | Menyusun anggaran per department dan menampilkan perbandingan anggaran terhadap realisasi. | TODO |
| TASK-FIN-018 | FR-FIN-018 | Wajib | Pembayaran vendor/pengeluaran di atas threshold menggunakan maker-checker; pembuat transaksi tidak boleh menjadi satu-satunya penyetuju. | REVIEW |
| TASK-FIN-019 | FR-FIN-019 | Wajib | Refund tamu, chargeback, settlement discrepancy, dan pembayaran berstatus unknown dikelola sebagai exception sampai direkonsiliasi, bukan diedit langsung pada transaksi asal. | TODO |
| TASK-FIN-020 | FR-FIN-020 | Wajib | Menghitung pajak daerah atas jasa perhotelan dan makanan minuman secara otomatis per outlet dengan tarif yang dapat dikonfigurasi. | TODO |
| TASK-FIN-021 | FR-FIN-021 | Wajib | Menyajikan lini masa kewajiban pajak: nilai terkumpul berjalan, periode pelaporan, tanggal jatuh tempo, dan status penyetoran. | TODO |
| TASK-FIN-022 | FR-FIN-022 | Wajib | Menghitung akumulasi service charge dari kamar dan outlet serta menyiapkan nilai yang akan didistribusikan melalui modul Human Resource. | TODO |
| TASK-FIN-023 | FR-FIN-023 | Wajib | Menerbitkan berkas rekapitulasi pajak yang siap dilaporkan kepada instansi pajak daerah. | TODO |
| TASK-FIN-024 | FR-FIN-024 | Sebaiknya | Memisahkan pencatatan pendapatan yang tidak dikenai pajak, kompliment, dan penghapusan tagihan agar dasar pengenaan pajak tetap akurat. | TODO |
| TASK-FIN-025 | FR-FIN-025 | Wajib | Tarif pajak, service charge, dan aturan pembulatan memiliki tanggal efektif; perubahan konfigurasi tidak boleh mengubah perhitungan transaksi historis. | TODO |
| TASK-FIN-030 | FR-FIN-030 | Wajib | Menerbitkan management P&L operasional per department berdasarkan pemetaan pendapatan dan biaya yang tersedia. Laporan diberi label jelas sebagai laporan manajemen, bukan laporan keuangan statutori pengganti buku besar akuntansi. | TODO |
| TASK-FIN-031 | FR-FIN-031 | Wajib | Menerbitkan laporan arus kas ringkas: penerimaan, pengeluaran, dan saldo kas serta bank. | TODO |
| TASK-FIN-032 | FR-FIN-032 | Sebaiknya | Menerbitkan laporan biaya bahan terhadap penjualan untuk outlet makanan dan minuman. | TODO |
| TASK-FIN-033 | FR-FIN-033 | Sebaiknya | Menerbitkan laporan nilai persediaan pada tanggal tertentu berdasarkan data modul Inventory. | TODO |
| TASK-FIN-034 | FR-FIN-034 | Wajib | Mengekspor data transaksi ke format lembar kerja atau format impor perangkat lunak akuntansi yang digunakan properti. | TODO |
| TASK-FIN-035 | FR-FIN-035 | Wajib | Menyimpan jejak audit atas seluruh perubahan angka keuangan beserta pelaku dan waktunya. | TODO |
| TASK-FIN-036 | FR-FIN-036 | Wajib | Transaksi keuangan yang telah locked hanya dapat dikoreksi melalui reversal/adjustment yang menaut ke transaksi asal dan memerlukan alasan serta otorisasi. | TODO |
| TASK-FIN-037 | FR-FIN-037 | Wajib | Rekonsiliasi harian menghasilkan daftar exception antara POS/folio, payment provider/EDC, kas fisik, dan bank; hari dianggap clean hanya bila exception telah diselesaikan atau di-waive. | IN_PROGRESS |

## Required engineering checks

- Identify aggregate owner and state transition before coding.
- Enforce property scope and server-side authorization.
- Define transaction/idempotency/concurrency behavior where mutation is critical.
- Emit audit evidence for sensitive/state-changing operations.
- Add happy, negative, conflict/retry, and permission tests as applicable.
- Update traceability/evidence before marking DONE.

## Delivery notes

### Slice 11 (2026-10-03): accounts payable, the first part of the Finance context

- Status: `TASK-FIN-010`, `-011`, `-012`, `-013` and `-018` are `REVIEW`. `TASK-FIN-006` is `IN_PROGRESS`: payables carry the posting facts, but the other postings (revenue, cash) do not exist yet. NFR/BR: BR-003 (a payable and a paid payment are never changed), BR-004 (no self-approval), BR-005 (idempotent), BR-002 (integer money), BR-006 (document numbers `PAY-nnnnnn`).
- Context: the new bounded context `Finance` (`app/Modules/Finance`), migration 62 (`finance_expense_accounts`, `ap_payables`, `ap_credits`, `ap_credit_applications`, `ap_payments`, `ap_payment_proofs`), screens under `/finance` (Payables, Payments, Due schedule, Aging, Expense accounts) and a Finance entry in the module navigation.
- **Finance reads events, not tables.** `PurchasingPayableConsumer` is an outbox consumer (registered in `config/outbox.php`) for `purchasing.invoice.recognised` and `purchasing.return.posted`. A recognised supplier invoice becomes a payable and a return that came with a supplier credit note becomes a credit; each is made once per source document however often the event is delivered. The payload of those two events carries what Finance needs (supplier code and name, invoice number and date, due date, business date, currency). Because the outbox is processed by the worker, a payable appears when the worker has run, not in the same request.
- **Posting facts (FR-FIN-006)**: a payable keeps the source document, the business date, the event time, the actor and the correlation id of the event that made it. The amount, the dates and the source never change (triggers); only the expense account it is classified under can.
- Choices recorded as configurable baselines:
  - **Balance**: amount less paid payments less credits applied. Status open, partly paid or paid; overdue when something is owed after the due date. A payment that waits for approval counts against what can still be paid.
  - **Payments (FR-FIN-013)**: against one payable, in full or in part, never more than is owed; methods transfer, cash, giro, other; a transfer or a giro needs its reference; the date cannot be in the future; a proof of payment (PDF, JPEG or PNG, up to five) is added afterwards. A paid payment never changes.
  - **Maker-checker (FR-FIN-018)**: approval subject `finance.supplier-payment`, with the threshold and the chain set by the owner in the approval policy (no policy for an amount means it is paid when recorded). A different person approves; the person who recorded the payment takes the decision to release it; a rejected payment is closed with the reason and frees its amount; a pending one can be cancelled by its maker.
  - **Supplier credits**: a credit from a return with a credit note (goods and the tax it credits) is set against payables of the same supplier in parts, never more than the credit has left or the payable still owes.
  - **Aging (FR-FIN-012)**: what is owed at the end of a chosen business date by days past due (not yet due, 1-30, 31-60, 61-90, over 90), per supplier; payments and credits count from their own date, so an earlier date is stable. The due schedule lists what is overdue and what falls due in the next 7 to 90 days. The dashboard warns of payables past due and payables due in the next seven days.
  - **Expense accounts (FR-FIN-010)**: code (fixed), name, department and category (a fixed baseline list); payables are classified under an active account.
- Known limit: cancelling a payment that waits for approval closes the payment but leaves its approval request pending in the approvers' inbox (the approval gate has no cancel for a request yet); approving it later changes nothing.
- Not yet: payments by batch, reversal of a paid payment (needs a reversal document), recurring expenses, petty cash, accounts receivable, revenue postings and bank reconciliation (the rest of Finance).
- Evidence: `tests/Feature/Finance/AccountsPayableHttpTest.php` (18 tests: the event pipeline and its idempotency, partial and full payments, the second approver, credits, aging at the bucket edges, the due schedule, the dashboard alerts, immutability triggers, privileges) and the browser run of the screens in both languages.

### Slice 12 (2026-10-03): revenue statement, cash received for shifts, exceptions and the verified day

- Status: `TASK-FIN-001`, `-002`, `-003` and `-005` are `REVIEW`. `TASK-FIN-037` is `IN_PROGRESS`: the cash side of the daily reconciliation (cash received against the system, exceptions settled before a day is verified) exists; the payment provider/EDC and bank sides do not. `TASK-FIN-006` stays `IN_PROGRESS` (payables, revenue days and cash receipts carry their posting facts; refunds and petty cash do not exist yet). NFR/BR: BR-003 (a booked day, a cash shift, a deposit and a settled exception are never changed), BR-004 (the cash of a shift is received, and a difference settled, by someone other than the person who worked it or received it), BR-005 (idempotent), BR-002 (integer money), BR-006 (document numbers `DEP-nnnnnn`).
- Context: migration 63 (`fin_revenue_days`, `fin_revenue_lines`, `fin_payment_lines`, `fin_cash_shifts`, `fin_cash_deposits`, `fin_cash_exceptions`), `FrontOfficeRevenueConsumer` (registered in `config/outbox.php`), `RevenueService`, `CashReconciliationService`, screens `/finance/revenue`, `/finance/revenue/{date}` and `/finance/cash`; privileges `finance.revenue.view` (read) and `finance.reconcile.manage` (receive cash, settle, verify).
- **Finance reads events.** The night audit event (`frontoffice.night_audit.completed`) now also carries the day's revenue by posting source (base, service charge, tax apart, charges and their reversals) and its payments by method; the shift event (`frontoffice.cashier.shift.closed`) carries the closing business date, the float, the drops and the cash the shift took in. Finance books each once per source document. A source is shown under the revenue outlet that owns it at that time (rooms and laundry are built in, every other unclaimed source is `other`); the outlet is kept on the line, so a later outlet change does not move a booked day.
- **A day is a fact (FR-FIN-005, FR-FIN-006)**: figures, lines and payments never change (triggers); the day keeps the night audit, the business date, the event time, the actor and the correlation id. Because the business date advances at the night audit, a posting made afterwards falls on a later day: a correction of a booked day is a reversal posted on a later day.
- **Reports (FR-FIN-002)**: the daily report is the booked days of a range (up to 93 days) with totals, by outlet and by payment method (received, paid back, net); the monthly roll-up shows the twelve months of a year, each with its outlets. Both read only what was booked.
- **Cash received (FR-FIN-003)**: the system holds, per closed shift, the cash it took in (received less paid back). Finance counts what is handed over and records it once as a deposit (`DEP-nnnnnn`), never for a shift it worked or closed itself. The declared handover (counted drawer less the float that stays, plus the drops) is shown beside the system figure. A difference needs a reason and opens an exception; a deposit that matches opens none.
- **Exceptions (FR-FIN-037)**: open until someone other than the receiver settles it as explained, recovered or waived, with a note; a settled exception never changes. Baseline: any difference opens one (no tolerance); a tolerance can be added later as a property setting.
- **Verified day (FR-FIN-005)**: finance verifies a booked day once every shift closed on that date has its cash received and none of their exceptions is open; the verification (who, when, note) is final.
- Not yet: reconciliation of QRIS and card receipts against the provider and the bank (FR-FIN-004), refunds and chargebacks as exceptions (FR-FIN-019), a locked-day correction document with authorisation (FR-FIN-036), POS outlets as a source (they appear as soon as they post to the folio with a source an outlet claims), and the dashboard alert for unverified days.
- Evidence: `tests/Feature/Finance/RevenueReconciliationHttpTest.php` (booking and its idempotency, outlets, immutability, the reports, privileges, cash received with and without a difference, settlement by another person, the verified day) and `tests/Integration/Finance/RevenueFromFrontOfficeTest.php` (the real night audit and shift close booked by finance).
