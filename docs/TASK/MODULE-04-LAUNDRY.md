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
| TASK-LDY-011 | FR-LDY-011 | Wajib | Setiap order memiliki promised time/SLA; order express dan order melewati janji selesai diberi prioritas serta notifikasi eskalasi. | REVIEW |
| TASK-LDY-012 | FR-LDY-012 | Wajib | Sistem mencegah penutupan stay bila guest laundry masih berstatus aktif, kecuali diubah menjadi late charge/claim melalui persetujuan yang tercatat. | REVIEW |

### Slice 42 (2026-10-03): purchase requests of laundry

- Status: `TASK-LDY-009` is `REVIEW`. The laundry menu links to the purchase requests of the department (`/inventory/requests?department=laundry`): only its requests are listed and a new one starts for laundry, for chemicals and equipment; the request, its approval chain and its ordering are Purchasing's, which also checks the privilege to ask.
- Evidence: `tests/Feature/InventoryPurchasing/PurchasingHttpTest.php` (the department view).

### Slice 45 (2026-10-03): supplies the laundry uses

- Status: `TASK-LDY-008` is `REVIEW`. The laundry records the chemicals and supplies it uses up (an item of the department laundry, the store, the quantity, a note) on the page "Supplies used"; the stock card of the store takes it out at the average cost as laundry consumption, never below zero, and the laundry sees what it used lately. People who process orders see the list; recording needs `laundry.supplies.use`.
- Context: Inventory contract `DepartmentSupplyUse` (issue of the item as consumption of a department, checking no privilege), `SupplyUseService`, `SupplyUseController`, page `laundry/pages/supplies`.
- Evidence: `tests/Feature/InventoryPurchasing/RequisitionAndSupplyHttpTest.php`.

### Evidence (2026-10-03): escalation of a late order (`LDY-011`)

- `TASK-LDY-011` is `REVIEW`. Every order has a promised time (set at hand-over); the work list already puts express orders first, then the earliest promise, and flags an overdue order. The escalation notice is now sent: `laundry:escalate` (scheduler, every ten minutes, one property failing does not stop the others) finds the orders still in the laundry's hands past their promise, marks each one (`laundry_orders.escalated_at`, set once under a conditional update so two runs cannot both send), records the audit entry and the outbox event `laundry.order.escalated`, and then e-mails the staff who process laundry or may cancel an order (the supervisor) the number, room, promised time and state. The e-mail goes through the `EmailNotifier` contract (the mailer the installation configured; the log mailer until a provider is chosen); a notice that cannot be sent never undoes the mark or the order. The dashboard alert for overdue laundry stays for as long as the order is overdue.
- Evidence: `tests/Integration/Laundry/LaundryTest.php` (`test_an_order_past_its_promise_is_told_to_the_laundry_staff_once`).

## Required engineering checks

- Identify aggregate owner and state transition before coding.
- Enforce property scope and server-side authorization.
- Define transaction/idempotency/concurrency behavior where mutation is critical.
- Emit audit evidence for sensitive/state-changing operations.
- Add happy, negative, conflict/retry, and permission tests as applicable.
- Update traceability/evidence before marking DONE.

- Decision and evidence (2026-10-07, `TASK-LDY-012`, FR-LDY-012, FR-FO-038, BR-004, BR-005). The PRD gives the Guest Laundry state machine a **Claim** branch (section 20) and a **late charge** is "a charge that appears after the guest's folio is closed" (glossary, FR-FO-038), so the exception means this: a stay is closed with laundry still in hand only when the person who checks the guest out turns each active order into
  - a **late charge**: the order goes on, and when it is ready it is charged to the late folio of the stay (never to the closed folio and never to a past day), or
  - a **claim**: the order becomes `claimed`, leaves the laundry's work, and nothing is charged; what the guest is owed or owes is then dealt with by the claim procedure of `TASK-LDY-006`.

  Both need an approval that is recorded (`front-office.laundry-exception`, **mandatory**: with no policy configured it is refused, never allowed; the owner configures who approves). The request names the stay, the way out, the reason and exactly the orders concerned (an approval cannot be used for other orders), the person checking the guest out must be the maker and a second person decides (BR-004), and the approval is used once. What was agreed is an immutable record of the stay (`stay_laundry_exceptions`); each order keeps how it was settled (`settlement`, the approval and the reason) and cannot change it. A late charge for a guest who left is allowed only when such a record exists. Evidence: `tests/Integration/Laundry/LaundryTest.php` (`test_a_stay_closes_with_laundry_in_hand_as_a_claim_only_with_a_recorded_approval`, `test_laundry_left_in_hand_as_a_late_charge_goes_on_and_is_charged_to_the_late_folio_when_ready`, `test_an_approval_is_for_exactly_the_orders_it_named`). The stay screen asks for and picks the approval in the check-out dialog. Not yet seen in a browser. A person who is owed or charged more than the order's value is still a matter for the claim procedure and the Manager on Duty.
