# Laundry — Task Contract

**Bounded context:** Laundry
**Critical note:** Item counts and charge linkage must reconcile with Housekeeping/folio.

## Candidate aggregates / read models

- `GuestLaundryOrder`
- `LaundryBatch/LinenProcess`
- `LaundryClaim`

## Requirement backlog

| Task ID | FR | Priority | Requirement | Status |
| --- | --- | --- | --- | --- |
| TASK-LDY-001 | FR-LDY-001 | Wajib | Menerima daftar order guest laundry dari Housekeeping, dikelompokkan per nomor kamar beserta jumlah item. | REVIEW |
| TASK-LDY-002 | FR-LDY-002 | Wajib | Petugas memverifikasi item yang diterima dengan cara mencentang daftar; selisih jumlah wajib dicatat sebagai temuan sebelum pengerjaan dimulai. | REVIEW |
| TASK-LDY-003 | FR-LDY-003 | Wajib | Mengubah status pengerjaan mengikuti tahapan: diterima, dicuci, dikeringkan, disetrika, siap, dan dikembalikan ke Housekeeping. | REVIEW |
| TASK-LDY-004 | FR-LDY-004 | Wajib | Menandai order selesai sehingga status berubah menjadi selesai dan Housekeeping menerima pemberitahuan untuk pengantaran ke kamar. | REVIEW |
| TASK-LDY-005 | FR-LDY-005 | Sebaiknya | Mencatat perlakuan khusus: cuci kering, noda membandel, setrika saja, dan layanan kilat dengan tarif berbeda. | REVIEW |
| TASK-LDY-006 | FR-LDY-006 | Sebaiknya | Mencatat klaim kerusakan atau kehilangan item tamu beserta foto, nilai penggantian, dan persetujuan Manager on Duty. | REVIEW |
| TASK-LDY-007 | FR-LDY-007 | Wajib | Mengelola linen hotel: penerimaan dari Housekeeping, jumlah dicuci, jumlah rusak atau afkir, dan pengembalian ke gudang. | REVIEW |
| TASK-LDY-008 | FR-LDY-008 | Sebaiknya | Mencatat pemakaian bahan kimia dan perlengkapan laundry sehingga terhubung dengan kartu stok gudang. | REVIEW |
| TASK-LDY-009 | FR-LDY-009 | Wajib | Mengajukan permintaan pembelian bahan dan alat ke modul Purchasing. | REVIEW |
| TASK-LDY-010 | FR-LDY-010 | Sebaiknya | Menerbitkan laporan volume pengerjaan harian, waktu penyelesaian rata-rata, biaya per kilogram, serta pendapatan guest laundry. | REVIEW |
| TASK-LDY-011 | FR-LDY-011 | Wajib | Setiap order memiliki promised time/SLA; order express dan order melewati janji selesai diberi prioritas serta notifikasi eskalasi. | IN_PROGRESS |
| TASK-LDY-012 | FR-LDY-012 | Wajib | Sistem mencegah penutupan stay bila guest laundry masih berstatus aktif, kecuali diubah menjadi late charge/claim melalui persetujuan yang tercatat. | IN_PROGRESS |

### Slice 42 (2026-10-03): purchase requests of laundry

- Status: `TASK-LDY-009` is `REVIEW`. The laundry menu links to the purchase requests of the department (`/inventory/requests?department=laundry`): only its requests are listed and a new one starts for laundry, for chemicals and equipment; the request, its approval chain and its ordering are Purchasing's, which also checks the privilege to ask.
- Evidence: `tests/Feature/InventoryPurchasing/PurchasingHttpTest.php` (the department view).

### Slice 45 (2026-10-03): supplies the laundry uses

- Status: `TASK-LDY-008` is `REVIEW`. The laundry records the chemicals and supplies it uses up (an item of the department laundry, the store, the quantity, a note) on the page "Supplies used"; the stock card of the store takes it out at the average cost as laundry consumption, never below zero, and the laundry sees what it used lately. People who process orders see the list; recording needs `laundry.supplies.use`.
- Context: Inventory contract `DepartmentSupplyUse` (issue of the item as consumption of a department, checking no privilege), `SupplyUseService`, `SupplyUseController`, page `laundry/pages/supplies`.
- Evidence: `tests/Feature/InventoryPurchasing/RequisitionAndSupplyHttpTest.php`.

## Required engineering checks

- Identify aggregate owner and state transition before coding.
- Enforce property scope and server-side authorization.
- Define transaction/idempotency/concurrency behavior where mutation is critical.
- Emit audit evidence for sensitive/state-changing operations.
- Add happy, negative, conflict/retry, and permission tests as applicable.
- Update traceability/evidence before marking DONE.
