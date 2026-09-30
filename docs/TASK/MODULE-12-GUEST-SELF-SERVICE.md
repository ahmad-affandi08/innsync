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
| TASK-GST-010 | FR-GST-010 | Wajib | Setiap kamar dan setiap meja restoran memiliki kode QR unik yang membuka menu digital. | TODO |
| TASK-GST-011 | FR-GST-011 | Wajib | Pemesanan dari kamar mewajibkan pengisian nomor kamar dan mencocokkannya dengan nama tamu yang sedang menginap agar terintegrasi dengan kasir dan Front Office. | TODO |
| TASK-GST-012 | FR-GST-012 | Wajib | Pesanan dari menu QR masuk ke POS outlet dan layar dapur seperti pesanan yang diambil pelayan, dengan penanda sumber pesanan. | TODO |
| TASK-GST-013 | FR-GST-013 | Wajib | Menu yang ditandai habis oleh dapur otomatis tidak dapat dipesan melalui menu QR. | TODO |
| TASK-GST-014 | FR-GST-014 | Wajib | Tamu memilih pembayaran langsung melalui QRIS atau pembebanan ke kamar; pembebanan ke kamar memerlukan verifikasi petugas. | TODO |
| TASK-GST-015 | FR-GST-015 | Sebaiknya | Tamu dapat mengirim permintaan layanan dan keluhan dari halaman yang sama, yang langsung masuk ke antrean department terkait. | TODO |
| TASK-GST-016 | FR-GST-016 | Sebaiknya | Tamu dapat melihat rincian tagihan berjalan dan mengisi survei kepuasan menjelang keberangkatan. | TODO |
| TASK-GST-017 | FR-GST-017 | Wajib | Halaman tamu tersedia dalam Bahasa Indonesia dan Bahasa Inggris, ringan dibuka pada jaringan lambat, dan tidak memerlukan pemasangan aplikasi. | TODO |
| TASK-GST-018 | FR-GST-018 | Wajib | QR kamar/meja tidak mengekspos identifier internal yang mudah ditebak. Session tamu berumur terbatas dan aksi sensitif seperti room charge memerlukan verifikasi konteks stay. | TODO |
| TASK-GST-019 | FR-GST-019 | Sebaiknya | Tamu dapat melihat status pesanan/permintaan layanan tanpa memperoleh akses ke data tamu lain atau histori stay sebelumnya. | TODO |

## Required engineering checks

- Identify aggregate owner and state transition before coding.
- Enforce property scope and server-side authorization.
- Define transaction/idempotency/concurrency behavior where mutation is critical.
- Emit audit evidence for sensitive/state-changing operations.
- Add happy, negative, conflict/retry, and permission tests as applicable.
- Update traceability/evidence before marking DONE.
