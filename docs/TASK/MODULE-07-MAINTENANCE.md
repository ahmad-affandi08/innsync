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
| TASK-MTC-001 | FR-MTC-001 | Wajib | Menerima laporan kerusakan dari seluruh department dan mengubahnya menjadi work order bernomor unik dengan lokasi, kategori, dan foto. | REVIEW |
| TASK-MTC-002 | FR-MTC-002 | Wajib | Menetapkan prioritas (mendesak, tinggi, normal, rendah) dan batas waktu penyelesaian sesuai kesepakatan tingkat layanan. | REVIEW |
| TASK-MTC-003 | FR-MTC-003 | Wajib | Menugaskan work order kepada teknisi tertentu dan menampilkannya pada ponsel teknisi. | REVIEW |
| TASK-MTC-004 | FR-MTC-004 | Wajib | Mengikuti status pekerjaan: berjalan, selesai, dan belum selesai beserta alasan bila tertunda (menunggu suku cadang, menunggu vendor, atau menunggu akses kamar). | REVIEW |
| TASK-MTC-005 | FR-MTC-005 | Wajib | Foto hasil pekerjaan wajib dilampirkan sebelum work order dapat ditandai selesai; sistem menolak penutupan tanpa foto. | REVIEW |
| TASK-MTC-006 | FR-MTC-006 | Wajib | Menetapkan kamar menjadi Out of Order atau Out of Service beserta perkiraan tanggal selesai; status ini langsung memblokir penjualan kamar di Front Office. | REVIEW |
| TASK-MTC-007 | FR-MTC-007 | Sebaiknya | Mengelola daftar aset properti (mesin, peralatan, kendaraan) beserta nomor aset, tanggal perolehan, garansi, dan riwayat perbaikan. | REVIEW |
| TASK-MTC-008 | FR-MTC-008 | Sebaiknya | Menyusun jadwal pemeliharaan pencegahan berkala per aset dan menghasilkan work order secara otomatis pada tanggalnya. | REVIEW |
| TASK-MTC-009 | FR-MTC-009 | Sebaiknya | Mengelola persediaan suku cadang dan mencatat pemakaiannya pada setiap work order. | TODO |
| TASK-MTC-010 | FR-MTC-010 | Wajib | Mengajukan permintaan pembelian alat dan suku cadang ke modul Purchasing, termasuk pekerjaan yang dikerjakan vendor luar. | TODO |
| TASK-MTC-011 | FR-MTC-011 | Wajib | Menampilkan SOP tugas harian, mingguan, dan bulanan teknik seperti pemeriksaan genset, pompa, dan pendingin ruangan. | TODO |
| TASK-MTC-012 | FR-MTC-012 | Wajib | Menerbitkan laporan: work order per status dan department pelapor, waktu penyelesaian rata-rata, kerusakan berulang per kamar, biaya perbaikan, dan hari kamar tidak dapat dijual. | IN_PROGRESS |
| TASK-MTC-013 | FR-MTC-013 | Wajib | Work order yang mendekati atau melewati SLA menghasilkan eskalasi ke supervisor/MOD sesuai matriks prioritas dan shift. | REVIEW |
| TASK-MTC-014 | FR-MTC-014 | Sebaiknya | Mencatat meter reading/usage counter untuk aset yang membutuhkan preventive maintenance berdasarkan jam operasi, kilometer, atau siklus selain kalender. | REVIEW |
| TASK-MTC-015 | FR-MTC-015 | Sebaiknya | Pekerjaan vendor eksternal memiliki quotation, approval, jadwal, biaya aktual, bukti pekerjaan, dan relasi ke aset/work order. | TODO |

## Progress notes

### Slice 29 (2026-10-03): work orders, technicians, photos and rooms off sale

- Status: `TASK-MTC-001` to `-006` are `REVIEW`. ADR/BR: property scope, a closed work order and its history never change, the maintenance context does not touch front office tables (it asks through a contract).
- Context: new bounded context `Maintenance` (`app/Modules/Maintenance`), migration 81 (`maintenance_work_orders`, `maintenance_work_events` append-only by trigger, `maintenance_settings`), `WorkOrderStore` with `DatabaseWorkOrderStore`, `WorkOrderService`, `WorkOrderController`, page `maintenance/pages/work-orders`, routes `/maintenance`. Privileges `maintenance.work.report` (every department), `maintenance.work.perform` (technicians) and `maintenance.work.manage`. In the front office: the `RoomBlocking` contract and `RoomBlockingService`, over `InventoryAdminService::placeBlock` and `removeBlock` (the same rules as a block placed by hand, without the front office's own privilege, which the caller has checked).
- **Report (`MTC-001`).** Anyone with a maintenance privilege reports a fault: a title, details, the kind of work (electrical, plumbing, air conditioning, furniture, appliance, building, IT, other), the department that reports it, where (a room of the property or a named place; one is needed), the priority and an optional photo. It becomes one work order, `WO-000001`. A person who only reports sees only what they reported; technicians see what is given to them; managers see everything.
- **Priority and deadline (`MTC-002`).** Urgent, high, normal, low; each has the time it may take, counted from the report (baseline: one hour, four hours, a day, three days; the manager sets them for the property, in order, audited with the version of the setting). Changing the priority recalculates the deadline from the report. A work order past its deadline is flagged and counted.
- **Technician (`MTC-003`).** The manager gives a work order to a person who holds the privilege to perform; the technician's tab lists their jobs, which works on a phone. A work order is started and worked by the technician it was given to (or a manager); a job nobody holds cannot be started.
- **Status (`MTC-004`).** Open, assigned, in progress, on hold (with what it waits for: parts, a vendor, or access to the room, and a note), done and cancelled (with a reason, by the manager). Every step names the version of the work order that was seen, and is kept in its history with who and when.
- **Proof (`MTC-005`).** Finishing needs a note and a photo of the finished work; without the photo the work order is refused, and the database refuses a done work order with no photo as well. Photos are kept privately, readable only by people who may see work orders, and their retention starts when the work order closes.
- **Rooms (`MTC-006`).** A manager takes the room of a work order off sale as out of order or out of service until a date, from the business date; the front office keeps the block (so no room is sold or overlapped), and says which nights are now oversold so reservations can be moved. The room goes back on sale when the work is done or the work order is cancelled (a block the front office released already is no obstacle), or by hand.
- Not yet: spare parts (`MTC-009`), purchase requests for parts and vendor work (`MTC-010`, `-015`), the routine duties (`MTC-011`), the reports (`MTC-012`), turning a guest request of the maintenance category into a work order, a push notice to the technician's phone.
- Evidence: `tests/Feature/Maintenance/WorkOrderHttpTest.php` (the report with its number, deadline, photo and checks, who sees what; the whole path from giving the job to the finished photo, holds, stale screens and the history that cannot be changed; priority, service levels and the late flag; a room taken off sale, put back by hand, by completing and by cancelling, with the front office's block). Seen in the browser: a fault reported with a photo, given, started, refused without a photo and finished with one.

### Slice 30 (2026-10-03): escalation by deadline and shift, and the reports

- Status: `TASK-MTC-013` is `REVIEW`; `TASK-MTC-012` is `IN_PROGRESS` (the cost of repairs needs spare parts and vendor work, which are not built). ADR/BR: facts that are never edited, audit of every escalation and acknowledgement (by the system, no person), the scheduler and the page do not depend on each other.
- Context: migration 82 (`maintenance_escalations`, `maintenance_room_blocks` as the history of rooms off sale, and the escalation settings on `maintenance_settings`), `EscalationService`, `ReportService`, `ReportController`, command `maintenance:escalate` (scheduled every five minutes), pages `maintenance/pages/reports` and the escalation panel of the work orders page, privilege `maintenance.escalation.receive` for the manager on duty.
- **Escalation (`MTC-013`).** A work order is escalated when it has used a share of its time: the warning share (baseline 75 percent) raises level 1, the whole of it (baseline 100) level 2. The matrix: an urgent or high work order has two levels, a normal or low one only the first; in the day shift level 1 goes to the supervisor (whoever manages work orders) and level 2 to the manager on duty; in the night shift (baseline 22:00 to 06:00 of the property's own day, crossing midnight) both go to the manager on duty. Each level is raised once, kept as a fact with whom it is for and the shift, shown on the page of those people until one of them acknowledges it (with a note, audited), and taken off when the work order closes. The owner sets the two shares and the hours of the night with the service levels. It runs from the scheduler and again when a manager opens the page.
- Not delivered: there is no push, SMS or e-mail to the person yet; escalations are on their screen. A third level (the general manager) and a matrix per outlet are not built.
- **Reports (`MTC-012`).** For a period of up to a year: what was reported, by status and by the department that reported it; the work finished, its average time from the report and the share finished by its deadline, in all and by priority; the rooms with two or more faults and what kind; and the nights each room was off sale because of a work order (counted from the day the room was taken off sale until it went back or its last night, within the period).
- Evidence: `tests/Feature/Maintenance/EscalationReportHttpTest.php` (levels by share of time, to whom, once; the matrix by priority and by night shift; acknowledgement, who may and once; closing takes it off; the scheduler without the page; the settings; the reports with the figures above). Seen in the browser: an overdue work order escalating on the page and acknowledged, and the report page.

### Slice 31 (2026-10-03): assets, meter readings and preventive plans

- Status: `TASK-MTC-007`, `-008` and `-014` are `REVIEW`. ADR/BR: an asset is retired, never deleted; meter readings are facts that are never edited (database triggers) and never go down; a preventive work order is made once for each cycle (a unique key in the database), by the system (audit without a person).
- Context: migration 83 (`maintenance_assets`, `maintenance_meter_readings` append-only by trigger, `maintenance_pm_plans`, and `asset_id`, `plan_id`, `pm_due` on `maintenance_work_orders`, unique on `plan_id` + `pm_due`), `AssetStore` with `DatabaseAssetStore`, `AssetService`, `PreventiveService`, `AssetController`, command `maintenance:preventive` (hourly), page `maintenance/pages/assets`, the asset choice on the report form. Privileges stay `maintenance.work.manage` (assets, plans, retiring) and `maintenance.work.perform` (meter readings).
- **Assets (`MTC-007`).** A manager keeps the machines, equipment, vehicles, buildings, IT and other assets of the property: number `AST-000001`, name, kind, serial number, a room or a named place, date acquired, warranty until, notes. The warranty shows as valid, ending (within 60 days) or expired. A retired asset (with a reason and the date) stops its plans. Each asset shows its repairs and care: every work order that names it, with its history; a report may name the asset, and when it names no place it takes the place of the asset.
- **Meter (`MTC-014`).** An asset may have a meter in hours, kilometres or cycles. A technician or manager records a reading; it must not be lower than the last one (the history is kept, with who and when).
- **Preventive plans (`MTC-008`).** A plan by the calendar (every N days, first due on a date or one interval from today, the work order made some days before it falls due) or by the meter (every N units from the reading when the plan was set). The scheduler (and opening the assets page) makes the work order, as an engineering work order with its deadline by priority, once for each cycle; it makes none while the plan has a work order still open. Finishing the work order rolls the plan: the next date counts from the day it was done, the next meter count from the reading then. Cancelling a work order frees its cycle so it may be made again; a plan may be paused and resumed.
- Not yet: spare parts used (`MTC-009`), purchase requests and vendor work (`MTC-010`, `-015`), routine duty lists (`MTC-011`), the cost of repairs in the report, depreciation and a link to the finance books, a push notice for preventive work.
- Evidence: `tests/Feature/Maintenance/AssetPreventiveHttpTest.php` (an asset kept with place, warranty and meter and retired; readings that cannot go down; a calendar plan through its cycle and roll; lead time, cancel, pause and a retired asset; a meter plan falling due; a report naming the asset). Seen in the browser: an asset created with a meter, a reading recorded, the plan form.

## Required engineering checks

- Identify aggregate owner and state transition before coding.
- Enforce property scope and server-side authorization.
- Define transaction/idempotency/concurrency behavior where mutation is critical.
- Emit audit evidence for sensitive/state-changing operations.
- Add happy, negative, conflict/retry, and permission tests as applicable.
- Update traceability/evidence before marking DONE.
