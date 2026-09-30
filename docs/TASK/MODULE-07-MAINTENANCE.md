# Maintenance — Task Contract

**Bounded context:** Maintenance
**Critical note:** Work order and asset lifecycle; OOO/OOS effect coordinated with Front Office.

## Candidate aggregates / read models

- `WorkOrder`
- `Asset`
- `PreventiveMaintenancePlan`

## Requirement backlog

| Task ID | FR | Priority | Requirement | Status |
| --- | --- | --- | --- | --- |
| TASK-MTC-001 | FR-MTC-001 | Wajib | Menerima laporan kerusakan dari seluruh department dan mengubahnya menjadi work order bernomor unik dengan lokasi, kategori, dan foto. | TODO |
| TASK-MTC-002 | FR-MTC-002 | Wajib | Menetapkan prioritas (mendesak, tinggi, normal, rendah) dan batas waktu penyelesaian sesuai kesepakatan tingkat layanan. | TODO |
| TASK-MTC-003 | FR-MTC-003 | Wajib | Menugaskan work order kepada teknisi tertentu dan menampilkannya pada ponsel teknisi. | TODO |
| TASK-MTC-004 | FR-MTC-004 | Wajib | Mengikuti status pekerjaan: berjalan, selesai, dan belum selesai beserta alasan bila tertunda (menunggu suku cadang, menunggu vendor, atau menunggu akses kamar). | TODO |
| TASK-MTC-005 | FR-MTC-005 | Wajib | Foto hasil pekerjaan wajib dilampirkan sebelum work order dapat ditandai selesai; sistem menolak penutupan tanpa foto. | TODO |
| TASK-MTC-006 | FR-MTC-006 | Wajib | Menetapkan kamar menjadi Out of Order atau Out of Service beserta perkiraan tanggal selesai; status ini langsung memblokir penjualan kamar di Front Office. | TODO |
| TASK-MTC-007 | FR-MTC-007 | Sebaiknya | Mengelola daftar aset properti (mesin, peralatan, kendaraan) beserta nomor aset, tanggal perolehan, garansi, dan riwayat perbaikan. | TODO |
| TASK-MTC-008 | FR-MTC-008 | Sebaiknya | Menyusun jadwal pemeliharaan pencegahan berkala per aset dan menghasilkan work order secara otomatis pada tanggalnya. | TODO |
| TASK-MTC-009 | FR-MTC-009 | Sebaiknya | Mengelola persediaan suku cadang dan mencatat pemakaiannya pada setiap work order. | TODO |
| TASK-MTC-010 | FR-MTC-010 | Wajib | Mengajukan permintaan pembelian alat dan suku cadang ke modul Purchasing, termasuk pekerjaan yang dikerjakan vendor luar. | TODO |
| TASK-MTC-011 | FR-MTC-011 | Wajib | Menampilkan SOP tugas harian, mingguan, dan bulanan teknik seperti pemeriksaan genset, pompa, dan pendingin ruangan. | TODO |
| TASK-MTC-012 | FR-MTC-012 | Wajib | Menerbitkan laporan: work order per status dan department pelapor, waktu penyelesaian rata-rata, kerusakan berulang per kamar, biaya perbaikan, dan hari kamar tidak dapat dijual. | TODO |
| TASK-MTC-013 | FR-MTC-013 | Wajib | Work order yang mendekati atau melewati SLA menghasilkan eskalasi ke supervisor/MOD sesuai matriks prioritas dan shift. | TODO |
| TASK-MTC-014 | FR-MTC-014 | Sebaiknya | Mencatat meter reading/usage counter untuk aset yang membutuhkan preventive maintenance berdasarkan jam operasi, kilometer, atau siklus selain kalender. | TODO |
| TASK-MTC-015 | FR-MTC-015 | Sebaiknya | Pekerjaan vendor eksternal memiliki quotation, approval, jadwal, biaya aktual, bukti pekerjaan, dan relasi ke aset/work order. | TODO |

## Required engineering checks

- Identify aggregate owner and state transition before coding.
- Enforce property scope and server-side authorization.
- Define transaction/idempotency/concurrency behavior where mutation is critical.
- Emit audit evidence for sensitive/state-changing operations.
- Add happy, negative, conflict/retry, and permission tests as applicable.
- Update traceability/evidence before marking DONE.
