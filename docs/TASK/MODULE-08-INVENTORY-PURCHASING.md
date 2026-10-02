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
| TASK-INV-004 | FR-INV-004 | Wajib | Mencatat mutasi stok otomatis dari penjualan POS, pemakaian dapur, pemakaian housekeeping, dan pemakaian teknik. | TODO |
| TASK-INV-005 | FR-INV-005 | Wajib | Melakukan pemindahan barang antar gudang dengan dokumen serah terima dan konfirmasi penerima. | TODO |
| TASK-INV-006 | FR-INV-006 | Wajib | Melakukan stock opname terjadwal maupun mendadak, membandingkan stok fisik dengan stok sistem, dan menghasilkan berita acara selisih beserta nilai kerugian. | TODO |
| TASK-INV-007 | FR-INV-007 | Sebaiknya | Menghitung nilai persediaan menggunakan metode rata-rata bergerak dan menyajikannya sebagai laporan nilai persediaan per tanggal. | TODO |
| TASK-INV-008 | FR-INV-008 | Sebaiknya | Mengelola tanggal kedaluwarsa dan nomor batch untuk barang konsumsi. | TODO |
| TASK-INV-009 | FR-INV-009 | Wajib | Konversi satuan bersifat berversi dan tidak boleh mengubah histori transaksi; setiap mutasi menyimpan kuantitas satuan transaksi dan ekuivalen satuan dasar. | REVIEW |
| TASK-INV-010 | FR-INV-010 | Wajib | Stock adjustment, write-off, dan pembukaan stok negatif memerlukan reason code dan otorisasi sesuai threshold. Kebijakan stok negatif dapat diblokir per kategori/lokasi. | TODO |
| TASK-INV-011 | FR-INV-011 | Wajib | Mendukung retur ke pemasok dan retur antar gudang dengan dokumen referensi sehingga stok, hutang/kredit, dan histori barang tetap dapat direkonsiliasi. | TODO |
| TASK-INV-012 | FR-INV-012 | Wajib | Stock opname menggunakan snapshot waktu mulai; mutasi selama opname tetap tercatat dan sistem menghitung expected quantity yang konsisten untuk mencegah selisih semu. | TODO |
| TASK-PUR-001 | FR-PUR-001 | Wajib | Setiap department mengajukan permintaan pembelian (purchase request) berisi barang, jumlah, alasan, dan tingkat urgensi. | TODO |
| TASK-PUR-002 | FR-PUR-002 | Wajib | Permintaan melewati alur persetujuan berjenjang yang dapat dikonfigurasi berdasarkan nilai nominal. | TODO |
| TASK-PUR-003 | FR-PUR-003 | Wajib | Purchasing menggabungkan permintaan yang disetujui menjadi pesanan pembelian (purchase order) kepada pemasok terpilih. | TODO |
| TASK-PUR-004 | FR-PUR-004 | Wajib | Mengelola data pemasok dan vendor secara terpisah dari data barang, meliputi kontak, syarat pembayaran, daftar harga, dan riwayat penilaian. | TODO |
| TASK-PUR-005 | FR-PUR-005 | Sebaiknya | Membandingkan penawaran harga dari beberapa pemasok untuk barang yang sama sebelum penerbitan pesanan. | TODO |
| TASK-PUR-006 | FR-PUR-006 | Wajib | Mencatat penerimaan barang beserta jumlah diterima, jumlah ditolak, kondisi, dan foto; penerimaan sebagian didukung. | TODO |
| TASK-PUR-007 | FR-PUR-007 | Wajib | Mencocokkan tiga dokumen: pesanan pembelian, bukti penerimaan barang, dan faktur pemasok; selisih ditandai untuk ditindaklanjuti. | TODO |
| TASK-PUR-008 | FR-PUR-008 | Wajib | Penerimaan barang otomatis menambah stok gudang tujuan dan membentuk hutang kepada pemasok di modul Finance. | TODO |
| TASK-PUR-009 | FR-PUR-009 | Wajib | Menerbitkan laporan pembelian per department dalam bentuk jumlah barang maupun nilai uang, per periode dan per pemasok. | TODO |
| TASK-PUR-010 | FR-PUR-010 | Sebaiknya | Menerbitkan laporan penerimaan barang dan laporan ketepatan waktu pengiriman pemasok. | TODO |
| TASK-PUR-011 | FR-PUR-011 | Wajib | Perubahan PO yang sudah disetujui menghasilkan revisi bernomor dan memerlukan persetujuan ulang bila mengubah nilai, pemasok, atau kuantitas di atas toleransi. | TODO |
| TASK-PUR-012 | FR-PUR-012 | Wajib | Faktur pemasok mencatat nomor unik pemasok, tanggal, pajak, dan dokumen pendukung; sistem mencegah duplikasi invoice dan menjaga relasi ke PO serta penerimaan barang. | TODO |
| TASK-PUR-013 | FR-PUR-013 | Sebaiknya | Permintaan dan PO menampilkan sisa budget department; kebijakan dapat berupa warning atau hard block sesuai threshold yang dikonfigurasi. | TODO |

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
