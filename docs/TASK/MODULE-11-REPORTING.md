# Reporting & Analytics — Task Contract

**Bounded context:** Reporting & Dashboard
**Critical note:** Read-only projections and server-side exports; no source-of-truth mutation.

## Candidate aggregates / read models

- `ReportDefinition`
- `ReportRun`
- `ScheduledReport`

## Requirement backlog

| Task ID | FR | Priority | Requirement | Status |
| --- | --- | --- | --- | --- |
| TASK-RPT-001 | FR-RPT-001 | Wajib | Menyediakan pusat laporan yang mengelompokkan seluruh laporan berdasarkan department dan tema. | TODO |
| TASK-RPT-002 | FR-RPT-002 | Wajib | Seluruh laporan mendukung penyaring rentang tanggal, outlet, department, dan pengguna. | TODO |
| TASK-RPT-003 | FR-RPT-003 | Wajib | Seluruh laporan dapat diekspor ke PDF dan lembar kerja, serta dicetak. | TODO |
| TASK-RPT-004 | FR-RPT-004 | Sebaiknya | Laporan dapat dijadwalkan untuk dikirim otomatis melalui surel atau pesan instan pada waktu tertentu kepada penerima tertentu. | TODO |
| TASK-RPT-005 | FR-RPT-005 | Wajib | Menyediakan laporan ringkas harian untuk manajemen (flash report) yang memuat okupansi, pendapatan, biaya utama, dan kejadian penting. | TODO |
| TASK-RPT-006 | FR-RPT-006 | Sebaiknya | Menyediakan pembanding antar periode: hari ini dibanding kemarin, bulan ini dibanding bulan lalu, dan tahun berjalan dibanding tahun sebelumnya. | TODO |
| TASK-RPT-007 | FR-RPT-007 | Wajib | Menyediakan jejak audit yang dapat dicari berdasarkan pengguna, modul, dan rentang waktu. | TODO |
| TASK-RPT-008 | FR-RPT-008 | Bisa | Menyediakan pembuat laporan sederhana bagi pengguna mahir untuk memilih kolom dan penyaring sendiri. | TODO |
| TASK-RPT-009 | FR-RPT-009 | Wajib | Setiap laporan menampilkan generated-at time, business date/periode, filter yang digunakan, dan sumber data utama sehingga hasil dapat direproduksi. | TODO |
| TASK-RPT-010 | FR-RPT-010 | Wajib | Kolom PII pada laporan mengikuti hak akses dan dapat dimasking; ekspor data sensitif dicatat pada audit log dengan pengguna, waktu, filter, dan tujuan. | TODO |
| TASK-RPT-011 | FR-RPT-011 | Sebaiknya | Ekspor besar diproses asynchronous dengan status pekerjaan dan notifikasi selesai agar tidak membebani transaksi operasional. | TODO |

## Required engineering checks

- Identify aggregate owner and state transition before coding.
- Enforce property scope and server-side authorization.
- Define transaction/idempotency/concurrency behavior where mutation is critical.
- Emit audit evidence for sensitive/state-changing operations.
- Add happy, negative, conflict/retry, and permission tests as applicable.
- Update traceability/evidence before marking DONE.
