# Cross-Module Product Contract

This file is a compact agent index. The canonical wording remains in `PRD.md`.

## Cross-module business rules

| Code | Rule | Implication |
| --- | --- | --- |
| BR-001 | Business date dipisahkan dari timestamp kalender dan dikelola per properti melalui night audit. | Laporan harian, posting room charge, shift, dan settlement menggunakan business date yang sama. |
| BR-002 | Nilai uang dihitung dengan tipe integer/minor unit yang aman dan aturan pembulatan terkonfigurasi; dilarang memakai floating-point untuk nilai finansial inti. | Subtotal, pajak, service charge, discount, payment, dan refund harus menghasilkan rekonsiliasi deterministik. |
| BR-003 | Data transaksi yang sudah posted/closed tidak dihapus atau ditimpa. | Koreksi dilakukan melalui void, reversal, refund, adjustment, atau dokumen koreksi yang menaut ke transaksi asal. |
| BR-004 | Maker-checker untuk tindakan sensitif dan tidak ada self-approval pada workflow yang mewajibkan approval. | Threshold dan approver chain dapat dikonfigurasi per properti. |
| BR-005 | Semua operasi yang dapat diulang (checkout, payment callback, sync offline, import, webhook) wajib idempotent. | Retry aman dan tidak menghasilkan transaksi, stok, atau posting keuangan ganda. |
| BR-006 | Nomor dokumen operasional unik per property dan jenis dokumen serta tidak digunakan ulang setelah void. | Traceability tetap terjaga pada reservasi, folio, bill, payment, WO, PR, PO, GR, stock count, dan payroll run. |
| BR-007 | Kebijakan stok negatif, overselling, dan override ditetapkan eksplisit per properti. | Default sistem mencegah kondisi tidak valid; override membutuhkan reason dan privilege. |
| BR-008 | Status kamar bersifat multidimensi: occupancy, housekeeping, sellability, dan service flag. | DND/Double Lock tidak mengubah occupancy; Complimentary adalah atribut rate/folio, bukan room status. |
| BR-009 | PII menggunakan prinsip minimum necessary dan masking berdasarkan role. | Akses/export data identitas, karyawan, dan informasi finansial sensitif selalu diaudit. |
| BR-010 | Offline queue menyimpan state sinkronisasi dan correlation/idempotency key. | Konflik harus terlihat dan dapat direkonsiliasi; transaksi finansial tidak boleh silently overwrite. |
| BR-011 | Entitas yang sering diubah serentak memakai concurrency control. | Bill, folio, availability, stock count, dan approval tidak boleh kehilangan update dari perangkat lain. |
| BR-012 | Setiap domain mempunyai system of record yang jelas. | Laporan dan dashboard membaca sumber resmi, bukan salinan manual per department. |

## System of Record

| Domain | System of Record | Primary Consumers |
| --- | --- | --- |
| Reservasi, stay, room assignment, folio | Front Office | Dashboard, Housekeeping, Finance, Guest Self-Service |
| Room cleanliness & inspection | Housekeeping | Front Office, Dashboard |
| Bill outlet & item penjualan | F&B Service/POS | Kitchen, Front Office, Finance, Inventory |
| Recipe, production, kitchen availability | Kitchen | POS, Inventory, Reporting |
| Stok, mutasi, costing | Inventory | Kitchen, Purchasing, Finance, Dashboard |
| PR/PO/receiving/vendor | Purchasing | Inventory, Finance, Department peminta |
| Work order & asset maintenance | Maintenance | Front Office, Dashboard, Reporting |
| Employment, roster, attendance, payroll basis | Human Resource | Dashboard, Finance |
| Settlement, AP/AR, tax/service charge, management finance | Finance | Dashboard, Reporting |
| Audit log & security events | Platform/Audit | Owner/GM, Auditor, Support berwenang |

## State machines and critical invariants

| Domain | Primary States | Critical Invariant |
| --- | --- | --- |
| Reservation | Tentative → Confirmed/Guaranteed → Checked-in → Completed; cabang Cancelled/No-show | Checked-in hanya bila inventory/room assignment valid; cancellation/no-show tidak menghapus histori deposit/penalty. |
| Room | Occupancy + Housekeeping + Sellability + Service Flag | Hanya sellable + ready yang dapat dijual; OOO/OOS memblokir availability sesuai policy. |
| Folio | Open → Partially Settled → Settled → Closed; koreksi via Reopened/Adjustment terkontrol | Saldo harus dapat direkonsiliasi ke charge, payment, refund, dan adjustment; tidak ada hard delete. |
| POS Bill | Open → Sent → Partially Paid → Paid/Closed; cabang Void | Bill paid tidak dapat diedit; perubahan pasca bayar melalui refund/void terkontrol. |
| Payment | Initiated → Pending → Paid/Failed/Expired/Unknown → Refunded/Partially Refunded | Unknown bukan Paid; callback/retry idempotent; settlement reference unik. |
| Guest Laundry | Open → Received → In Process → Ready → Returned → Closed; cabang Claim | Jumlah item antar handover selalu direkonsiliasi. |
| Work Order | Open → Assigned → In Progress → On Hold → Completed → Verified → Closed; cabang Cancelled | OOO/OOS dan SLA mengikuti work order; bukti wajib sebelum complete pada kategori tertentu. |
| Purchasing | PR Draft → Submitted → Approved/Rejected → PO Issued → Partially Received → Received/Closed | Perubahan material setelah approval membuat revisi dan dapat memerlukan approval ulang. |
| Night Audit | Pre-check → Blocked/Ready → Posting → Completed | Posting idempotent; business date hanya maju setelah semua gate wajib lulus atau waiver terdokumentasi. |
| Payroll Run | Draft → Calculated → Reviewed → Approved → Paid → Locked | Periode locked tidak ditimpa; koreksi melalui adjustment/reopen berizin. |
