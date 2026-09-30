# Human Resource — Task Contract

**Bounded context:** Human Resource
**Critical note:** Roster/attendance/performance/payroll basis/service charge; sensitive employee data.

## Candidate aggregates / read models

- `Employee`
- `Roster`
- `AttendanceRecord`
- `LeaveRequest`
- `PerformanceReview`
- `PayrollBasis`
- `ServiceChargeRun`

## Requirement backlog

| Task ID | FR | Priority | Requirement | Status |
| --- | --- | --- | --- | --- |
| TASK-HR-001 | FR-HR-001 | Wajib | Mengelola data induk karyawan: nomor induk, nama, department, jabatan, tanggal bergabung, jenis kontrak, masa berlaku kontrak, atasan langsung, dan status aktif. | TODO |
| TASK-HR-002 | FR-HR-002 | Wajib | Menyimpan berkas kepegawaian: kontrak kerja, identitas, sertifikat keahlian, dan hasil pemeriksaan wajib, beserta tanggal berlaku. | TODO |
| TASK-HR-003 | FR-HR-003 | Sebaiknya | Memberi peringatan otomatis menjelang berakhirnya kontrak, sertifikat, atau dokumen wajib lainnya. | TODO |
| TASK-HR-004 | FR-HR-004 | Sebaiknya | Menyediakan portal mandiri karyawan untuk melihat jadwal, sisa cuti, riwayat kehadiran, dan slip pendapatan. | TODO |
| TASK-HR-005 | FR-HR-005 | Wajib | Proses offboarding menonaktifkan akses, menutup assignment/shift mendatang, mencatat pengembalian aset, dan mempertahankan histori transaksi karyawan tanpa menghapus data historis. | TODO |
| TASK-HR-010 | FR-HR-010 | Wajib | Menyusun roster shift per department dengan pola shift yang dapat dikonfigurasi (pagi, siang, malam, split, libur) untuk periode mingguan dan bulanan. | TODO |
| TASK-HR-011 | FR-HR-011 | Sebaiknya | Sistem memperingatkan bila jumlah staf pada suatu shift berada di bawah kebutuhan minimum department. | TODO |
| TASK-HR-012 | FR-HR-012 | Wajib | Karyawan melakukan presensi masuk dan pulang melalui ponsel dengan verifikasi lokasi (geofence) dan swafoto, atau melalui perangkat presensi di properti. | TODO |
| TASK-HR-013 | FR-HR-013 | Wajib | Sistem menghitung keterlambatan, pulang lebih awal, jam lembur, dan ketidakhadiran tanpa keterangan secara otomatis terhadap jadwal. | TODO |
| TASK-HR-014 | FR-HR-014 | Wajib | Jumlah staf bertugas per shift per department dikirim ke dashboard secara langsung. | TODO |
| TASK-HR-015 | FR-HR-015 | Wajib | Mengelola pengajuan cuti, ijin, dan sakit dengan alur persetujuan berjenjang serta lampiran bukti; hasilnya otomatis mengubah roster. | TODO |
| TASK-HR-016 | FR-HR-016 | Wajib | Mengelola saldo cuti tahunan, cuti yang sudah diambil, dan sisa cuti per karyawan. | TODO |
| TASK-HR-017 | FR-HR-017 | Bisa | Mendukung pertukaran shift antar karyawan dengan persetujuan penyelia. | TODO |
| TASK-HR-018 | FR-HR-018 | Wajib | Mencatat lembur yang telah disetujui sebelumnya dan membedakannya dari kelebihan jam kerja yang tidak disetujui. | TODO |
| TASK-HR-019 | FR-HR-019 | Wajib | Koreksi presensi setelah periode berjalan memerlukan alasan dan approval; nilai sebelum/sesudah disimpan dan perubahan otomatis memicu hitung ulang komponen terkait. | TODO |
| TASK-HR-020 | FR-HR-020 | Wajib | Menerima persentase penyelesaian SOP tugas harian, mingguan, dan bulanan dari seluruh modul operasional sebagai komponen penilaian kinerja objektif. | TODO |
| TASK-HR-021 | FR-HR-021 | Sebaiknya | Menampilkan papan kinerja per karyawan: kehadiran, ketepatan waktu, penyelesaian tugas, jumlah komplain tamu terkait, dan produktivitas department. | TODO |
| TASK-HR-022 | FR-HR-022 | Sebaiknya | Melakukan penilaian kinerja berkala dengan formulir yang dapat dikonfigurasi dan tanda tangan digital atasan serta karyawan. | TODO |
| TASK-HR-023 | FR-HR-023 | Sebaiknya | Mencatat teguran, surat peringatan, dan penghargaan karyawan beserta lampiran dan masa berlaku. | TODO |
| TASK-HR-024 | FR-HR-024 | Bisa | Menyediakan papan pengumuman internal dan distribusi kebijakan yang wajib dibaca dengan pencatatan konfirmasi. | TODO |
| TASK-HR-030 | FR-HR-030 | Wajib | Mengelola komponen pendapatan karyawan: gaji pokok, tunjangan tetap, tunjangan tidak tetap, uang makan, dan uang transport. | TODO |
| TASK-HR-031 | FR-HR-031 | Wajib | Menghitung usulan penggajian periodik berdasarkan kehadiran, lembur, potongan keterlambatan, dan ketidakhadiran, lalu meneruskannya ke modul Finance untuk verifikasi dan pembayaran. | TODO |
| TASK-HR-032 | FR-HR-032 | Wajib | Menghitung distribusi service charge yang terkumpul dari kamar dan outlet berdasarkan sistem poin per jabatan dan proporsi kehadiran, dengan penyisihan untuk kerusakan atau kehilangan sesuai kebijakan properti. | TODO |
| TASK-HR-033 | FR-HR-033 | Wajib | Menampilkan simulasi distribusi service charge sebelum disahkan, dan mengunci nilainya setelah disetujui oleh General Manager. | TODO |
| TASK-HR-034 | FR-HR-034 | Wajib | Menerbitkan slip pendapatan elektronik per karyawan yang memuat rincian gaji, lembur, potongan, dan bagian service charge. | TODO |
| TASK-HR-035 | FR-HR-035 | Sebaiknya | Mengekspor data penggajian ke berkas lembar kerja atau format yang dapat diterima sistem penggajian pihak ketiga. | TODO |
| TASK-HR-036 | FR-HR-036 | Sebaiknya | Menyimpan dasar perhitungan pajak penghasilan karyawan dan iuran jaminan sosial sebagai parameter yang dapat dikonfigurasi. | TODO |
| TASK-HR-037 | FR-HR-037 | Wajib | Payroll run memiliki lifecycle draft, calculated, reviewed, approved, paid, dan locked; hanya periode approved yang boleh diteruskan untuk pembayaran. | TODO |
| TASK-HR-038 | FR-HR-038 | Wajib | Perubahan setelah payroll/service-charge dikunci dilakukan melalui adjustment pada periode berikutnya atau reopening berizin tinggi; transaksi lama tidak ditimpa. | TODO |

## Required engineering checks

- Identify aggregate owner and state transition before coding.
- Enforce property scope and server-side authorization.
- Define transaction/idempotency/concurrency behavior where mutation is critical.
- Emit audit evidence for sensitive/state-changing operations.
- Add happy, negative, conflict/retry, and permission tests as applicable.
- Update traceability/evidence before marking DONE.
