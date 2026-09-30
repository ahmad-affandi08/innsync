# Kitchen — Task Contract

**Bounded context:** Kitchen
**Critical note:** KDS/recipe/production facts feed inventory; do not mutate inventory tables directly.

## Candidate aggregates / read models

- `KitchenTicket`
- `Recipe`
- `Production/WasteRecord`
- `MenuAvailability`

## Requirement backlog

| Task ID | FR | Priority | Requirement | Status |
| --- | --- | --- | --- | --- |
| TASK-KIT-001 | FR-KIT-001 | Wajib | Menampilkan tiket pesanan dari POS pada layar dapur secara berurutan beserta waktu tunggu dan penanda keterlambatan. | TODO |
| TASK-KIT-002 | FR-KIT-002 | Wajib | Mengubah status tiket menjadi diproses, siap, dan sudah diantar sehingga pelayan menerima pemberitahuan. | TODO |
| TASK-KIT-003 | FR-KIT-003 | Wajib | Mengelola resep dan komposisi bahan (bill of material) berversi untuk setiap menu, termasuk yield, waste standar, satuan, dan tanggal efektif, sebagai dasar biaya bahan dan harga pokok. | TODO |
| TASK-KIT-004 | FR-KIT-004 | Wajib | Mengurangi stok bahan secara otomatis berdasarkan versi resep yang berlaku setiap kali item menu diposting sebagai penjualan, tepat satu kali untuk setiap transaksi. | TODO |
| TASK-KIT-005 | FR-KIT-005 | Wajib | Menandai menu yang habis sehingga otomatis tidak dapat dipesan dari POS maupun menu QR tamu. | TODO |
| TASK-KIT-006 | FR-KIT-006 | Wajib | Mencatat pemakaian bahan, produksi persiapan, dan pembuangan bahan rusak (waste log) beserta alasan. | TODO |
| TASK-KIT-007 | FR-KIT-007 | Wajib | Melakukan stock opname bahan dapur dan gudang kering dengan pencatatan selisih dan nilai kerugian. | TODO |
| TASK-KIT-008 | FR-KIT-008 | Wajib | Menampilkan daftar periksa kebersihan, suhu penyimpanan, dan tugas harian, mingguan, serta bulanan dapur. | TODO |
| TASK-KIT-009 | FR-KIT-009 | Sebaiknya | Mencatat tanggal kedaluwarsa dan nomor batch bahan sensitif dengan peringatan mendekati kedaluwarsa. | TODO |
| TASK-KIT-010 | FR-KIT-010 | Wajib | Membuat laporan kerusakan peralatan yang diteruskan ke modul Maintenance. | TODO |
| TASK-KIT-011 | FR-KIT-011 | Wajib | Mengajukan permintaan pembelian bahan dan peralatan ke modul Purchasing. | TODO |
| TASK-KIT-012 | FR-KIT-012 | Sebaiknya | Menerbitkan laporan penjualan menu, rasio biaya bahan terhadap penjualan, dan analisis menu berdasarkan popularitas serta kontribusi margin. | TODO |
| TASK-KIT-013 | FR-KIT-013 | Wajib | Setiap perubahan resep menghasilkan versi baru bertanggal efektif; transaksi lama selalu mereferensikan versi resep yang berlaku saat transaksi diposting. | TODO |
| TASK-KIT-014 | FR-KIT-014 | Sebaiknya | Mendukung produksi/preparation batch (misalnya sauce, dough, stock) yang mengonsumsi bahan baku dan menghasilkan semi-finished goods beserta yield aktual. | TODO |
| TASK-KIT-015 | FR-KIT-015 | Wajib | KDS menyediakan indikator koneksi dan antrean; bila layar atau jaringan bermasalah, tiket tetap tersimpan dan dapat dialihkan ke printer/fallback queue tanpa kehilangan order. | TODO |

## Required engineering checks

- Identify aggregate owner and state transition before coding.
- Enforce property scope and server-side authorization.
- Define transaction/idempotency/concurrency behavior where mutation is critical.
- Emit audit evidence for sensitive/state-changing operations.
- Add happy, negative, conflict/retry, and permission tests as applicable.
- Update traceability/evidence before marking DONE.
