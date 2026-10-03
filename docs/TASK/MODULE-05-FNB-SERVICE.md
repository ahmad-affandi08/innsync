# F&B Service — Task Contract

**Bounded context:** F&B Sales
**Critical note:** POS/payment/room-charge/offline and cashier-shift controls are critical.

## Candidate aggregates / read models

- `PosBill`
- `CashierShift`
- `OutletOrder`
- `RoomChargeRequest`

## Requirement backlog

| Task ID | FR | Priority | Requirement | Status |
| --- | --- | --- | --- | --- |
| TASK-FBS-001 | FR-FBS-001 | Wajib | Menampilkan denah meja per outlet dengan status kosong, terisi, dan sudah memesan; kasir dapat membuka bill dari meja atau dari nomor kamar. | IN_PROGRESS |
| TASK-FBS-002 | FR-FBS-002 | Wajib | Mengambil pesanan dengan katalog menu bergambar, kategori, varian, catatan khusus, dan jumlah porsi. | IN_PROGRESS |
| TASK-FBS-003 | FR-FBS-003 | Wajib | Mengirim pesanan ke layar dapur dan bar sesuai kategori item, serta mencetak tiket pada printer masing-masing bila diperlukan. | IN_PROGRESS |
| TASK-FBS-004 | FR-FBS-004 | Sebaiknya | Mendukung pemisahan bill, penggabungan bill, dan pemindahan pesanan antar meja. | TODO |
| TASK-FBS-005 | FR-FBS-005 | Wajib | Void item dan pembatalan bill hanya dapat dilakukan dengan alasan dan persetujuan penyelia; seluruh tindakan tercatat pada jejak audit. | IN_PROGRESS |
| TASK-FBS-006 | FR-FBS-006 | Wajib | Diskon dan pemberian gratis (complimentary) memerlukan alasan dan persetujuan sesuai ambang yang dikonfigurasi. | TODO |
| TASK-FBS-007 | FR-FBS-007 | Wajib | Menerima pembayaran tunai, QRIS, kartu melalui EDC, dan pembebanan ke kamar. Pembebanan ke kamar wajib memvalidasi bahwa kamar berstatus terisi dan mencocokkan nama tamu. | REVIEW |
| TASK-FBS-008 | FR-FBS-008 | Wajib | Menghitung pajak dan service charge secara otomatis sesuai konfigurasi per outlet dan menampilkannya terpisah pada struk. | REVIEW |
| TASK-FBS-009 | FR-FBS-009 | Wajib | Membuka dan menutup shift kasir dengan penghitungan kas fisik, kas sistem, serta pencatatan selisih beserta alasan. | REVIEW |
| TASK-FBS-010 | FR-FBS-010 | Wajib | POS tetap dapat mencatat transaksi saat jaringan terputus melalui antrean lokal terenkripsi. Setiap transaksi memiliki idempotency key dan status sinkronisasi sehingga pemulihan jaringan tidak menghasilkan bill, pembayaran, atau pengurangan stok ganda. | TODO |
| TASK-FBS-011 | FR-FBS-011 | Wajib | Mendukung modifier/add-on, tingkat kematangan, pilihan varian, dan catatan khusus yang dapat memengaruhi harga dan resep tanpa membuat item menu duplikat. | IN_PROGRESS |
| TASK-FBS-012 | FR-FBS-012 | Wajib | Perubahan bill oleh beberapa perangkat menggunakan kontrol konkurensi; sistem mencegah lost update dan menampilkan konflik bila bill telah berubah di perangkat lain. | REVIEW |
| TASK-FBS-013 | FR-FBS-013 | Wajib | Pembayaran QRIS/daring memiliki state initiated, pending, paid, failed, expired, unknown, dan refunded. Status unknown tidak boleh dianggap lunas sebelum rekonsiliasi atau callback valid diterima. | IN_PROGRESS |
| TASK-FBS-014 | FR-FBS-014 | Wajib | Refund, void setelah pembayaran, dan reprint struk memerlukan hak akses sesuai kebijakan, alasan, serta referensi transaksi awal pada audit trail. | TODO |
| TASK-FBS-015 | FR-FBS-015 | Sebaiknya | Mendukung price list dan jadwal harga per outlet/channel/waktu, termasuk promo terjadwal, tanpa mengubah histori harga transaksi yang sudah ditutup. | TODO |
| TASK-FBS-020 | FR-FBS-020 | Wajib | Petugas memeriksa mini bar di kamar tamu dengan memindai barcode kamar lalu memilih menu mini bar. | TODO |
| TASK-FBS-021 | FR-FBS-021 | Wajib | Jumlah minuman dan makanan yang dikonsumsi tamu diinput di tempat dan otomatis terkirim ke kasir serta folio kamar tanpa input ulang. | TODO |
| TASK-FBS-022 | FR-FBS-022 | Wajib | Sistem menghasilkan daftar jumlah item yang harus diisi ulang per kamar untuk shift berikutnya. | TODO |
| TASK-FBS-023 | FR-FBS-023 | Wajib | Riwayat pengisian dan konsumsi mini bar tersimpan per kamar dan per petugas untuk keperluan audit. | TODO |
| TASK-FBS-024 | FR-FBS-024 | Wajib | Mencatat pesanan room service dengan nomor kamar, waktu janji pengantaran, dan status pengantaran. | TODO |
| TASK-FBS-025 | FR-FBS-025 | Sebaiknya | Sistem memblokir pembebanan mini bar setelah folio kamar ditutup dan mengarahkannya ke prosedur late charge. | TODO |
| TASK-FBS-030 | FR-FBS-030 | Wajib | Mengelola persediaan outlet (bar dan gudang outlet) beserta permintaan barang ke gudang utama. | TODO |
| TASK-FBS-031 | FR-FBS-031 | Wajib | Melakukan stock opname harian untuk minuman dan bahan bar dengan pencatatan selisih. | TODO |
| TASK-FBS-032 | FR-FBS-032 | Wajib | Menampilkan SOP tugas harian, mingguan, dan bulanan outlet beserta persentase penyelesaian yang dikirim ke Human Resource. | TODO |
| TASK-FBS-033 | FR-FBS-033 | Wajib | Membuat laporan kerusakan yang diteruskan ke modul Maintenance. | TODO |
| TASK-FBS-034 | FR-FBS-034 | Wajib | Mengajukan permintaan pembelian alat dan bahan ke modul Purchasing. | TODO |

## Progress notes

### Slice 20 (2026-10-03): outlets, tables and the menu (the setup the POS stands on)

- Status: `TASK-FBS-001`, `-002`, `-008` and `-011` are `IN_PROGRESS`: their setup half is built (outlets and tables, the menu with variants and groups of choices, the charge scheme per outlet); taking orders, the floor plan with live status, bills and the receipt come in the next slices. ADR/BR: BR-005 (charges are computed by the scheme of a scope, effective-dated), property scope, append-only history of what a bill priced.
- Context: new bounded context `FnbSales` (`app/Modules/FnbSales`), migration 71 (`fnb_outlets`, `fnb_tables`, `fnb_menu_categories`, `fnb_menu_items`, `fnb_item_variants`, `fnb_modifier_groups`, `fnb_modifiers`, `fnb_item_modifier_groups`), `SetupStore` with `DatabaseSetupStore`, `OutletService`, `MenuService`, `SetupController`, routes under `/fnb`, pages `fnb-sales/pages/outlets|tables|menu`. Permissions: `fnb.setup.manage` (sets everything up) and `fnb.pos.operate` (sees the setup and marks items sold out).
- **Outlets.** A short code that never changes, a kind (restaurant, bar, cafe, room service, banquet, other), the revenue scope whose service charge and tax the outlet follows (`fnb` is a new scope under property tax, effective-dated like `rooms` and `laundry`; the owner defines its scheme there) and whether its prices already include them. Baseline: prices are quoted without service charge and tax, as is usual on hotel menus. Outlets and tables are deactivated, never removed.
- **Menu.** Categories per outlet carry the station that prepares what is in them (kitchen, bar or none), so a ticket will reach the right screen; an item may override the station. An item has a price, sizes or kinds (variants) with their own price, and takes groups of choices (add-ons, doneness) with a least and most to pick and an extra price per choice, so one dish is one item however it is ordered. Nothing is removed: variants and choices left out of an edit are deactivated, and put back by the same name as the same row. Price changes are audited (before and after).
- **Sold out.** Whoever takes orders may mark an item sold out or on sale again (audited); only the menu's owner changes the rest. The kitchen will use the same switch (`TASK-KIT-005`).
- Not yet: bills and the floor plan (`FBS-001` live status), pictures of the dishes, price lists and schedules per outlet or channel (`FBS-015`), recipes that make a variant or a choice consume stock (`KIT-003`, `KIT-004`), and the revenue source mapping of an outlet to the reporting outlets (the owner does it where the revenue outlets are mapped, as for laundry).
- Evidence: `tests/Feature/FnbSales/SetupHttpTest.php` (outlet setup with lock and audit, the fnb scheme can be defined, tables unique per outlet, variants with own price and their deactivation and return, groups of choices with their limits, sold out by a waiter, permissions, property scope). Seen in the browser: outlet, table, category, group and item created, item marked sold out.

## Required engineering checks

- Identify aggregate owner and state transition before coding.
- Enforce property scope and server-side authorization.
- Define transaction/idempotency/concurrency behavior where mutation is critical.
- Emit audit evidence for sensitive/state-changing operations.
- Add happy, negative, conflict/retry, and permission tests as applicable.
- Update traceability/evidence before marking DONE.

### Slice 21 (2026-10-03): the floor, bills, ordering, sending, void and cancel

- Status: `TASK-FBS-012` is `REVIEW` (a bill changed by several devices is protected by its version). `TASK-FBS-001`, `-003`, `-005`, `-008` and `-011` stay `IN_PROGRESS`: the floor plan with live status, ordering with variants and choices, sending, void and cancel with approval, and the charges of a bill are built; payment, the receipt, the kitchen screen, discounts, refund and the recipes come in the next slices. ADR/BR: BR-004 (maker-checker), BR-005 (charges by scheme), property scope, no hard delete of financial facts.
- Context: migration 72 (`fnb_bills` with a generated `open_table_id` and its unique index, `fnb_bill_lines`, `fnb_order_batches`), `BillStore` with `DatabaseBillStore`, `BillPricing`, `BillService`, `BillController`, pages `fnb-sales/pages/floor` and `bill`, routes `/fnb/pos`, `/fnb/bills/...`. Approval subjects `fnb.item.void` and `fnb.bill.cancel` are declared **mandatory** in `config/approvals.php` (FR-FBS-005): with no policy configured a void of a sent line is refused, never allowed; the owner configures who approves under approval policies.
- **Floor.** Tables of an outlet as free, seated (open bill, nothing sent) and ordered (something sent), and the open bills including those of a room or the counter. A bill is opened from a table (one open bill per table, enforced by the database, so two devices cannot both open one), from a room that has a guest in it (the stay is checked through `GuestCharging`), or over the counter. Numbers are `BILL-000001`….
- **Ordering.** A line copies the item's name, the variant's price, the chosen choices and their extra price and the station, so a later change of the menu never changes a bill. Refused: an item of another outlet or out of use, a sold-out item, an item that has variants without one chosen (or a variant it has not), choices that do not satisfy the least and most of their groups or do not belong to the item, and 0 or more than 99 portions. The charges of a bill are those of the scheme of the outlet's scope on the business date of the bill (service charge and tax shown apart, prices with or without them as the outlet says); a bill whose scheme is not configured is shown with its lines and flagged.
- **Sending.** The pending lines go as one numbered batch; it is audited and published as `fnb.order.sent` (lines with their station) for the kitchen and bar screens (`TASK-KIT-001`). A line that was not sent is taken off freely (kept as `removed`).
- **Void and cancel.** A sent line is voided, and a bill that has sent lines is cancelled, with a reason and an approved request of the policy; the approval is for that line or bill only and is used once; the audit entry names the reason and the approval. A bill that never reached a station is cancelled at once with a reason. Voids and cancellations publish `fnb.line.voided` and `fnb.bill.cancelled` so stock and the kitchen can follow.
- **Two devices.** Every change names the version of the bill the person saw; a change on an older version is refused with a conflict and the person is asked to reload, so nothing is overwritten (`FR-FBS-012`). Writes are idempotent by key.
- Not yet: payment (cash, QRIS, card, room charge) and cashier shifts, the receipt, splitting, merging and moving bills (`FBS-004`), discounts and complimentary (`FBS-006`), refund and reprint (`FBS-014`), offline queue (`FBS-010`), the kitchen screen and stock consumption by recipe.
- Evidence: `tests/Feature/FnbSales/BillHttpTest.php` (opening from a table, room and counter and the one-open-bill rule, pricing from variants and choices and the charges, what ordering refuses, removal and batches, void with the mandatory policy and approval, cancel before and after sending, two devices, permissions, property scope). Seen in the browser: floor, opening a table, ordering a steak with a variant and doneness, sending, a waiter asking for a void, a supervisor approving it and the void completing.

### Slice 22 (2026-10-03): cashier shifts, payments and settlement

- Status: `TASK-FBS-007`, `-008` and `-009` are `REVIEW`; `TASK-FBS-013` is `IN_PROGRESS` (the states and the rule that an unknown payment is not paid are built; a payment provider callback is not, since none is chosen). ADR/BR: BR-005 (charges by scheme, once per bill to a folio), BR-004, append-only facts, idempotent writes.
- Context: migration 73 (`fnb_cashier_shifts` with a generated `open_cashier_id` and its unique index, `fnb_payments`, and the totals a settled bill keeps on `fnb_bills`), `PaymentStore` with `DatabasePaymentStore`, `ShiftService`, `PaymentService`, `BillGuard` (the lock and version check every bill change starts with), `PaymentController`, page `fnb-sales/pages/shift`, the payment panel and receipt on the bill page. New permission `fnb.cashier.operate` (a waiter has only `fnb.pos.operate`). `GuestCharging::inHouseStayOfRoom` also returns the guest name on the reservation.
- **Shift (FBS-009).** A cashier opens a shift with the float and has one open at a time; payments are taken only while it is open, into that shift. Closing counts the cash against the expected (float plus the cash taken for bills): any variance needs a reason, the variance is kept, a shift with a QRIS payment not decided yet cannot be closed, and the closing publishes `fnb.cashier.shift.closed` with what was taken by method (for finance to receive the cash). A shift is closed by the cashier who opened it; managers see the recent shifts.
- **Payments (FBS-007).** Several payments may add up to a bill; it is settled when what was paid reaches its total and then keeps the figures it came to (base, service charge, tax, total and the scheme), so a later change of the scheme or menu never changes it. Cash gives change (the amount counted is what the bill takes); a card payment needs the approval code of the terminal; a QRIS payment is created initiated; a room charge is for the whole bill, needs a room that has a guest in it, and the name the guest gives must match the name on the reservation (at least three letters, every word a word or the start of one); the front office posts the charge to the guest's folio once per bill (source `pos_<outlet code>`) and the amounts must agree. Nothing is paid while lines wait to be sent, nothing is ordered, or the outlet's scheme is not configured.
- **QRIS (FBS-013).** initiated → pending, paid, failed, expired or unknown; pending → paid, failed, expired or unknown; unknown → paid (with the transaction reference and how it was reconciled), failed or expired; paid, failed and expired are final. Only a paid payment counts; one that is initiated, pending or unknown keeps its amount reserved so the bill is not over-collected, and a failed or expired one gives it back. Each move is audited with its reason.
- **Receipt (FBS-008).** A settled bill shows its lines, the service charge and the tax apart, the payments and the change, and prints (the menu and buttons stay off the paper).
- **Void and cancel.** A bill that has payments is not voided or cancelled: the payment is refunded first (refund is the next slice).
- Facts published: `fnb.bill.settled` (the figures, the payments by method, the lines sold with their items, business date) for finance and for stock by recipe, and `fnb.cashier.shift.closed`. Finance does not consume them yet.
- Not yet: finance booking the settled bills and the shifts' cash (revenue by outlet and method, cash deposit), refund and reprint (`FBS-014`), discounts and complimentary (`FBS-006`), splitting and merging (`FBS-004`), a QRIS provider callback and its reconciliation, the offline queue (`FBS-010`), cash drops during a shift.
- Evidence: `tests/Feature/FnbSales/PaymentHttpTest.php` (one shift per cashier and no payment without it, cash with change and the settled snapshot, split payments and the card code, QRIS states and reservation of the amount, a room charge with the name match and one folio posting, closing the shift with variance and its reason, the name matching). Seen in the browser: opening a shift, ordering, sending, paying cash with change, paying by QRIS and marking it paid with a reference, and the shift totals.
