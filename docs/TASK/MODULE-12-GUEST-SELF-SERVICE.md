# Guest Self-Service — Task Contract

**Bounded context:** Guest Experience
**Critical note:** Public/session-scoped surface; no guessable IDs or cross-guest data exposure.

## Candidate aggregates / read models

- `GuestSession`
- `SelfCheckInRequest`
- `GuestOrder`
- `ServiceRequest`

## Requirement backlog

| Task ID | FR | Priority | Requirement | Status |
| --- | --- | --- | --- | --- |
| TASK-GST-001 | FR-GST-001 | Wajib | Tamu memindai kode QR di area lobi atau menerima tautan sebelum kedatangan untuk membuka halaman pendaftaran mandiri. | TODO |
| TASK-GST-002 | FR-GST-002 | Wajib | Tamu mengisi data diri, mengunggah atau memotret identitas, dan membubuhkan tanda tangan digital pada kartu registrasi. | TODO |
| TASK-GST-003 | FR-GST-003 | Wajib | Tamu melakukan pembayaran atau deposit melalui QRIS; status pembayaran otomatis tercatat pada folio. | TODO |
| TASK-GST-004 | FR-GST-004 | Wajib | Setelah verifikasi oleh resepsionis, tamu menerima konfirmasi berisi nomor kamar dan petunjuk pengambilan kunci. | TODO |
| TASK-GST-005 | FR-GST-005 | Wajib | Data hasil check-in mandiri masuk ke antrean verifikasi Front Office, bukan langsung mengubah status kamar tanpa persetujuan petugas. | TODO |
| TASK-GST-006 | FR-GST-006 | Wajib | Tautan check-in mandiri menggunakan token acak berumur terbatas, rate limiting, dan validasi reservasi; tautan kedaluwarsa tidak dapat digunakan kembali. | TODO |
| TASK-GST-007 | FR-GST-007 | Wajib | Sebelum mengirim identitas/tanda tangan, tamu diberikan pemberitahuan privasi dan persetujuan yang versinya tersimpan bersama waktu persetujuan. | TODO |
| TASK-GST-010 | FR-GST-010 | Wajib | Setiap kamar dan setiap meja restoran memiliki kode QR unik yang membuka menu digital. | REVIEW |
| TASK-GST-011 | FR-GST-011 | Wajib | Pemesanan dari kamar mewajibkan pengisian nomor kamar dan mencocokkannya dengan nama tamu yang sedang menginap agar terintegrasi dengan kasir dan Front Office. | REVIEW |
| TASK-GST-012 | FR-GST-012 | Wajib | Pesanan dari menu QR masuk ke POS outlet dan layar dapur seperti pesanan yang diambil pelayan, dengan penanda sumber pesanan. | REVIEW |
| TASK-GST-013 | FR-GST-013 | Wajib | Menu yang ditandai habis oleh dapur otomatis tidak dapat dipesan melalui menu QR. | REVIEW |
| TASK-GST-014 | FR-GST-014 | Wajib | Tamu memilih pembayaran langsung melalui QRIS atau pembebanan ke kamar; pembebanan ke kamar memerlukan verifikasi petugas. | REVIEW |
| TASK-GST-015 | FR-GST-015 | Sebaiknya | Tamu dapat mengirim permintaan layanan dan keluhan dari halaman yang sama, yang langsung masuk ke antrean department terkait. | TODO |
| TASK-GST-016 | FR-GST-016 | Sebaiknya | Tamu dapat melihat rincian tagihan berjalan dan mengisi survei kepuasan menjelang keberangkatan. | TODO |
| TASK-GST-017 | FR-GST-017 | Wajib | Halaman tamu tersedia dalam Bahasa Indonesia dan Bahasa Inggris, ringan dibuka pada jaringan lambat, dan tidak memerlukan pemasangan aplikasi. | TODO |
| TASK-GST-018 | FR-GST-018 | Wajib | QR kamar/meja tidak mengekspos identifier internal yang mudah ditebak. Session tamu berumur terbatas dan aksi sensitif seperti room charge memerlukan verifikasi konteks stay. | REVIEW |
| TASK-GST-019 | FR-GST-019 | Sebaiknya | Tamu dapat melihat status pesanan/permintaan layanan tanpa memperoleh akses ke data tamu lain atau histori stay sebelumnya. | TODO |

### Slice 64 (2026-10-03): QR menu, guest session and orders from the phone

- Status: `TASK-GST-010`, `-011`, `-012`, `-013`, `-014` and `-018` are `REVIEW`. `TASK-GST-017` and `-019` follow with the self check-in and the service requests of the next slices.
- Context: new bounded context `GuestExperience` (`app/Modules/GuestExperience`), migration 110 (`ge_qr_points`, `ge_sessions`, `ge_orders`, `fnb_bills.source`, `kitchen_tickets.source`), contracts `GuestOrdering` (F&B sales), `GuestRoomCharges` (guest self-service, asked by the cashier), `SystemActors` and `StaffContacts` (identity), config `config/guest.php`, public routes `/g/…`, staff routes `/guest/…`, pages under `resources/js/modules/guest`.
- **The code (GST-010, GST-018).** One code for each room and each table in use, made in one step by the owner (`guest.qr.manage`); the code is `/g/{token}`, a 32-character random token (192 bits) that carries no room number, table, property or any identifier. The token is kept as a hash for looking it up and, encrypted, so the code can be printed again (printing is audited). A code can be switched off or renewed; either ends every session on it and a printed code that was renewed opens nothing.
- **The session (GST-018).** Scanning opens a session on that one code, kept in an HttpOnly, same-site cookie (path `/g`) and as a hash in the database; it ends by itself (4 hours for a table, 12 for a room, `config/guest.php`), or when the code is switched off or renewed. Public pages answer at most 120 requests a minute for an address and writes 20 a minute for a session; an unknown, malformed, switched-off or renewed code gives the same answer.
- **The stay proof (GST-011, GST-018).** A room session may browse the menu but orders only after the guest gives the room number and a name of the guest in it, checked against the front office (case, accents and spaces ignored, a whole name, at least two letters). A wrong answer never says which half was wrong, is counted and locks the session after 5 tries for 15 minutes; the room is checked again at the moment of the order (the guest who confirmed it must still be in the house). A table session may prove a stay the same way, with any room of the house, to ask for a charge to the room.
- **The order (GST-012, GST-013).** The menu is that of the outlet of the table, or of the first room service outlet in use for a room, with the price that holds now for the way it is sold (`FR-FBS-015`), and what the kitchen marked sold out shown as such and refused by the server. An order (at most 30 lines, 20 portions of a line, 6 orders an hour for a session, once whatever the number of taps by a key made by the page) goes through the same services as a waiter's order, in one transaction: a table's open bill takes the lines (a new bill is opened when it has none), a room gets a room service order promised in 30 minutes; the order is sent to the kitchen and published as `fnb.order.sent`. The bill and the kitchen ticket carry the source `qr`, shown on the floor and the kitchen screen. The work is recorded under a guest self-service account that nobody can sign in to and that holds only the right to take orders, so no guest action can discount, void or take a payment.
- **Paying (GST-014).** The guest chooses QRIS (at the cashier, who shows the code and confirms it, as for any QRIS payment: no payment provider is chosen yet), a charge to the room, or later. A charge to the room waits in the staff list `/guest/orders` (`guest.order.manage`) for a person to check the guest and verify or refuse it with a note; the cashier cannot take a room payment on that bill until it is verified, and nothing is ever posted to the folio from the guest side.
- **What the guest sees (GST-019 in part).** The orders of this session and how far each dish is (in the queue, being prepared, ready, served; for a room, ordered, on the way, delivered), refreshed by itself while something is being made; never the other orders on a table's bill or an earlier stay.
- Dependency: `qrcode-generator` 2.0.4 (MIT, no dependencies, about 20 kB) draws the QR codes in the browser of the staff who print them; rationale: `FR-GST-010` needs a printable code per room and table, drawing it in the browser keeps the token off any third-party service and needs no server library; it is used by the print page only.
- Not yet: paying by QRIS from the guest's own phone (needs a payment provider), a different room service outlet per room, the order status as push notification, a QR code on the bill, and the guest's language remembered between sessions (the choice lasts the session).
- Evidence: `tests/Feature/GuestExperience/QrMenuHttpTest.php` (the codes and their tokens, a table order reaching the bill and the kitchen as the guest's and placed once, a switched-off and a renewed code, a sold-out dish and every refusal, the room proof and its lock, the charge to the room waiting for verification and the cashier refused until then, a session that ends).

## Required engineering checks

- Identify aggregate owner and state transition before coding.
- Enforce property scope and server-side authorization.
- Define transaction/idempotency/concurrency behavior where mutation is critical.
- Emit audit evidence for sensitive/state-changing operations.
- Add happy, negative, conflict/retry, and permission tests as applicable.
- Update traceability/evidence before marking DONE.
