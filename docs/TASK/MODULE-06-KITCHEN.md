# Kitchen — Task Contract

**Bounded context:** Kitchen
**Critical note:** KDS/recipe/production facts feed inventory; do not mutate inventory tables directly.

## Candidate aggregates / read models

- `KitchenTicket`
- `Recipe`
- `Production/WasteRecord`
- `MenuAvailability`

## Requirement backlog

| Task ID | FR | Priority | Requirement | Status |
| --- | --- | --- | --- | --- |
| TASK-KIT-001 | FR-KIT-001 | Wajib | Menampilkan tiket pesanan dari POS pada layar dapur secara berurutan beserta waktu tunggu dan penanda keterlambatan. | REVIEW |
| TASK-KIT-002 | FR-KIT-002 | Wajib | Mengubah status tiket menjadi diproses, siap, dan sudah diantar sehingga pelayan menerima pemberitahuan. | REVIEW |
| TASK-KIT-003 | FR-KIT-003 | Wajib | Mengelola resep dan komposisi bahan (bill of material) berversi untuk setiap menu, termasuk yield, waste standar, satuan, dan tanggal efektif, sebagai dasar biaya bahan dan harga pokok. | TODO |
| TASK-KIT-004 | FR-KIT-004 | Wajib | Mengurangi stok bahan secara otomatis berdasarkan versi resep yang berlaku setiap kali item menu diposting sebagai penjualan, tepat satu kali untuk setiap transaksi. | TODO |
| TASK-KIT-005 | FR-KIT-005 | Wajib | Menandai menu yang habis sehingga otomatis tidak dapat dipesan dari POS maupun menu QR tamu. | IN_PROGRESS |
| TASK-KIT-006 | FR-KIT-006 | Wajib | Mencatat pemakaian bahan, produksi persiapan, dan pembuangan bahan rusak (waste log) beserta alasan. | TODO |
| TASK-KIT-007 | FR-KIT-007 | Wajib | Melakukan stock opname bahan dapur dan gudang kering dengan pencatatan selisih dan nilai kerugian. | TODO |
| TASK-KIT-008 | FR-KIT-008 | Wajib | Menampilkan daftar periksa kebersihan, suhu penyimpanan, dan tugas harian, mingguan, serta bulanan dapur. | TODO |
| TASK-KIT-009 | FR-KIT-009 | Sebaiknya | Mencatat tanggal kedaluwarsa dan nomor batch bahan sensitif dengan peringatan mendekati kedaluwarsa. | TODO |
| TASK-KIT-010 | FR-KIT-010 | Wajib | Membuat laporan kerusakan peralatan yang diteruskan ke modul Maintenance. | TODO |
| TASK-KIT-011 | FR-KIT-011 | Wajib | Mengajukan permintaan pembelian bahan dan peralatan ke modul Purchasing. | TODO |
| TASK-KIT-012 | FR-KIT-012 | Sebaiknya | Menerbitkan laporan penjualan menu, rasio biaya bahan terhadap penjualan, dan analisis menu berdasarkan popularitas serta kontribusi margin. | TODO |
| TASK-KIT-013 | FR-KIT-013 | Wajib | Setiap perubahan resep menghasilkan versi baru bertanggal efektif; transaksi lama selalu mereferensikan versi resep yang berlaku saat transaksi diposting. | TODO |
| TASK-KIT-014 | FR-KIT-014 | Sebaiknya | Mendukung produksi/preparation batch (misalnya sauce, dough, stock) yang mengonsumsi bahan baku dan menghasilkan semi-finished goods beserta yield aktual. | TODO |
| TASK-KIT-015 | FR-KIT-015 | Wajib | KDS menyediakan indikator koneksi dan antrean; bila layar atau jaringan bermasalah, tiket tetap tersimpan dan dapat dialihkan ke printer/fallback queue tanpa kehilangan order. | IN_PROGRESS |

## Progress notes

### Slice 24 (2026-10-03): the kitchen and bar screen

- Status: `TASK-KIT-001` and `-002` are `REVIEW`. `TASK-KIT-005` and `-015` are `IN_PROGRESS`: sold out blocks the point of sale and the screen shows its age, but the guest QR menu does not exist yet and a printer fallback is not built. ADR/BR: property scope, the Application layer reads other contexts only through contracts and events, audit of every setting and of every sold-out change.
- Context: new bounded context `Kitchen` (`app/Modules/Kitchen`), migration 75 (`kitchen_tickets`, `kitchen_ticket_lines`, `kitchen_settings`, and `prep_status` on `fnb_bill_lines`), `TicketStore` with `DatabaseTicketStore`, `TicketIntakeConsumer`, `BoardService`, `BoardController`, page `kitchen/pages/board`, routes `/kitchen`. Permissions `kitchen.board.operate` (works the screen, marks dishes sold out) and `kitchen.settings.manage` (sets the waiting limit).
- **Tickets.** The point of sale publishes `fnb.order.sent`; the kitchen turns it into one ticket per station (kitchen or bar) with the table, room or counter it goes to, the lines with variant, choices and note, and the moment it was sent. A line no station prepares never reaches a screen and is served when sent. Handling the same send twice makes its tickets once (unique per send and station). A line voided, or a bill cancelled, strikes its dishes off the screens (a ticket left with no dish is cancelled), so nobody cooks what will not be paid.
- **Steps.** New, preparing, ready, served. A cook may also finish a ticket that was never started. Whoever moves a ticket and when are kept; a move names the version of the ticket the cook saw, so two screens cannot move it twice. Every move publishes `kitchen.ticket.progressed`; the point of sale reads it (`KitchenProgressConsumer`, in F&B) and shows the waiter how far each line is on the bill, and how many lines are ready on the table of the floor plan.
- **Waiting and late.** The screen shows how long a ticket waited, counting up each second against the server's clock (a wrong clock on the device does not matter). A new or preparing ticket waiting longer than the limit is marked late. Baseline: 15 minutes; a manager sets 1 to 240 minutes with a reason, audited, with the version of the setting.
- **Sold out (`KIT-005`).** A tab lists the menu; marking a dish sold out goes through the `MenuAvailability` contract (F&B keeps the menu and audits the change as set by the kitchen), and the point of sale refuses to order it until it is put back.
- **Connection (`KIT-015`).** The screen reloads itself every 15 seconds and says when it last got the tickets; when the network fails it keeps what it has, says so with the time of that picture, and keeps trying. Tickets are stored whatever the state of a screen, so nothing is lost; a printer or fallback queue for a dead screen is not built.
- Not yet: recipes and stock deduction (`KIT-003`, `-004`, `-013`), waste log (`KIT-006`), preparation batches, the guest QR menu that must also honour sold out, a ticket printer, sound alerts, per-item prep times.
- Evidence: `tests/Feature/Kitchen/BoardHttpTest.php` (one ticket per station and none for what no station prepares, place of table, room and counter, the steps with lock and who, what the waiter sees on the bill and the floor, void and cancel take dishes off the screens, late by the property's limit and its audited setting, sold out blocks ordering and putting back allows it, permissions).

## Required engineering checks

- Identify aggregate owner and state transition before coding.
- Enforce property scope and server-side authorization.
- Define transaction/idempotency/concurrency behavior where mutation is critical.
- Emit audit evidence for sensitive/state-changing operations.
- Add happy, negative, conflict/retry, and permission tests as applicable.
- Update traceability/evidence before marking DONE.
