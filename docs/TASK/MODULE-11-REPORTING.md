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
| TASK-RPT-001 | FR-RPT-001 | Wajib | Menyediakan pusat laporan yang mengelompokkan seluruh laporan berdasarkan department dan tema. | REVIEW |
| TASK-RPT-002 | FR-RPT-002 | Wajib | Seluruh laporan mendukung penyaring rentang tanggal, outlet, department, dan pengguna. | IN_PROGRESS |
| TASK-RPT-003 | FR-RPT-003 | Wajib | Seluruh laporan dapat diekspor ke PDF dan lembar kerja, serta dicetak. | REVIEW |
| TASK-RPT-004 | FR-RPT-004 | Sebaiknya | Laporan dapat dijadwalkan untuk dikirim otomatis melalui surel atau pesan instan pada waktu tertentu kepada penerima tertentu. | REVIEW |
| TASK-RPT-005 | FR-RPT-005 | Wajib | Menyediakan laporan ringkas harian untuk manajemen (flash report) yang memuat okupansi, pendapatan, biaya utama, dan kejadian penting. | REVIEW |
| TASK-RPT-006 | FR-RPT-006 | Sebaiknya | Menyediakan pembanding antar periode: hari ini dibanding kemarin, bulan ini dibanding bulan lalu, dan tahun berjalan dibanding tahun sebelumnya. | REVIEW |
| TASK-RPT-007 | FR-RPT-007 | Wajib | Menyediakan jejak audit yang dapat dicari berdasarkan pengguna, modul, dan rentang waktu. | REVIEW |
| TASK-RPT-008 | FR-RPT-008 | Bisa | Menyediakan pembuat laporan sederhana bagi pengguna mahir untuk memilih kolom dan penyaring sendiri. | REVIEW |
| TASK-RPT-009 | FR-RPT-009 | Wajib | Setiap laporan menampilkan generated-at time, business date/periode, filter yang digunakan, dan sumber data utama sehingga hasil dapat direproduksi. | REVIEW |
| TASK-RPT-010 | FR-RPT-010 | Wajib | Kolom PII pada laporan mengikuti hak akses dan dapat dimasking; ekspor data sensitif dicatat pada audit log dengan pengguna, waktu, filter, dan tujuan. | REVIEW |
| TASK-RPT-011 | FR-RPT-011 | Sebaiknya | Ekspor besar diproses asynchronous dengan status pekerjaan dan notifikasi selesai agar tidak membebani transaksi operasional. | REVIEW |

### Slice 63 (2026-10-03): scheduled reports

- Status: `TASK-RPT-004` is `REVIEW`. The instant-message channel is not built (no provider is chosen); the e-mail channel sends a notice through the mailer the installation configures (`MAIL_MAILER`, the log mailer until a provider is chosen).
- Context: migration 109 (`report_schedules`, `report_schedule_recipients`, `report_schedule_runs` append-only, `report_export_jobs.schedule_id`), `ReportScheduleCalendar` (pure), `ReportScheduleRepository`/`DatabaseReportScheduleRepository`, `ReportScheduleService`, `ReportScheduleController`, `RunReportSchedulesCommand` (`reports:run-schedules`, every minute in `routes/console.php`), `ScheduledReportNoticeConsumer`, `ReportNotifier`/`MailReportNotifier`, `StaffContacts` (a Shared contract; the identity module answers with the addresses of active members), privilege `reporting.schedule.manage`, page `reporting/pages/schedules.tsx`.
- **Schedule.** A report that can be exported in the background (flash, payments, performance, comparison, housekeeping, laundry, movements, registrations, foreign guests), the period it covers relative to the day it runs (today so far, yesterday, last 7 days, month so far; by day, month or year for performance and comparison; today or yesterday for movements), how often (daily, a weekday, a day of the month from 1 to 28), the time on the property's clock, up to 20 recipients and whether to notify by e-mail. At most 50 schedules; one can be paused and resumed, and is changed only at the version seen.
- **Run.** The runner asks, for every due schedule, for the export of each recipient through the same background export as a person's own request, so each file is built as that person with their own rights and audit, kept privately for them and shown on their exports page. A recipient who left or may no longer export the report is skipped and the run records who and why (`report_schedule_runs`); the creator and every recipient must be allowed to export the report when the schedule is made. A run missed because the system was down runs once when it is back, never once for every missed time; a time skipped by a daylight-saving gap runs at the first moment after it. Personal-data reports are exported with the purpose "Scheduled report: name", like any export of them.
- **Notice.** When the schedule asks, the recipient is told by e-mail that the file is ready (or could not be built), in Indonesian and English, with the address of the exports page and no figures; a person with no address or a mailer that fails never holds the message or undoes the report.
- Not yet: attaching the file to the e-mail (deliberately not done: figures and personal data stay behind the person's sign-in), instant messages, recipients outside the property, and schedules for the other report screens (they join as they become exportable in the background).
- Evidence: `tests/Unit/Modules/Reporting/ReportScheduleCalendarTest.php` (daily, weekly and monthly times on the property's clock, one run after downtime, invalid inputs, a daylight-saving gap), `tests/Feature/Reporting/ReportScheduleHttpTest.php` (runs at their time once per time, the file built as the recipient, the e-mail without figures, a skipped recipient, validation, versions, pause and resume, rights).

## Required engineering checks

- Identify aggregate owner and state transition before coding.
- Enforce property scope and server-side authorization.
- Define transaction/idempotency/concurrency behavior where mutation is critical.
- Emit audit evidence for sensitive/state-changing operations.
- Add happy, negative, conflict/retry, and permission tests as applicable.
- Update traceability/evidence before marking DONE.

- Evidence (TASK-RPT-005, the flash report): the report now has the main operating costs per department (supplier invoices, petty cash, recurring expenses and stock consumed, from the management profit and loss of finance, shown only to those who may see that report and only for a range finance reports, at most 366 days) and the notable events of the period (a baseline list of audit actions counted without who did them or the guest: bills cancelled, items voided after sending, bills refunded, finance exceptions raised, petty cash vouchers voided, laundry claims, laundry orders and work orders escalated). `tests/Integration/Reporting/ReportingTest.php::test_the_flash_report_adds_the_main_costs_for_finance_and_counts_the_notable_events`. The list of notable events is a baseline for the General Manager to confirm. The CSV export still holds the daily rows only. Not yet seen in a browser.

- Open design decision (2026-10-07). `TASK-RPT-002` is `IN_PROGRESS`: all reports take a date range, and the audit trail also filters by person and by module (department). The outlet, department and user filters for the other reports depend on the same question as `TASK-DSH-022` (which registry is an outlet) and on which dimension each report really has (for example the payments report has a method and a posting source, the housekeeping report an attendant). It is to be built report by report once that is decided.
