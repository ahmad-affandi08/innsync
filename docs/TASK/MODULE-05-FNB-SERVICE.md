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
| TASK-FBS-001 | FR-FBS-001 | Wajib | Menampilkan denah meja per outlet dengan status kosong, terisi, dan sudah memesan; kasir dapat membuka bill dari meja atau dari nomor kamar. | TODO |
| TASK-FBS-002 | FR-FBS-002 | Wajib | Mengambil pesanan dengan katalog menu bergambar, kategori, varian, catatan khusus, dan jumlah porsi. | TODO |
| TASK-FBS-003 | FR-FBS-003 | Wajib | Mengirim pesanan ke layar dapur dan bar sesuai kategori item, serta mencetak tiket pada printer masing-masing bila diperlukan. | TODO |
| TASK-FBS-004 | FR-FBS-004 | Sebaiknya | Mendukung pemisahan bill, penggabungan bill, dan pemindahan pesanan antar meja. | TODO |
| TASK-FBS-005 | FR-FBS-005 | Wajib | Void item dan pembatalan bill hanya dapat dilakukan dengan alasan dan persetujuan penyelia; seluruh tindakan tercatat pada jejak audit. | TODO |
| TASK-FBS-006 | FR-FBS-006 | Wajib | Diskon dan pemberian gratis (complimentary) memerlukan alasan dan persetujuan sesuai ambang yang dikonfigurasi. | TODO |
| TASK-FBS-007 | FR-FBS-007 | Wajib | Menerima pembayaran tunai, QRIS, kartu melalui EDC, dan pembebanan ke kamar. Pembebanan ke kamar wajib memvalidasi bahwa kamar berstatus terisi dan mencocokkan nama tamu. | TODO |
| TASK-FBS-008 | FR-FBS-008 | Wajib | Menghitung pajak dan service charge secara otomatis sesuai konfigurasi per outlet dan menampilkannya terpisah pada struk. | TODO |
| TASK-FBS-009 | FR-FBS-009 | Wajib | Membuka dan menutup shift kasir dengan penghitungan kas fisik, kas sistem, serta pencatatan selisih beserta alasan. | TODO |
| TASK-FBS-010 | FR-FBS-010 | Wajib | POS tetap dapat mencatat transaksi saat jaringan terputus melalui antrean lokal terenkripsi. Setiap transaksi memiliki idempotency key dan status sinkronisasi sehingga pemulihan jaringan tidak menghasilkan bill, pembayaran, atau pengurangan stok ganda. | TODO |
| TASK-FBS-011 | FR-FBS-011 | Wajib | Mendukung modifier/add-on, tingkat kematangan, pilihan varian, dan catatan khusus yang dapat memengaruhi harga dan resep tanpa membuat item menu duplikat. | TODO |
| TASK-FBS-012 | FR-FBS-012 | Wajib | Perubahan bill oleh beberapa perangkat menggunakan kontrol konkurensi; sistem mencegah lost update dan menampilkan konflik bila bill telah berubah di perangkat lain. | TODO |
| TASK-FBS-013 | FR-FBS-013 | Wajib | Pembayaran QRIS/daring memiliki state initiated, pending, paid, failed, expired, unknown, dan refunded. Status unknown tidak boleh dianggap lunas sebelum rekonsiliasi atau callback valid diterima. | TODO |
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

## Required engineering checks

- Identify aggregate owner and state transition before coding.
- Enforce property scope and server-side authorization.
- Define transaction/idempotency/concurrency behavior where mutation is critical.
- Emit audit evidence for sensitive/state-changing operations.
- Add happy, negative, conflict/retry, and permission tests as applicable.
- Update traceability/evidence before marking DONE.
