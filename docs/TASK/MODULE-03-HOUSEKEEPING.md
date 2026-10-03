# Housekeeping — Task Contract

**Bounded context:** Housekeeping
**Critical note:** Room housekeeping state is distinct from occupancy/sellability; offline sync applies.

## Candidate aggregates / read models

- `RoomServiceTask`
- `RoomInspection`
- `HousekeepingChecklist`
- `LinenMovement`
- `LostFoundItem`

## Requirement backlog

| Task ID | FR | Priority | Requirement | Status |
| --- | --- | --- | --- | --- |
| TASK-HK-001 | FR-HK-001 | Wajib | Menampilkan papan status kamar yang sama dengan dashboard dan Front Office; setiap perubahan berlaku serentak untuk seluruh modul. | REVIEW |
| TASK-HK-002 | FR-HK-002 | Wajib | Supervisor membagi kamar kepada room attendant; setiap staf menerima tautan pribadi di ponsel berisi daftar kamar dan tugasnya. | REVIEW |
| TASK-HK-003 | FR-HK-003 | Sebaiknya | Sistem menyusun urutan prioritas pembersihan secara otomatis: kamar keberangkatan, kamar kotor kosong, permintaan tamu, lalu kamar menginap. | REVIEW |
| TASK-HK-004 | FR-HK-004 | Wajib | Room attendant mengubah status kamar langsung dari ponsel dengan maksimal tiga ketukan, termasuk penanda mulai dan selesai membersihkan untuk mengukur durasi. | REVIEW |
| TASK-HK-005 | FR-HK-005 | Wajib | Menyediakan daftar periksa SOP tugas harian, mingguan, dan bulanan per kamar dan per area umum, disusun oleh manajemen sebagai template. | REVIEW |
| TASK-HK-006 | FR-HK-006 | Sebaiknya | Daftar periksa dapat mewajibkan lampiran foto pada butir tertentu sebagai bukti pengerjaan. | REVIEW |
| TASK-HK-007 | FR-HK-007 | Wajib | Supervisor melakukan inspeksi kamar dan menyetujui perubahan status menjadi siap dijual; kamar tanpa inspeksi dapat dikonfigurasi tetap masuk status bersih namun belum siap. | REVIEW |
| TASK-HK-008 | FR-HK-008 | Wajib | Room attendant membuat laporan kerusakan dengan cara memilih kamar atau lokasi, menulis keterangan, dan melampirkan foto; laporan langsung menjadi work order pada modul Maintenance. | REVIEW |
| TASK-HK-009 | FR-HK-009 | Wajib | Mencatat pemakaian linen dan perlengkapan: sprei, handuk, sarung bantal, sabun, dan amenitas lain, per kamar dan per hari. | REVIEW |
| TASK-HK-010 | FR-HK-010 | Wajib | Mencatat sirkulasi linen mengikuti alur gudang ke luar gudang, ke laundry, dan kembali ke gudang; setiap perpindahan wajib diinput saat pengambilan maupun penyimpanan. | REVIEW |
| TASK-HK-011 | FR-HK-011 | Sebaiknya | Sistem menghitung selisih linen yang tidak kembali dan menandainya sebagai kehilangan atau kerusakan untuk ditindaklanjuti. | REVIEW |
| TASK-HK-012 | FR-HK-012 | Sebaiknya | Mencatat temuan barang tertinggal (lost and found) dengan foto, lokasi, tanggal, penemu, dan status pengembalian. | REVIEW |
| TASK-HK-013 | FR-HK-013 | Wajib | Menerima permintaan tamu dari Front Office beserta batas waktu penyelesaian dan menandai status penyelesaiannya. | REVIEW |
| TASK-HK-014 | FR-HK-014 | Wajib | Mengajukan permintaan pembelian alat dan bahan ke modul Purchasing langsung dari modul Housekeeping. | TODO |
| TASK-HK-015 | FR-HK-015 | Sebaiknya | Menerbitkan laporan produktivitas: jumlah kamar dibersihkan per staf, rata-rata durasi per kamar, dan persentase penyelesaian SOP. | REVIEW |
| TASK-HK-016 | FR-HK-016 | Wajib | Mendeteksi room status discrepancy antara Front Office dan Housekeeping (misalnya kamar menurut FO vacant tetapi menurut HK occupied/berisi barang) dan mewajibkan resolusi supervisor sebelum kamar dijual. | REVIEW |
| TASK-HK-017 | FR-HK-017 | Wajib | Mencatat service flag DND, refused service, make-up-room, dan privacy request dengan waktu mulai/selesai tanpa mengubah occupancy status kamar. | REVIEW |
| TASK-HK-018 | FR-HK-018 | Wajib | Inspeksi supervisor dapat menghasilkan status rework dengan daftar temuan; kamar hanya menjadi ready setelah seluruh temuan wajib diselesaikan atau di-waive oleh peran berwenang. | REVIEW |
| TASK-HK-019 | FR-HK-019 | Sebaiknya | Mengelola par level linen dan amenitas per tipe kamar/area sehingga kebutuhan replenishment dan selisih konsumsi dapat dihitung per shift. | REVIEW |
| TASK-HK-020 | FR-HK-020 | Wajib | Staf Housekeeping memindai barcode kantong laundry lalu memilih kamar untuk membuka order guest laundry. | REVIEW |
| TASK-HK-021 | FR-HK-021 | Wajib | Staf mencatat rincian per item sebelum dikirim ke laundry: jenis pakaian (baju, celana, dan seterusnya), merek atau tanpa merek, jumlah, catatan kondisi, tanggal pengambilan, dan tanggal janji kembali kepada tamu. | REVIEW |
| TASK-HK-022 | FR-HK-022 | Wajib | Sistem mengirim order tersebut ke modul Laundry lengkap dengan nomor kamar dan jumlah item, dalam bentuk daftar per item sehingga petugas laundry cukup menandai centang. | REVIEW |
| TASK-HK-023 | FR-HK-023 | Wajib | Nilai tagihan laundry otomatis dibentuk berdasarkan daftar harga per item dan diposkan ke folio kamar. | REVIEW |
| TASK-HK-024 | FR-HK-024 | Wajib | Setelah laundry selesai, Housekeeping menerima notifikasi untuk mengantarkan kembali ke kamar dan menutup order dengan bukti penerimaan. | REVIEW |

### Slice 42 (2026-10-03): faults found by housekeeping become work orders

- Status: `TASK-HK-008` is `REVIEW`. A room attendant (or anyone who performs, manages or inspects housekeeping work) opens "Report a fault", picks the room or writes the place, chooses the kind of work, says what is wrong, may mark it urgent and attach a photo; Maintenance gets a work order at once, reported for that person with the department `housekeeping`, and the screen lists the faults they reported with the state of each work order (waiting, being fixed, fixed, cancelled). The work order is the record; an audit entry names its number.
- Context: Maintenance contract `DamageReporting` (implemented by `DamageReportService` over `WorkOrderService::report`, checking no privilege), `RoomDamageReportService`, `DamageReportController`, page `housekeeping/pages/damage-reports` over the shared `DamageReportPanel`.
- Evidence: `tests/Feature/Maintenance/DamageReportHttpTest.php`.

## Required engineering checks

- Identify aggregate owner and state transition before coding.
- Enforce property scope and server-side authorization.
- Define transaction/idempotency/concurrency behavior where mutation is critical.
- Emit audit evidence for sensitive/state-changing operations.
- Add happy, negative, conflict/retry, and permission tests as applicable.
- Update traceability/evidence before marking DONE.
