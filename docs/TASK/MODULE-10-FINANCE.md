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
| TASK-FIN-001 | FR-FIN-001 | Wajib | Menerima pembukuan pendapatan otomatis dari Front Office dan seluruh POS outlet, terpisah antara nilai dasar, pajak, dan service charge. | TODO |
| TASK-FIN-002 | FR-FIN-002 | Wajib | Menerbitkan laporan pendapatan harian per outlet dan per metode pembayaran, serta rekapitulasi bulanan. | TODO |
| TASK-FIN-003 | FR-FIN-003 | Wajib | Melakukan rekonsiliasi setoran kasir: kas fisik yang disetor dibandingkan dengan kas sistem per shift dan per kasir, dengan pencatatan selisih. | TODO |
| TASK-FIN-004 | FR-FIN-004 | Sebaiknya | Merekonsiliasi penerimaan QRIS dan kartu terhadap mutasi rekening bank, termasuk pemotongan biaya transaksi. | TODO |
| TASK-FIN-005 | FR-FIN-005 | Wajib | Memverifikasi dan mengunci transaksi hari sebelumnya setelah night audit sehingga tidak dapat diubah tanpa jurnal koreksi. | TODO |
| TASK-FIN-006 | FR-FIN-006 | Wajib | Setiap posting keuangan menyimpan property, business date, event time, source document, actor, dan correlation ID agar rekonsiliasi lintas modul dapat dilakukan tanpa ambigu. | TODO |
| TASK-FIN-010 | FR-FIN-010 | Wajib | Mengelola daftar akun biaya sederhana yang dikelompokkan per department dan per kategori. | TODO |
| TASK-FIN-011 | FR-FIN-011 | Wajib | Mencatat hutang kepada pemasok dan vendor secara otomatis dari penerimaan barang dan faktur, lengkap dengan syarat pembayaran dan tanggal jatuh tempo. | TODO |
| TASK-FIN-012 | FR-FIN-012 | Wajib | Menampilkan jadwal jatuh tempo pembayaran dan laporan umur hutang, serta mengirimkannya sebagai peringatan ke dashboard. | TODO |
| TASK-FIN-013 | FR-FIN-013 | Wajib | Mencatat pembayaran kepada pemasok dan vendor, baik penuh maupun sebagian, beserta bukti pembayaran. | TODO |
| TASK-FIN-014 | FR-FIN-014 | Wajib | Mengelola piutang dari perusahaan, agen perjalanan, dan kanal pemesanan daring beserta umur piutang dan penagihan. | TODO |
| TASK-FIN-015 | FR-FIN-015 | Wajib | Mengelola kas kecil (petty cash): pengisian, pengeluaran dengan bukti, dan pertanggungjawaban. | TODO |
| TASK-FIN-016 | FR-FIN-016 | Sebaiknya | Mencatat biaya tetap berulang seperti sewa, listrik, air, dan langganan, dengan pengingat jatuh tempo. | TODO |
| TASK-FIN-017 | FR-FIN-017 | Sebaiknya | Menyusun anggaran per department dan menampilkan perbandingan anggaran terhadap realisasi. | TODO |
| TASK-FIN-018 | FR-FIN-018 | Wajib | Pembayaran vendor/pengeluaran di atas threshold menggunakan maker-checker; pembuat transaksi tidak boleh menjadi satu-satunya penyetuju. | TODO |
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
| TASK-FIN-037 | FR-FIN-037 | Wajib | Rekonsiliasi harian menghasilkan daftar exception antara POS/folio, payment provider/EDC, kas fisik, dan bank; hari dianggap clean hanya bila exception telah diselesaikan atau di-waive. | TODO |

## Required engineering checks

- Identify aggregate owner and state transition before coding.
- Enforce property scope and server-side authorization.
- Define transaction/idempotency/concurrency behavior where mutation is critical.
- Emit audit evidence for sensitive/state-changing operations.
- Add happy, negative, conflict/retry, and permission tests as applicable.
- Update traceability/evidence before marking DONE.
