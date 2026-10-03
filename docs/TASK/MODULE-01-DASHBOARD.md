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
| TASK-DSH-002 | FR-DSH-002 | Wajib | Menampilkan room board dengan dimensi status yang terpisah: occupancy (vacant/occupied), housekeeping (dirty/clean/inspected), sellability (sellable/OOO/OOS), serta service flag seperti DND/Double Lock. Complimentary ditampilkan sebagai atribut tarif/folio, bukan status kebersihan kamar. | IN_PROGRESS |
| TASK-DSH-003 | FR-DSH-003 | Wajib | Papan kamar bersifat template: administrator dapat menambah, mengubah, menonaktifkan kamar, menetapkan tipe, lantai, gedung, dan kapasitas tanpa bantuan pengembang. | REVIEW |
| TASK-DSH-004 | FR-DSH-004 | Wajib | Menampilkan pendapatan hari berjalan per outlet (Kamar, Restoran, Bar, Spa, Gift Shop, dan outlet tambahan yang dibuat pengguna) beserta total dan perbandingan terhadap hari, minggu, serta bulan sebelumnya. | IN_PROGRESS |
| TASK-DSH-005 | FR-DSH-005 | Wajib | Daftar outlet bersifat dapat diperluas; penambahan outlet baru otomatis muncul sebagai kolom pendapatan dan kategori pada laporan. | REVIEW |
| TASK-DSH-006 | FR-DSH-006 | Wajib | Menampilkan ringkasan pengeluaran: pembayaran kepada pemasok dan vendor yang telah dibayar, hutang berjalan, serta daftar jatuh tempo dalam 7 dan 30 hari ke depan. | REVIEW |
| TASK-DSH-007 | FR-DSH-007 | Wajib | Menampilkan peringatan stok minimum per department (Bar, Kitchen, Housekeeping, Maintenance, Galley, Reception) berdasarkan kartu stok dan hasil stock opname. | REVIEW |
| TASK-DSH-008 | FR-DSH-008 | Wajib | Menampilkan ringkasan kepegawaian hari berjalan: jumlah staf bertugas per shift per department, staf libur, staf ijin dengan keterangan, dan staf tanpa keterangan (alpha). | REVIEW |
| TASK-DSH-009 | FR-DSH-009 | Wajib | Menampilkan ringkasan pekerjaan pemeliharaan: work order berjalan, selesai hari ini, melewati batas waktu, dan kamar berstatus Out of Order. | REVIEW |
| TASK-DSH-010 | FR-DSH-010 | Sebaiknya | Menampilkan performa produk: sepuluh menu terlaris dan paling tidak laku, serta performa tipe kamar berdasarkan okupansi dan ADR pada periode terpilih. | TODO |
| TASK-DSH-011 | FR-DSH-011 | Sebaiknya | Menampilkan distribusi jam transaksi per outlet dalam bentuk grafik batang per jam untuk membantu penjadwalan staf. | TODO |
| TASK-DSH-012 | FR-DSH-012 | Sebaiknya | Menampilkan heatmap kedatangan tamu (check-in) berdasarkan jam dan hari dalam seminggu. | TODO |
| TASK-DSH-013 | FR-DSH-013 | Wajib | Menampilkan lini masa kewajiban pajak: pajak kamar, pajak restoran dan outlet lain, nilai terkumpul berjalan, tanggal jatuh tempo pelaporan, dan status pelaporan. | REVIEW |
| TASK-DSH-014 | FR-DSH-014 | Wajib | Menampilkan akumulasi service charge yang terkumpul dari kamar dan outlet beserta estimasi porsi yang akan didistribusikan kepada karyawan. | REVIEW |
| TASK-DSH-015 | FR-DSH-015 | Wajib | Menyediakan penyaring periode (hari ini, kemarin, 7 hari, bulan berjalan, rentang khusus) yang berlaku serentak pada seluruh kartu. | REVIEW |
| TASK-DSH-016 | FR-DSH-016 | Wajib | Setiap kartu dapat diklik untuk menelusuri hingga daftar transaksi atau dokumen sumbernya. | IN_PROGRESS |
| TASK-DSH-017 | FR-DSH-017 | Bisa | Susunan kartu dapat diatur per pengguna (urutan dan tampil/sembunyi) dan tersimpan pada profil pengguna. | REVIEW |
| TASK-DSH-018 | FR-DSH-018 | Sebaiknya | Data diperbarui otomatis paling lambat setiap 60 detik tanpa memuat ulang halaman, dengan penanda waktu pembaruan terakhir. | REVIEW |
| TASK-DSH-019 | FR-DSH-019 | Bisa | Tersedia mode layar televisi (tampilan besar tanpa navigasi) untuk dipasang di ruang manajemen. | REVIEW |
| TASK-DSH-020 | FR-DSH-020 | Wajib | Menyediakan pusat exception/alert untuk kondisi yang membutuhkan tindakan: reservasi berpotensi oversold, folio belum settle, pembayaran berstatus unknown, stok negatif atau kritis, work order lewat SLA, dan kegagalan sinkronisasi. | IN_PROGRESS |
| TASK-DSH-021 | FR-DSH-021 | Wajib | Setiap KPI menampilkan definisi, business date/periode, waktu data terakhir diperbarui, serta drill-down ke data sumber agar tidak terjadi perbedaan interpretasi antar department. | REVIEW |
| TASK-DSH-022 | FR-DSH-022 | Wajib | Dashboard menerapkan cakupan data berdasarkan property, outlet, department, dan role; pengguna hanya melihat angka yang diizinkan tanpa mengubah sumber data. | IN_PROGRESS |

### Slice 41 (2026-10-03): dashboard cards for spending, low stock by department, today's staffing and maintenance

- Status: `TASK-DSH-006`, `-007`, `-008` and `-009` are `REVIEW`. Each card is read-only, states its period or business date and links to where its numbers come from, and is shown only to people who hold a permission behind it. The new keys `spend`, `stock` and `maintenance` join the order and hidden-card preferences.
- **Spending (`DSH-006`).** Payments to suppliers and vendors made on the days of the chosen period (reversals taken off), what is still owed on supplier invoices, what is past due, what falls due in 7 and in 30 days, and the next due invoices. Seen by people with `finance.payable.view`, `.manage` or `finance.payment.record`.
- **Low stock (`DSH-007`).** Items below the minimum of a location, counted and listed by the department of the item, from the stock card (which counts correct). Seen with `inventory.stock.view` or `inventory.catalog.view`.
- **Staffing today (`DSH-008`).** The existing staff card now also says how many have the day off, who is on leave or a permit with its kind, and who was planned and did not come (absent). Seen by people with an HR privilege.
- **Maintenance (`DSH-009`).** Work orders still open, done on the business date, open ones past their due time, and the rooms out of order today. Seen with a maintenance work privilege.
- Evidence: `tests/Integration/Reporting/DashboardOperationsCardsTest.php` (who sees which card; the spending sums with an overdue, a near and a far invoice and a payment; low stock by department; maintenance counts and the room out of order), `LeaveHttpTest` (leave on the staff card), `DashboardPreferenceTest` (the card keys).

## Required engineering checks

- Identify aggregate owner and state transition before coding.
- Enforce property scope and server-side authorization.
- Define transaction/idempotency/concurrency behavior where mutation is critical.
- Emit audit evidence for sensitive/state-changing operations.
- Add happy, negative, conflict/retry, and permission tests as applicable.
- Update traceability/evidence before marking DONE.
