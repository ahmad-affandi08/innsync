# Front Office — Task Contract

**Bounded context:** Front Office
**Critical note:** Availability, reservation, stay, folio, payment and night audit are high-risk core flows.

## Candidate aggregates / read models

- `Reservation`
- `Stay`
- `Folio`
- `Payment/PaymentAttempt`
- `NightAudit`
- `GuestRequest`

## Requirement backlog

| Task ID | FR | Priority | Requirement | Status |
| --- | --- | --- | --- | --- |
| TASK-FO-001 | FR-FO-001 | Wajib | Menampilkan rak kamar interaktif yang tersambung dengan data okupansi dan status kamar; klik pada nomor kamar membuka data tamu atau formulir check-in. | REVIEW |
| TASK-FO-002 | FR-FO-002 | Wajib | Menyediakan kalender ketersediaan per tipe kamar dengan horizon minimal 365 hari dan dapat dikonfigurasi, lengkap dengan jumlah kamar tersisa, allotment/hold, dan penanda pembatasan penjualan per tanggal. | REVIEW |
| TASK-FO-003 | FR-FO-003 | Wajib | Membuat reservasi dengan sumber pemesanan (langsung, telepon, OTA, korporat, walk-in), status (tentatif, terkonfirmasi, dijamin deposit), dan catatan khusus. | REVIEW |
| TASK-FO-004 | FR-FO-004 | Wajib | Menandai reservasi yang tidak datang (no-show) dan pembatalan dengan alasan, serta menerapkan aturan denda bila dikonfigurasi. | REVIEW |
| TASK-FO-005 | FR-FO-005 | Wajib | Menandai kamar sebagai Out of Order atau Out of Service dengan rentang tanggal sehingga tidak muncul sebagai kamar yang dapat dijual. | REVIEW |
| TASK-FO-006 | FR-FO-006 | Sebaiknya | Mendukung pemesanan grup sederhana: satu pemesan dengan beberapa kamar, satu master folio, dan opsi pemisahan tagihan per kamar. | TODO |
| TASK-FO-007 | FR-FO-007 | Wajib | Mengelola inventory kamar per tipe dengan aturan overbooking yang dapat dikonfigurasi. Sistem tidak boleh menjual melebihi batas yang disetujui dan wajib memperingatkan pengguna sebelum menerima reservasi yang berpotensi oversold. | REVIEW |
| TASK-FO-008 | FR-FO-008 | Wajib | Mengelola rate plan, seasonal rate, corporate rate, package, inclusions, minimum stay, closed-to-arrival/departure, serta tanggal efektif tanpa mengubah histori reservasi lama. | REVIEW |
| TASK-FO-009 | FR-FO-009 | Wajib | Mendukung kebijakan guarantee, deposit due date, cancellation, no-show, dan penalty per rate plan/sumber reservasi serta menyimpan policy snapshot pada saat reservasi dibuat. | REVIEW |
| TASK-FO-010 | FR-FO-010 | Wajib | Formulir check-in memuat: nama tamu, kewarganegaraan, jenis dan nomor identitas (paspor atau KTP), tanggal berlaku identitas, nomor visa bila diperlukan, jumlah tamu (dewasa dan anak), serta alamat sesuai identitas. | REVIEW |
| TASK-FO-011 | FR-FO-011 | Wajib | Sistem mengunggah dan menampilkan foto identitas yang diambil langsung dari kamera perangkat resepsionis atau tablet, dan melampirkannya pada data tamu. | REVIEW |
| TASK-FO-012 | FR-FO-012 | Wajib | Pemilihan lama menginap menampilkan blok tanggal menginap secara visual serta menghitung otomatis harga per malam sesuai tarif kamar yang bersangkutan. | REVIEW |
| TASK-FO-013 | FR-FO-013 | Wajib | Harga kamar dapat diubah kapan pun oleh pengguna berwenang; setiap perubahan mencatat nilai lama, nilai baru, alasan, dan pelaku. Perubahan melebihi ambang diskon yang ditetapkan memerlukan persetujuan Manager on Duty. | REVIEW |
| TASK-FO-014 | FR-FO-014 | Sebaiknya | Sistem memperingatkan bila identitas tamu telah kedaluwarsa atau akan kedaluwarsa selama masa menginap. | REVIEW |
| TASK-FO-015 | FR-FO-015 | Sebaiknya | Sistem mendeteksi tamu berulang berdasarkan nomor identitas dan mengisi otomatis data profil beserta riwayat menginap dan preferensinya. | IN_PROGRESS |
| TASK-FO-016 | FR-FO-016 | Wajib | Setelah check-in, status kamar otomatis berubah menjadi terisi dan seluruh permintaan tamu yang tercatat muncul pada kartu kamar tersebut. | REVIEW |
| TASK-FO-017 | FR-FO-017 | Sebaiknya | Sistem mencetak atau mengirim kartu registrasi elektronik untuk ditandatangani tamu, termasuk tanda tangan digital pada tablet. | REVIEW |
| TASK-FO-018 | FR-FO-018 | Wajib | Mendukung perpindahan kamar (room move) dengan pemindahan seluruh saldo folio dan pencatatan alasan. | REVIEW |
| TASK-FO-019 | FR-FO-019 | Wajib | Mendukung perpanjangan masa menginap (Stay Over) dan check-out dipercepat dengan penyesuaian tagihan otomatis. | REVIEW |
| TASK-FO-020 | FR-FO-020 | Wajib | Setiap stay memiliki minimal satu folio dan dapat memiliki beberapa folio/window untuk routing tagihan. Folio menampung room charge, pajak, service charge, charge outlet, koreksi, dan pembayaran secara terurut dan dapat ditelusuri. | IN_PROGRESS |
| TASK-FO-021 | FR-FO-021 | Wajib | Mencetak rincian tagihan (bill print out) yang menampilkan seluruh transaksi terperinci per outlet dan per tanggal. | REVIEW |
| TASK-FO-022 | FR-FO-022 | Sebaiknya | Mendukung pemisahan tagihan (split bill) menjadi beberapa folio, misalnya folio perusahaan dan folio pribadi tamu. | REVIEW |
| TASK-FO-023 | FR-FO-023 | Sebaiknya | Mendukung pemindahan item tagihan antar folio atau antar kamar dengan pencatatan alasan. | REVIEW |
| TASK-FO-024 | FR-FO-024 | Wajib | Menerima pembayaran melalui tunai, QRIS, kartu melalui EDC, transfer bank, dan pembayaran daring dari kanal pemesanan. | REVIEW |
| TASK-FO-025 | FR-FO-025 | Wajib | Mencatat deposit di muka dan mengurangkannya secara otomatis pada saat penyelesaian tagihan, termasuk pengembalian sisa deposit. | REVIEW |
| TASK-FO-026 | FR-FO-026 | Bisa | Mencatat pembayaran dengan mata uang asing beserta kurs yang berlaku bila fitur diaktifkan. | TODO |
| TASK-FO-027 | FR-FO-027 | Wajib | Membukukan pendapatan kamar secara otomatis ke modul Finance beserta pemisahan nilai dasar, pajak, dan service charge. | TODO |
| TASK-FO-028 | FR-FO-028 | Wajib | Menjalankan night audit berdasarkan business date properti: melakukan pre-check transaksi tertunda, membukukan room charge, mengunci hari yang selesai, memindahkan business date, dan menghasilkan laporan. Proses harus aman dijalankan ulang tanpa posting ganda. | REVIEW |
| TASK-FO-029 | FR-FO-029 | Wajib | Pembayaran, refund, reversal, dan koreksi folio memiliki status dan referensi yang jelas. Refund atau reversal setelah settlement memerlukan otorisasi, alasan, jejak audit, dan tidak boleh menghapus transaksi asal. | REVIEW |
| TASK-FO-030 | FR-FO-030 | Wajib | Mencatat permintaan tamu (guest request) dengan template bebas isi dan meneruskannya otomatis ke Housekeeping, Restoran, atau Maintenance sesuai kategori, lengkap dengan status penyelesaian. | REVIEW |
| TASK-FO-031 | FR-FO-031 | Wajib | Mencatat komentar dan keluhan tamu beserta tingkat keparahan, penanggung jawab tindak lanjut, dan bukti penyelesaian. | REVIEW |
| TASK-FO-032 | FR-FO-032 | Wajib | Menampilkan SOP tugas harian, mingguan, dan bulanan resepsionis pada ponsel atau tablet, dengan isi template yang disusun oleh manajemen. | REVIEW |
| TASK-FO-033 | FR-FO-033 | Wajib | Staf menandai tugas selesai; persentase penyelesaian dikirim otomatis ke modul Human Resource sebagai komponen penilaian kinerja. | REVIEW |
| TASK-FO-034 | FR-FO-034 | Sebaiknya | Menyediakan buku serah terima shift (log book) yang wajib diisi pada akhir shift dan dibaca pada awal shift berikutnya. | REVIEW |
| TASK-FO-035 | FR-FO-035 | Sebaiknya | Mengelola profil perusahaan/agen, credit limit, billing instruction, dan routing charge untuk tamu korporat tanpa mencampur tagihan pribadi. | TODO |
| TASK-FO-036 | FR-FO-036 | Wajib | Membuka dan menutup shift kasir Front Office dengan opening float, penerimaan per metode, cash drop, saldo sistem, kas fisik, dan selisih beralasan. | REVIEW |
| TASK-FO-037 | FR-FO-037 | Sebaiknya | Mengelola early check-in, late check-out, day-use, dan biaya terkait berdasarkan kebijakan/rate plan yang dapat dikonfigurasi. | REVIEW |
| TASK-FO-038 | FR-FO-038 | Wajib | Late charge setelah folio ditutup harus menggunakan alur khusus yang menaut ke stay/folio asal dan tidak mengubah laporan hari lama tanpa adjustment. | REVIEW |
| TASK-FO-039 | FR-FO-039 | Wajib | Koreksi nama tamu, identitas, room move, dan routing finansial setelah check-in disimpan sebagai perubahan ter-audit; perubahan data kritis dapat memerlukan approval. | REVIEW |
| TASK-FO-040 | FR-FO-040 | Wajib | Menerbitkan laporan registrasi tamu harian sesuai kolom di atas dengan penyaring tanggal dan kewarganegaraan. | REVIEW |
| TASK-FO-041 | FR-FO-041 | Wajib | Menerbitkan berkas laporan tamu warga negara asing dalam format yang siap disampaikan kepada instansi terkait. | IN_PROGRESS |
| TASK-FO-042 | FR-FO-042 | Wajib | Menerbitkan laporan pendapatan kamar per metode pembayaran: tunai, QRIS, transfer bank, kartu, dan pembayaran kanal daring. | REVIEW |
| TASK-FO-043 | FR-FO-043 | Wajib | Menerbitkan laporan kedatangan, keberangkatan, dan tamu menginap untuk keperluan operasional harian. | REVIEW |
| TASK-FO-044 | FR-FO-044 | Wajib | Menerbitkan laporan okupansi, ADR, dan RevPAR per hari, bulan, dan tahun berjalan. | REVIEW |

## Required engineering checks

- Identify aggregate owner and state transition before coding.
- Enforce property scope and server-side authorization.
- Define transaction/idempotency/concurrency behavior where mutation is critical.
- Emit audit evidence for sensitive/state-changing operations.
- Add happy, negative, conflict/retry, and permission tests as applicable.
- Update traceability/evidence before marking DONE.
