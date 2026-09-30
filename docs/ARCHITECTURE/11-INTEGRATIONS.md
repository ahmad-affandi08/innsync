# External Integrations

| Integration | Purpose | PRD Phase |
| --- | --- | --- |
| Gerbang pembayaran QRIS | Check-in mandiri, pembayaran outlet, dan pembayaran tagihan kamar | Fase 1 untuk pembayaran Front Office; Fase 2 untuk POS outlet; Fase 3 untuk guest self-service |
| Mesin EDC bank | Pembayaran kartu di meja depan dan kasir outlet | Fase 1 (pencatatan manual referensi), otomatisasi menyusul |
| Pesan instan bisnis | Konfirmasi reservasi, pemberitahuan status laundry, dan pengingat tugas staf | Fase 2 |
| Surel (SMTP) | Pengiriman laporan terjadwal dan konfirmasi tamu | Fase 1 |
| Perangkat lunak akuntansi | Ekspor terstruktur transaksi keuangan | Fase 2 |
| Channel manager / OTA | Sinkronisasi ketersediaan kamar dan reservasi masuk | Fase 3 untuk integrasi terbatas/satu arah; sinkronisasi dua arah penuh setelah rilis 1.0 |
| Printer thermal dan layar dapur | Pencetakan struk dan tiket pesanan | Fase 1 untuk printer Front Office; Fase 2 untuk POS/KDS |
| Pemindai barcode dan pembuat kode QR | Mini bar, guest laundry, kode kamar, dan kode meja | Fase 1 dan 2 |
| Sistem kunci pintu elektronik | Penerbitan kartu kunci setelah check-in mandiri | Setelah rilis 1.0 |
| Pelaporan tamu asing kepada instansi | Ekspor berkas laporan sesuai format yang diminta | Fase 1 (ekspor berkas), otomatisasi menyusul |
| API & Webhook InnSYnc | Integrasi aman dengan sistem eksternal/partner menggunakan authentication, versioning, idempotency, signature bila diperlukan, dan event delivery terpantau. | Disiapkan sejak Fase 1; endpoint bisnis dibuka bertahap |
| Object/File Storage Privat | Penyimpanan foto identitas, bukti transaksi, foto work order, dokumen HR, invoice, dan hasil ekspor dengan kontrol akses dan expiry. | Fase 1 |

## Adapter contract

Every external provider sits behind an application port/interface. Provider-specific DTOs never leak into Domain. Timeouts, signature validation, idempotency, retry/backoff, correlation IDs, dead-letter handling, secret isolation, and manual reconciliation are mandatory according to the PRD NFRs.

Payment providers use an internal state machine and settlement/reconciliation. Provider callbacks are evidence, not permission to bypass internal invariants.
