# Dashboard Manajemen — Task Contract

**Bounded context:** Reporting & Dashboard
**Critical note:** Read models/KPIs only; every metric traceable to source.

## Candidate aggregates / read models

- `DashboardProjection`
- `RoomBoardProjection`
- `AlertProjection`

## Requirement backlog

| Task ID | FR | Priority | Requirement | Status |
| --- | --- | --- | --- | --- |
| TASK-DSH-001 | FR-DSH-001 | Wajib | Menampilkan kartu okupansi hari berjalan: jumlah kamar terisi, jumlah kamar tersedia, jumlah tamu menginap, kedatangan hari ini, keberangkatan hari ini, dan reservasi masuk. Angka bersumber dari Front Office dan Housekeeping. | REVIEW |
| TASK-DSH-002 | FR-DSH-002 | Wajib | Menampilkan room board dengan dimensi status yang terpisah: occupancy (vacant/occupied), housekeeping (dirty/clean/inspected), sellability (sellable/OOO/OOS), serta service flag seperti DND/Double Lock. Complimentary ditampilkan sebagai atribut tarif/folio, bukan status kebersihan kamar. | REVIEW |
| TASK-DSH-003 | FR-DSH-003 | Wajib | Papan kamar bersifat template: administrator dapat menambah, mengubah, menonaktifkan kamar, menetapkan tipe, lantai, gedung, dan kapasitas tanpa bantuan pengembang. | REVIEW |
| TASK-DSH-004 | FR-DSH-004 | Wajib | Menampilkan pendapatan hari berjalan per outlet (Kamar, Restoran, Bar, Spa, Gift Shop, dan outlet tambahan yang dibuat pengguna) beserta total dan perbandingan terhadap hari, minggu, serta bulan sebelumnya. | REVIEW |
| TASK-DSH-005 | FR-DSH-005 | Wajib | Daftar outlet bersifat dapat diperluas; penambahan outlet baru otomatis muncul sebagai kolom pendapatan dan kategori pada laporan. | REVIEW |
| TASK-DSH-006 | FR-DSH-006 | Wajib | Menampilkan ringkasan pengeluaran: pembayaran kepada pemasok dan vendor yang telah dibayar, hutang berjalan, serta daftar jatuh tempo dalam 7 dan 30 hari ke depan. | REVIEW |
| TASK-DSH-007 | FR-DSH-007 | Wajib | Menampilkan peringatan stok minimum per department (Bar, Kitchen, Housekeeping, Maintenance, Galley, Reception) berdasarkan kartu stok dan hasil stock opname. | REVIEW |
| TASK-DSH-008 | FR-DSH-008 | Wajib | Menampilkan ringkasan kepegawaian hari berjalan: jumlah staf bertugas per shift per department, staf libur, staf ijin dengan keterangan, dan staf tanpa keterangan (alpha). | REVIEW |
| TASK-DSH-009 | FR-DSH-009 | Wajib | Menampilkan ringkasan pekerjaan pemeliharaan: work order berjalan, selesai hari ini, melewati batas waktu, dan kamar berstatus Out of Order. | REVIEW |
| TASK-DSH-010 | FR-DSH-010 | Sebaiknya | Menampilkan performa produk: sepuluh menu terlaris dan paling tidak laku, serta performa tipe kamar berdasarkan okupansi dan ADR pada periode terpilih. | REVIEW |
| TASK-DSH-011 | FR-DSH-011 | Sebaiknya | Menampilkan distribusi jam transaksi per outlet dalam bentuk grafik batang per jam untuk membantu penjadwalan staf. | REVIEW |
| TASK-DSH-012 | FR-DSH-012 | Sebaiknya | Menampilkan heatmap kedatangan tamu (check-in) berdasarkan jam dan hari dalam seminggu. | REVIEW |
| TASK-DSH-013 | FR-DSH-013 | Wajib | Menampilkan lini masa kewajiban pajak: pajak kamar, pajak restoran dan outlet lain, nilai terkumpul berjalan, tanggal jatuh tempo pelaporan, dan status pelaporan. | REVIEW |
| TASK-DSH-014 | FR-DSH-014 | Wajib | Menampilkan akumulasi service charge yang terkumpul dari kamar dan outlet beserta estimasi porsi yang akan didistribusikan kepada karyawan. | REVIEW |
| TASK-DSH-015 | FR-DSH-015 | Wajib | Menyediakan penyaring periode (hari ini, kemarin, 7 hari, bulan berjalan, rentang khusus) yang berlaku serentak pada seluruh kartu. | REVIEW |
| TASK-DSH-016 | FR-DSH-016 | Wajib | Setiap kartu dapat diklik untuk menelusuri hingga daftar transaksi atau dokumen sumbernya. | IN_PROGRESS |
| TASK-DSH-017 | FR-DSH-017 | Bisa | Susunan kartu dapat diatur per pengguna (urutan dan tampil/sembunyi) dan tersimpan pada profil pengguna. | REVIEW |
| TASK-DSH-018 | FR-DSH-018 | Sebaiknya | Data diperbarui otomatis paling lambat setiap 60 detik tanpa memuat ulang halaman, dengan penanda waktu pembaruan terakhir. | REVIEW |
| TASK-DSH-019 | FR-DSH-019 | Bisa | Tersedia mode layar televisi (tampilan besar tanpa navigasi) untuk dipasang di ruang manajemen. | REVIEW |
| TASK-DSH-020 | FR-DSH-020 | Wajib | Menyediakan pusat exception/alert untuk kondisi yang membutuhkan tindakan: reservasi berpotensi oversold, folio belum settle, pembayaran berstatus unknown, stok negatif atau kritis, work order lewat SLA, dan kegagalan sinkronisasi. | REVIEW |
| TASK-DSH-021 | FR-DSH-021 | Wajib | Setiap KPI menampilkan definisi, business date/periode, waktu data terakhir diperbarui, serta drill-down ke data sumber agar tidak terjadi perbedaan interpretasi antar department. | REVIEW |
| TASK-DSH-022 | FR-DSH-022 | Wajib | Dashboard menerapkan cakupan data berdasarkan property, outlet, department, dan role; pengguna hanya melihat angka yang diizinkan tanpa mengubah sumber data. | IN_PROGRESS |

### Slice 41 (2026-10-03): dashboard cards for spending, low stock by department, today's staffing and maintenance

- Status: `TASK-DSH-006`, `-007`, `-008` and `-009` are `REVIEW`. Each card is read-only, states its period or business date and links to where its numbers come from, and is shown only to people who hold a permission behind it. The new keys `spend`, `stock` and `maintenance` join the order and hidden-card preferences.
- **Spending (`DSH-006`).** Payments to suppliers and vendors made on the days of the chosen period (reversals taken off), what is still owed on supplier invoices, what is past due, what falls due in 7 and in 30 days, and the next due invoices. Seen by people with `finance.payable.view`, `.manage` or `finance.payment.record`.
- **Low stock (`DSH-007`).** Items below the minimum of a location, counted and listed by the department of the item, from the stock card (which counts correct). Seen with `inventory.stock.view` or `inventory.catalog.view`.
- **Staffing today (`DSH-008`).** The existing staff card now also says how many have the day off, who is on leave or a permit with its kind, and who was planned and did not come (absent). Seen by people with an HR privilege.
- **Maintenance (`DSH-009`).** Work orders still open, done on the business date, open ones past their due time, and the rooms out of order today. Seen with a maintenance work privilege.
- Evidence: `tests/Integration/Reporting/DashboardOperationsCardsTest.php` (who sees which card; the spending sums with an overdue, a near and a far invoice and a payment; low stock by department; maintenance counts and the room out of order), `LeaveHttpTest` (leave on the staff card), `DashboardPreferenceTest` (the card keys).

### Slice 55 (2026-10-03): products, busy hours and arrivals on the dashboard

- **DSH-010** — card `products` (revenue right): the ten dishes sold the most and the ten sold the least in the chosen period (bills that were settled, lines not voided or removed; a dish on the menu that sold nothing is among the least), with quantity, sales and outlet; and for each room type the room nights sold (night-audit charges, less reversals), the room revenue before service charge and tax, the average daily rate and the occupancy of its rooms. The room type is that of the room the stay is in.
- **DSH-011** — card `outlet_hours`: for each active outlet, the bills settled in each of the 24 hours of the day by the clock of the property, as a bar chart (with the total and the busiest hour), to plan the staff.
- **DSH-012** — card `arrivals`: a heatmap of the check-ins of the period by day of the week (Monday first) and hour of the day, by the clock of the property; a darker square is more arrivals.
- All three have a definition, the period they describe, the time of the data and a link to the source; they can be moved or hidden like the others and show in the television view in short form. The charts are drawn without a library and carry a text alternative.
- Tests: `DashboardAnalyticsCardsTest`; the existing dashboard tests were updated for the three new cards.

### Evidence (2026-10-03): the room board dimensions, outlet revenue and the alert centre (`DSH-002`, `DSH-004`, `DSH-020`)

- `TASK-DSH-002` is `REVIEW`. A room card on the board now shows, apart from each other: whether it is occupied (stay), the housekeeping state, the sellability (`sellable`, `out_of_order`, `out_of_service`; out of order wins when both blocks overlap, from the room blocks of Front Office in one query) and the service flags the guests have up (do not disturb, refused service, make-up room, privacy, from Housekeeping through the `RoomReadiness` contract in one query). Complimentary is not a room state; it is an attribute of the rate and the folio.
- `TASK-DSH-004` is `REVIEW`. Revenue per outlet now counts what the outlets sold for cash, card or QRIS as well as what was charged to a room: the settled POS bills (`fin_pos_sales`, those with no room part, so a room charge is never counted twice) are added to the folio postings by source, under the outlet the source belongs to or "other"; the comparisons with the previous day, the same weekday a week earlier and the same day a month earlier were already there.
- `TASK-DSH-020` is `REVIEW`. The alert centre lists, each with its count, a few examples and a link to where it is dealt with: oversold reservations, unsettled and overdue folios, payments of unknown status (`/fnb/pos`), stock below zero in a location (`/inventory/stock`), work orders past their due time (`/maintenance`) and offline entries waiting to be reconciled (`/sync/exceptions`), besides the alerts that were there (stale arrivals, due departures, serious complaints, overdue laundry, low stock, payables and receivables, recurring expenses).
- Evidence: `tests/Integration/Reporting/ReportingTest.php` (`test_offline_entries_waiting_and_pos_sales_paid_without_a_room_charge_reach_the_dashboard`), `tests/Integration/FrontOffice/GuestRequestTest.php` (`test_the_room_card_shows_what_the_guest_asked_for_and_why_a_room_cannot_be_sold`).

### Evidence (2026-10-03): the room board dimensions, outlet revenue and the alert centre (`DSH-002`, `DSH-004`, `DSH-020`)

- `TASK-DSH-002` is `REVIEW`. A room card on the board now shows, apart from each other: whether it is occupied (stay), the housekeeping state, the sellability (`sellable`, `out_of_order`, `out_of_service`; out of order wins when both blocks overlap, from the room blocks of Front Office in one query) and the service flags the guests have up (do not disturb, refused service, make-up room, privacy, from Housekeeping through the `RoomReadiness` contract in one query). Complimentary is not a room state; it is an attribute of the rate and the folio.
- `TASK-DSH-004` is `REVIEW`. Revenue per outlet now counts what the outlets sold for cash, card or QRIS as well as what was charged to a room: the settled POS bills (`fin_pos_sales`, those with no room part, so a room charge is never counted twice) are added to the folio postings by source, under the outlet the source belongs to or "other"; the comparisons with the previous day, the same weekday a week earlier and the same day a month earlier were already there.
- `TASK-DSH-020` is `REVIEW`. The alert centre lists, each with its count, a few examples and a link to where it is dealt with: oversold reservations, unsettled and overdue folios, payments of unknown status (`/fnb/pos`), stock below zero in a location (`/inventory/stock`), work orders past their due time (`/maintenance`) and offline entries waiting to be reconciled (`/sync/exceptions`), besides the alerts that were there.
- Evidence: `tests/Integration/Reporting/ReportingTest.php` (`test_offline_entries_waiting_and_pos_sales_paid_without_a_room_charge_reach_the_dashboard`), `tests/Integration/FrontOffice/GuestRequestTest.php` (`test_the_room_card_shows_what_the_guest_asked_for_and_why_a_room_cannot_be_sold`).

## Required engineering checks

- Identify aggregate owner and state transition before coding.
- Enforce property scope and server-side authorization.
- Define transaction/idempotency/concurrency behavior where mutation is critical.
- Emit audit evidence for sensitive/state-changing operations.
- Add happy, negative, conflict/retry, and permission tests as applicable.
- Update traceability/evidence before marking DONE.
