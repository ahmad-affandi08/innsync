# Inventory & Purchasing — Task Contract

**Bounded context:** Inventory & Purchasing
**Critical note:** Stock ledger is append/correct; purchasing approvals and receipts feed AP/stock.

## Candidate aggregates / read models

- `StockItem`
- `StockLedger/Movement`
- `StockCount`
- `PurchaseRequest`
- `PurchaseOrder`
- `GoodsReceipt`
- `PurchaseReturn`

## Requirement backlog

| Task ID | FR | Priority | Requirement | Status |
| --- | --- | --- | --- | --- |
| TASK-INV-001 | FR-INV-001 | Wajib | Mengelola data induk barang: kode, nama, kategori, satuan dasar, konversi satuan (dus ke botol, kilogram ke gram), dan department pemilik. | REVIEW |
| TASK-INV-002 | FR-INV-002 | Wajib | Mengelola beberapa lokasi penyimpanan: gudang utama, gudang bar, gudang dapur, gudang housekeeping, gudang teknik, dan galley. | REVIEW |
| TASK-INV-003 | FR-INV-003 | Wajib | Menetapkan stok minimum dan stok maksimum per barang per lokasi; pelanggaran batas minimum otomatis muncul sebagai peringatan pada dashboard. | REVIEW |
| TASK-INV-004 | FR-INV-004 | Wajib | Mencatat mutasi stok otomatis dari penjualan POS, pemakaian dapur, pemakaian housekeeping, dan pemakaian teknik. | IN_PROGRESS |
| TASK-INV-005 | FR-INV-005 | Wajib | Melakukan pemindahan barang antar gudang dengan dokumen serah terima dan konfirmasi penerima. | REVIEW |
| TASK-INV-006 | FR-INV-006 | Wajib | Melakukan stock opname terjadwal maupun mendadak, membandingkan stok fisik dengan stok sistem, dan menghasilkan berita acara selisih beserta nilai kerugian. | REVIEW |
| TASK-INV-007 | FR-INV-007 | Sebaiknya | Menghitung nilai persediaan menggunakan metode rata-rata bergerak dan menyajikannya sebagai laporan nilai persediaan per tanggal. | REVIEW |
| TASK-INV-008 | FR-INV-008 | Sebaiknya | Mengelola tanggal kedaluwarsa dan nomor batch untuk barang konsumsi. | REVIEW |
| TASK-INV-009 | FR-INV-009 | Wajib | Konversi satuan bersifat berversi dan tidak boleh mengubah histori transaksi; setiap mutasi menyimpan kuantitas satuan transaksi dan ekuivalen satuan dasar. | REVIEW |
| TASK-INV-010 | FR-INV-010 | Wajib | Stock adjustment, write-off, dan pembukaan stok negatif memerlukan reason code dan otorisasi sesuai threshold. Kebijakan stok negatif dapat diblokir per kategori/lokasi. | REVIEW |
| TASK-INV-011 | FR-INV-011 | Wajib | Mendukung retur ke pemasok dan retur antar gudang dengan dokumen referensi sehingga stok, hutang/kredit, dan histori barang tetap dapat direkonsiliasi. | REVIEW |
| TASK-INV-012 | FR-INV-012 | Wajib | Stock opname menggunakan snapshot waktu mulai; mutasi selama opname tetap tercatat dan sistem menghitung expected quantity yang konsisten untuk mencegah selisih semu. | REVIEW |
| TASK-PUR-001 | FR-PUR-001 | Wajib | Setiap department mengajukan permintaan pembelian (purchase request) berisi barang, jumlah, alasan, dan tingkat urgensi. | REVIEW |
| TASK-PUR-002 | FR-PUR-002 | Wajib | Permintaan melewati alur persetujuan berjenjang yang dapat dikonfigurasi berdasarkan nilai nominal. | REVIEW |
| TASK-PUR-003 | FR-PUR-003 | Wajib | Purchasing menggabungkan permintaan yang disetujui menjadi pesanan pembelian (purchase order) kepada pemasok terpilih. | REVIEW |
| TASK-PUR-004 | FR-PUR-004 | Wajib | Mengelola data pemasok dan vendor secara terpisah dari data barang, meliputi kontak, syarat pembayaran, daftar harga, dan riwayat penilaian. | REVIEW |
| TASK-PUR-005 | FR-PUR-005 | Sebaiknya | Membandingkan penawaran harga dari beberapa pemasok untuk barang yang sama sebelum penerbitan pesanan. | REVIEW |
| TASK-PUR-006 | FR-PUR-006 | Wajib | Mencatat penerimaan barang beserta jumlah diterima, jumlah ditolak, kondisi, dan foto; penerimaan sebagian didukung. | REVIEW |
| TASK-PUR-007 | FR-PUR-007 | Wajib | Mencocokkan tiga dokumen: pesanan pembelian, bukti penerimaan barang, dan faktur pemasok; selisih ditandai untuk ditindaklanjuti. | REVIEW |
| TASK-PUR-008 | FR-PUR-008 | Wajib | Penerimaan barang otomatis menambah stok gudang tujuan dan membentuk hutang kepada pemasok di modul Finance. | IN_PROGRESS |
| TASK-PUR-009 | FR-PUR-009 | Wajib | Menerbitkan laporan pembelian per department dalam bentuk jumlah barang maupun nilai uang, per periode dan per pemasok. | REVIEW |
| TASK-PUR-010 | FR-PUR-010 | Sebaiknya | Menerbitkan laporan penerimaan barang dan laporan ketepatan waktu pengiriman pemasok. | REVIEW |
| TASK-PUR-011 | FR-PUR-011 | Wajib | Perubahan PO yang sudah disetujui menghasilkan revisi bernomor dan memerlukan persetujuan ulang bila mengubah nilai, pemasok, atau kuantitas di atas toleransi. | REVIEW |
| TASK-PUR-012 | FR-PUR-012 | Wajib | Faktur pemasok mencatat nomor unik pemasok, tanggal, pajak, dan dokumen pendukung; sistem mencegah duplikasi invoice dan menjaga relasi ke PO serta penerimaan barang. | REVIEW |
| TASK-PUR-013 | FR-PUR-013 | Sebaiknya | Permintaan dan PO menampilkan sisa budget department; kebijakan dapat berupa warning atau hard block sesuai threshold yang dikonfigurasi. | REVIEW |

### Slice 44 (2026-10-03): batches and expiry dates of stock

- Status: `TASK-INV-008` is `REVIEW`. ADR/BR (baselines): stock that comes in with a batch number or an expiry date is kept as a batch; an outflow always takes from the batch that expires first (then the ones with no expiry); stock that came in with no batch belongs to none; a batch is flagged when it expires within 14 days; stock that has already expired is not received.
- Context: migration 93 (`inventory_lots`: batch number, expiry date, quantity received and left), `StockPoster` (makes the batch on an inflow, takes from batches on an outflow), `StockLotService` and page `inventory-purchasing/pages/lots`; a goods receipt line with an expiry date makes a batch numbered with the receipt; the stock movement form takes a batch number and an expiry date for an opening, a receipt and an adjustment in; the dashboard has an alert for batches expired or about to expire.
- Not yet: carrying a batch with a transfer to another location, choosing the batch of an outflow by hand, writing a batch off as a whole.
- Evidence: `tests/Feature/InventoryPurchasing/StockMovementHttpTest.php` (batches made by inflows, first-expired-first-out across batches, expired stock refused, the list with its states and filters, who may see it).

### Evidence (2026-10-03): approval of a large adjustment or write-off (`INV-010`)

- `TASK-INV-010` is `REVIEW`. An adjustment, a write-off and the opening of negative stock already needed the privilege, a reason code and, for the negative balance, the reason of a person with `inventory.stock.negative` (BR-007; the category and the location can forbid it). What was missing was the threshold approval, which waited for valuation (FR-INV-007, now built). The approval subject `inventory.stock.adjust` is declared in `config/approvals.php` (not mandatory): the owner configures the amount band and the approver in the approval policies, and with no policy for the amount nothing more is needed. The amount is the value of the movement at the moving average (or at the cost given, for stock that comes in).
- When the policy requires it, the posting is refused with 409 `approval_required` and nothing is booked (the check runs inside the transaction of the posting). The person asks with `POST /inventory/stock/adjustment-approval` (what is asked is exactly what is posted later: kind, item, location, unit, quantity and reason are fingerprinted), another person decides, and the same posting sent again uses the approval once: the person's own approved, unused request is found, or it is named with `approval_id`. An approval does not cover another quantity or another kind of movement, and cannot be used twice. The stock page shows the need, offers to ask, and tells the person to post again once decided.
- Evidence: `tests/Feature/InventoryPurchasing/StockMovementHttpTest.php` (`test_a_large_adjustment_or_write_off_needs_an_approved_request_when_the_policy_says_so_and_uses_it_once`). `TASK-INV-004` stays `IN_PROGRESS`: it still needs its automatic feeders (kitchen, housekeeping and engineering usage).

## Required engineering checks

- Identify aggregate owner and state transition before coding.
- Enforce property scope and server-side authorization.
- Define transaction/idempotency/concurrency behavior where mutation is critical.
- Emit audit evidence for sensitive/state-changing operations.
- Add happy, negative, conflict/retry, and permission tests as applicable.
- Update traceability/evidence before marking DONE.

## Delivery notes

### Slice 1 (2026-10-02): catalog, locations, versioned units, opening stock, minimum and maximum

- Status: `TASK-INV-001`, `TASK-INV-002`, `TASK-INV-003` and `TASK-INV-009` are `REVIEW`. FR: FR-INV-001, FR-INV-002, FR-INV-003, FR-INV-009 (and the groundwork of FR-INV-004: the stock ledger). NFR/BR: append-only stock facts (RULES 00 rule 6), property scope, audit, optimistic locking. ADR: none new.
- Context: the new bounded context `InventoryPurchasing` (`app/Modules/InventoryPurchasing`), tables from migrations 51 and 52, screens under `/inventory` (Stock, Items, Locations and categories), module entry **Inventory** in the navigation.
- Choices that need the owner, recorded as configurable baselines:
  - **Item**: code (unique per property, upper case, fixed), name, **category** (a master list the hotel keeps), **department** (a fixed baseline list: Front Office, Housekeeping, Laundry, Food and Beverage, Kitchen, Maintenance, HR, Finance, Purchasing, General; more through a change request) and **base unit** (fixed when the item is created, because the ledger keeps every quantity in it; a database trigger refuses a change). An item and a location are deactivated, never deleted.
  - **Locations**: code, name and kind (main store, bar, kitchen, housekeeping, engineering, galley, other).
  - **Quantities** are whole thousandths of a unit (three decimals, no floats); a factor is how many base units one unit holds, from 0.001 to 1,000,000; the base equivalent is rounded half away from zero; at most one million units in one posting.
  - **Unit conversions are versioned (FR-INV-009)**: a new factor is a new row with the next version and a reason, never an edit (triggers refuse update and delete); every movement stores the unit, the quantity in it, the factor and version used, and the base equivalent, so a later factor never changes what was posted.
  - **Opening stock** is posted once per item and location, before anything else, by a person with `inventory.stock.post`; the item row is locked while it is posted so two postings run one after the other. Receipts, issues, transfers, counts and adjustments (FR-INV-004, -005, -006, -010, -012, FR-PUR-*) follow in the next slices.
  - **Minimum and maximum** per item and location (maximum optional); below the minimum the stock screen, the home page and the **dashboard alert** `stock_below_minimum` show it (FR-INV-003); above the maximum is shown as a warning on the stock screen.
- Permissions: `inventory.catalog.manage` (items, locations, categories, units, limits), `inventory.catalog.view`, `inventory.stock.view`, `inventory.stock.post`.
- Evidence: `tests/Unit/Modules/InventoryPurchasing/StockQuantityTest.php`, `tests/Feature/InventoryPurchasing/InventoryHttpTest.php` (conversion versions never edited, opening stock once and with its factor kept, append-only triggers, limits and status, dashboard alert, permissions, property scope), `resources/js/modules/inventory-purchasing/lib/quantity.test.ts`, and a browser run of the whole flow in English and Indonesian.

### Slice 2 (2026-10-02): stock movements, negative-stock policy, transfers with hand-over

- Status: `TASK-INV-005` is `REVIEW`. `TASK-INV-004` and `TASK-INV-010` are `IN_PROGRESS`: FR-INV-004 still needs its automatic feeders (POS sales, kitchen, housekeeping and engineering usage) that depend on modules not built yet; FR-INV-010 still needs threshold-based approval, which waits for valuation (FR-INV-007). NFR/BR: BR-005, BR-007, append-only ledger, idempotency, audit, optimistic locking.
- Context: migration 53, `StockPoster` (the single place that writes a movement), `StockMovementService` (receipt, issue, adjustment, write-off), `StockTransferService`, screens Stock (movement dialog) and Transfers, and the negative-stock flag on categories and locations.
- Choices recorded as configurable baselines:
  - **Movement kinds**: opening, receipt, adjustment in (positive); issue, adjustment out, write-off, transfer out (negative); transfer in (positive). Outflows are stored negative; a database constraint checks the sign per kind and that adjustments and write-offs carry a reason code.
  - **Reason codes** are a fixed baseline list: adjustment (count correction, found, system correction, other), write-off (damaged, expired, lost, spoiled, other); issues carry the receiving department (the fixed department list).
  - **Negative stock**: a category or a location can be marked `negative_blocked`, which is a hard stop. Otherwise a posting that would go below zero is refused unless the person holds `inventory.stock.negative` and gives a reason; the reason is kept on the movement. Transfers never go below zero.
  - **Source documents (idempotency)**: a movement from another document (source type + reference) can be posted once per item and location; a repeat returns the first movement instead of posting twice. Manual postings also accept an idempotency key.
  - **Adjustments and write-offs** need `inventory.stock.adjust` and a reason code. Approval by value threshold is deferred until valuation exists (FR-INV-007); until then every adjustment is audited and attributable.
  - **Transfers (FR-INV-005)**: the sender creates a transfer document (`TRF-nnnnnn`) with lines in any unit; **stock moves only when the receiver confirms**, as two movements (transfer out, transfer in) in one transaction. The sender cannot confirm their own transfer (two-person rule); the receiver may reject with a note and the sender may cancel while it waits. A decided transfer is immutable (triggers).
- Permissions: `inventory.stock.adjust`, `inventory.stock.negative`, `inventory.transfer.send`, `inventory.transfer.receive`, plus the slice 1 ones.
- Evidence: `tests/Feature/InventoryPurchasing/StockMovementHttpTest.php` (13 tests: sign and reason rules, negative block and override, source idempotency, units and factor kept, two-person transfers, reject/cancel, atomic movement pair, permissions, property scope) and a browser run through movements, override, adjustment, write-off and a two-user transfer in both languages.

### Slice 3 (2026-10-02): stock valuation by moving average (FR-INV-007)

- Status: `TASK-INV-007` is `REVIEW`. It also unblocks the value part of FR-INV-006 (loss value of a count difference) and the threshold approval of FR-INV-010. NFR/BR: append-only ledger, integer money (ADR-0006), audit, property scope.
- Context: migration 54 (`value_minor`, `unit_cost_minor` on `stock_movements`, a check that the value has the sign of the quantity), `StockValue` (exact `a*b/c` without floats), costing inside `StockPoster`, the report page `/inventory/valuation`, a value column on Stock and its movements.
- Choices recorded as configurable baselines:
  - **Method**: moving average **per item over the whole property** (not per location), the common baseline for a hotel with several stores. Value is kept per location, and a transfer carries the value it left with, so moving stock never changes the total.
  - **Inflows carry a cost**: opening and receipt need the cost of one posted unit (whole amount in the property currency, at most 100,000,000); adjustment in uses the current average unless a cost is given, and needs one when the item has no cost yet. Value of a posting = quantity x cost, rounded half up.
  - **Outflows** (issue, adjustment out, write-off, transfer out) are taken out at the average of the moment; the last unit takes exactly the value left, so no rounding remains. When no stock is left to average, the cost of the last inflow is used, so stock that went negative is valued too and a correction at that cost restores value with quantity.
  - **Report**: quantity, average cost per base unit and value per item and location at the end of a chosen business date, with the total. It is a sum over the ledger, so a past date always gives the same figures.
  - **Costs are sensitive**: seeing values needs `inventory.valuation.view` (separate from seeing quantities); entering a cost on a receipt does not.
- Not yet: valuation by FIFO or last purchase price, per-location average, revaluation, and the link of receipts to purchase prices (comes with purchasing).
- Evidence: `tests/Unit/Modules/InventoryPurchasing/StockValueTest.php`, `tests/Feature/InventoryPurchasing/StockValuationHttpTest.php` (cost on inflows, average on outflows, last unit empties the value, rounding, negative stock, transfers keep the total, adjustments, ledger sign constraint, report at a date, privilege), and the browser run of Stock, the cost field and the valuation page.

### Slice 4 (2026-10-02): stock opname with a start snapshot (FR-INV-006, FR-INV-012)

- Status: `TASK-INV-006` and `TASK-INV-012` are `REVIEW`. NFR/BR: BR-006 (document number `OPN-nnnnnn`, unique per property, never reused), append-only ledger, audit, two-person control, property scope.
- Context: migration 55 (`stock_counts`, `stock_count_lines`, triggers that make a decided count and a snapshot immutable), `StockCountService` and `StockCountStore`, screens Stock counts (list, start) and the count sheet / minutes, permissions `inventory.count.manage` (start, count, hand in, cancel) and `inventory.count.approve` (review).
- Choices recorded as configurable baselines:
  - **Snapshot at the start** (FR-INV-012): starting a count locks the items (in one order, as transfers do) and freezes the system quantity of each item that has ledger history in the location, optionally only one category (a cycle count). Scheduled and spot counts are the same document with a different kind and an optional planned date.
  - **The difference is counted minus snapshot**, never minus the balance on the day of approval: people counted the shelf as it was when the count began. Movements posted meanwhile stay in the ledger untouched and the adjustment is added on top, so a receipt or an issue during the count is never a false difference. The sheet shows "moved since start" (current balance minus snapshot) for the reviewer.
  - **Blind count**: while it is being counted, the snapshot is hidden from everyone who cannot review. Counters enter the quantity in the base unit or any unit of the item (the factor of the day is kept), with zero where nothing is found; every line needs a number before the count is handed in. Two people saving at once are caught by the version of the count.
  - **Review by a second person**: whoever hands the count in cannot review it. The reviewer approves or sends it back with a reason for a recount; a count can be cancelled with a reason while it is being counted or waits for review. Approving posts each difference as an adjustment (reason code from the line, default count correction) tied to the count by source type `count`, once, in one transaction; the movement and its value are kept on the line.
  - **Value of the loss** (FR-INV-006): the value of a difference is that of its adjustment movement, at the moving average of the moment (FR-INV-007). Before approval the sheet shows an estimate at today's average; the minutes show surplus, shortage and net.
  - **Berita acara**: the count page is the minutes once it is handed in: lines with system, counted, difference and value, totals, and the signature block (counted by, reviewed by, started by), with a Print action.
- Not yet: threshold-based approval of large differences by value (FR-INV-010, now possible because stock has a value; today the second-person review is the control), counting items without ledger history, and batch/expiry.
- Evidence: `tests/Feature/InventoryPurchasing/StockCountHttpTest.php` (snapshot and blind count, category filter, idempotent start, unit and factor kept, stale save refused, hand-in needs every line, review by someone else with adjustments and value, movements during the count are not differences, send back and cancel post nothing, immutability triggers, permissions and property scope) and the browser run of a two-person count in both languages.

### Slices 5 to 10 (2026-10-02): purchasing from supplier to payable, and returns

- Status: `TASK-PUR-001` to `-007`, `-009` to `-013` and `TASK-INV-011` are `REVIEW`. `TASK-PUR-008` is `IN_PROGRESS`: the stock part is done, and the payable is recorded as supplier ledger facts and an outbox event, but the Finance context (AR/AP) does not exist yet, so nothing consumes them. NFR/BR: BR-003 (documents are never changed; corrections point back), BR-004 (approval and no self-approval), BR-005 (idempotent), BR-006 (numbers PR, PO, GR, SI, RTV), BR-002 (integer money, prices before tax).
- Context: migrations 56 to 61, services `SupplierService`, `PurchaseRequestService`, `PurchaseOrderService`, `PurchasingSettingsService`, `GoodsReceiptService`, `SupplierInvoiceService`, `PurchaseReturnService`, `SupplierQuoteService`, `PurchasingReportService` over one `PurchasingStore`, `PurchasingAccess` for the privileges (`purchasing.*`), and the screens under Inventory: Suppliers, Requests, Orders, Receipts, Invoices, Returns, Quotes, the two reports and the purchasing policy.
- The flow: **supplier** (contact, payment terms, NPWP, a price list that is never edited, ratings) -> **purchase request** by a department (items, quantities, reason, urgency, needed-by) -> approval by the chain the owner configures for its value (approval subjects `inventory.purchase-request` and `inventory.purchase-order`; no policy for an amount means no approval; the maker releases the decision) -> **purchase order** from approved request lines or typed, priced from the supplier price list, prices before tax and a tax rate kept on the order (11% PPN to start) -> **issued** -> **goods receipt** (accepted and refused quantities, reason, condition, expiry date captured, photos added afterwards) -> **supplier invoice** matched to the order and the receipt -> payable facts.
- Choices recorded as configurable baselines (purchasing policy, one row per property): budget policy per department and month **warn** (off, warn or block); tolerance under which a revision of an approved order needs no new approval **5%**; over-receipt tolerance **10%**; invoice price tolerance **1%** and quantity tolerance **0%**.
  - **Revisions (FR-PUR-011)**: after approval a change is a numbered revision with its reason and a snapshot. A change of supplier, of the lines, or beyond the tolerance in value or in a quantity goes through approval again when a policy applies to the new value, and takes effect only when approved; a rejected revision leaves the order as it was. A quantity cannot go below what was received, and a line with goods received cannot be removed.
  - **Budget (FR-PUR-013)**: the budget of a department for a month is compared with what orders (not draft, not cancelled) commit before tax; a request or an order that would go over it is warned about or refused, by policy. No budget set means no check.
  - **Receipts (FR-PUR-006, -008)**: the accepted quantity goes into the stock ledger as a receipt at the price of the order line (so stock is valued before tax); refused goods never enter stock and still have to be delivered; a partial delivery keeps the order open; more than ordered is accepted only within the tolerance.
  - **Payable facts (FR-PUR-008)**: goods received add the accepted value to what is owed to the supplier (an accrual); a recognised invoice takes the accrual it replaces off and puts the whole invoice on; a return takes its value off. These are append-only supplier ledger entries plus outbox events (`purchasing.goods.received`, `purchasing.invoice.recognised`, `purchasing.return.posted`) for Finance to consume; nothing here is a payment.
  - **Invoices (FR-PUR-012, -007)**: the supplier's number is compared without spacing, punctuation or case, per supplier, so the same invoice cannot be entered twice; the date cannot be in the future; the lines and tax must add up to the stated total. The match flags more quantity than was received and not yet invoiced (goods returned before invoicing are not invoiceable), a price beyond the tolerance, a tax that differs from the order's rate, and tax without a tax invoice number. A clean invoice is recognised at once; one with differences waits and someone other than the person who entered it approves it (recognised) or rejects it (nothing recognised, its quantities are free again), always with a note.
  - **Returns (FR-INV-011)**: a return to a supplier is made against a receipt line, up to what it accepted less what already went back, takes the stock out of the location it was received into at what it cost on the receipt (a new ledger kind, `return_out`), only if the location still has it, and takes the same value off what is owed; the supplier's credit note number and the tax it credits are recorded with it. A return between locations is a transfer that points to the received transfer it returns, goes the other way and is limited to what that transfer moved less what other returns already took.
  - **Reports (FR-PUR-009, -010)**: purchases by department, supplier and item in base-unit quantity and money, received less returned, per period; per supplier the orders on time and late, the average days late, the fill rate and the refusal rate. **Quotations (FR-PUR-005)**: quotations are only added; the comparison brings the price lists and the valid quotations of the active suppliers to one base unit, cheapest first, with terms and rating.
- Not yet: payment of invoices and the accounts-payable ledger (Finance), batch and expiry control in stock (the expiry date is only captured on the receipt), supplier credit notes as their own document, and order lines split across several departments.
- Evidence: `tests/Feature/InventoryPurchasing/{SupplierHttpTest,PurchasingHttpTest,GoodsReceiptHttpTest,SupplierInvoiceHttpTest,ReturnHttpTest,PurchasingReportHttpTest}.php` (about 115 tests: approval flow and rejection, revisions with and without approval, budget policies, partial and refused receipts, the stock value at the order price, duplicate invoices, the match and its decision by a second person, returns against receipts and transfers, immutability triggers, privileges and property scope), `StockValueTest`, and browser runs of each screen in both languages.
