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
| TASK-HR-001 | FR-HR-001 | Wajib | Mengelola data induk karyawan: nomor induk, nama, department, jabatan, tanggal bergabung, jenis kontrak, masa berlaku kontrak, atasan langsung, dan status aktif. | REVIEW |
| TASK-HR-002 | FR-HR-002 | Wajib | Menyimpan berkas kepegawaian: kontrak kerja, identitas, sertifikat keahlian, dan hasil pemeriksaan wajib, beserta tanggal berlaku. | REVIEW |
| TASK-HR-003 | FR-HR-003 | Sebaiknya | Memberi peringatan otomatis menjelang berakhirnya kontrak, sertifikat, atau dokumen wajib lainnya. | REVIEW |
| TASK-HR-004 | FR-HR-004 | Sebaiknya | Menyediakan portal mandiri karyawan untuk melihat jadwal, sisa cuti, riwayat kehadiran, dan slip pendapatan. | TODO |
| TASK-HR-005 | FR-HR-005 | Wajib | Proses offboarding menonaktifkan akses, menutup assignment/shift mendatang, mencatat pengembalian aset, dan mempertahankan histori transaksi karyawan tanpa menghapus data historis. | REVIEW |
| TASK-HR-010 | FR-HR-010 | Wajib | Menyusun roster shift per department dengan pola shift yang dapat dikonfigurasi (pagi, siang, malam, split, libur) untuk periode mingguan dan bulanan. | REVIEW |
| TASK-HR-011 | FR-HR-011 | Sebaiknya | Sistem memperingatkan bila jumlah staf pada suatu shift berada di bawah kebutuhan minimum department. | REVIEW |
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

## Progress

### Slice 36 (2026-10-03): employee records, personnel papers, warnings and offboarding

- Status: `TASK-HR-001`, `-002` and `-003` are `REVIEW`; `TASK-HR-005` was `IN_PROGRESS` until slice 37 added the roster and the closing of upcoming shifts. ADR/BR: a person who leaves is never deleted; personnel papers are sensitive (a separate privilege, private files, every opening audited, nothing of their content in the audit trail); human resource and the identity module meet only through the shared contracts `StaffDirectory` and `StaffAccess`.
- Context: new bounded context `HumanResource` (`app/Modules/HumanResource`), migration 88 (`hr_employees`, `hr_documents`, `hr_offboard_items` append-only by trigger, `hr_settings`), `EmployeeStore` with `DatabaseEmployeeStore`, `EmployeeService`, `DocumentService`, `EmployeeController`, page `hr/pages/employees`, routes `/hr/...`. Privileges `hr.employee.view`, `hr.employee.manage` and `hr.document.manage`. In the shared kernel: `StaffDirectory::members` and the new contract `StaffAccess` (`DatabaseStaffAccess` in the identity module).
- **Employees (`HR-001`).** A manager writes a record: number `EMP-000001`, name, department, position, the day joined (at most 90 days ahead), kind of contract (permanent, fixed term, probation, daily, intern) with its end date (required unless permanent), the direct supervisor (an employee still working here; nobody is under themselves, directly or through others), the account they sign in with (one account for one person, someone who works in the property), phone and e-mail. Changes carry the version of the screen and are audited without the contact details.
- **Papers (`HR-002`).** Contract, identity, certificate, medical check or other, each with a title, the day issued and the day it holds until, and its file (PDF, JPG or PNG up to 5 MB, kept privately as sensitive). A newer paper may replace one of the same person; the older stays, marked replaced. Only the privilege for papers sees, adds or opens them, and each adding and each opening is audited with the kind and the employee's number.
- **Warnings (`HR-003`).** For people still working: a contract ending or ended, a paper holding until a date that is near or past, and a paper every employee must have that is missing (baseline: contract and identity; the owner chooses). They are warned of from 30 days before (baseline, 1 to 365, saved with the version of the setting) and listed, the most urgent first, for managers and the papers privilege. There is no push or e-mail yet.
- **Offboarding (`HR-005`).** A manager records why the person leaves (resigned, terminated, contract ended, retired, other; a reason is required for terminated and other), the day (not after today, not before they joined) and what they had to hand back, each marked handed back or not. Someone with people under them must name who supervises these from now on. The person's roles in the property end, so they can no longer sign in to it (their account, and everything that names them, stays); their papers start the retention period of `hr_personnel_document` counted from the day they left; the record, the papers and the hand-back list can no longer be changed (the hand-back list also not by the database).
- Not yet: taking someone back, a hand-back list that is filled in later, a self-service portal (`HR-004`), the dashboard card of warnings, an e-mail of the warnings, ending the person's open work (work orders, tasks) given to them.
- Evidence: `tests/Feature/HumanResource/EmployeeHttpTest.php` (numbering, validation of every field, supervisor loops, one account for one person, versions, who may see and write; papers kept privately, replaced and never deleted, openings audited, who may; warnings for ending and ended contracts and papers, missing papers, the warning period and its version; offboarding with supervisor hand-over, the hand-back list, access ended, retention started, the record closed, and the database refusing changes). Seen in the browser: an employee written, a paper added, and the person offboarded.

### Slice 37 (2026-10-03): shifts, the roster and the staffing needs

- Status: `TASK-HR-010`, `-011` and (with the closing of upcoming shifts) `-005` are `REVIEW`. ADR/BR: each planned day keeps the times of the shift as it was planned; a day already past is not planned again here; the roster plans only people still working and within their contract.
- Context: migration 89 (`hr_shift_patterns`, `hr_roster_entries`, `hr_staffing_minimums`), `RosterStore` with `DatabaseRosterStore`, `RosterService`, `RosterController`, page `hr/pages/roster`, privilege `hr.roster.manage` (people with the employee privileges only see the roster). Offboarding calls `RosterService::closeFor`.
- **Shifts (`HR-010`).** The owner configures them: a code (letters and digits), a name, the times it starts and ends (an end before the start means the shift ends the next morning, as a night shift does), an optional second part for a split shift (both parts within one day, one after the other), or a day off with no times. The usual ones for an Indonesian hotel can be written in one step: morning 07:00 to 15:00, afternoon 15:00 to 23:00, night 23:00 to 07:00, split 07:00 to 11:00 and 17:00 to 21:00, and day off. A shift is changed (with the version of the screen) or retired and brought back; its code and whether it is a day off do not change; it is never deleted.
- **Roster (`HR-010`).** A manager plans a person for a shift on a day, or clears the day, for a week or a month (up to 62 days), by department or all; one change may cover several people and days (at most 800 in all). The days copied from the week before fill only empty days, and skip people who have left, days that are past and days outside a contract. A planned day keeps the code, the department, the times and the hours (a split or night shift counted right) it was planned with, so changing a shift later changes none of them.
- **Needs (`HR-011`).** For each department and each worked shift the owner says the fewest people it needs (0 to 200; 0 takes the need away). The roster lists every coming day, from the business date on, that has fewer people planned than that, with how many it has and needs; days off do not count as people on the shift, and people who have left do not count.
- **Offboarding (`HR-005`).** When a person leaves, the days planned for them after the day they left are taken out of the roster; the day count is kept in the audit entry of the offboarding.
- Not yet: the staff on duty sent live to the dashboard (`HR-014`), leave that changes the roster (`HR-015`), swapping shifts (`HR-017`), an assignment of several shifts in one day, a minimum that depends on the day of the week or the occupancy, a published or locked roster.
- Evidence: `tests/Feature/HumanResource/RosterHttpTest.php` (the usual shifts, hours of a night and a split shift, every refused input, retiring; planning days, clearing, the times kept when a shift is changed, past days, days before joining and after a contract, retired shifts, the size limits, who may; copying a week into empty days only; the needs and the warnings with their edge cases; offboarding clearing the upcoming days). Seen in the browser: the usual shifts written, and a person planned on a day from the roster.

## Required engineering checks

- Identify aggregate owner and state transition before coding.
- Enforce property scope and server-side authorization.
- Define transaction/idempotency/concurrency behavior where mutation is critical.
- Emit audit evidence for sensitive/state-changing operations.
- Add happy, negative, conflict/retry, and permission tests as applicable.
- Update traceability/evidence before marking DONE.
