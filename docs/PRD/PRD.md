# InnSYnc — Hotel Operating System

> Canonical textual extraction of `PRD-InnSYnc-Hotel-Operating-System-v1.1.0-Revisi.docx`. The DOCX remains the signed/visual source; this Markdown exists so AI agents can index and reason over the requirements reliably.

H O T E L O P E R A T I N G S Y S T E M

PRODUCT REQUIREMENTS DOCUMENT (PRD)

| Atribut Dokumen | Keterangan |
| --- | --- |
| Nama Produk | InnSYnc — Hotel Operating System |
| Nomor Dokumen | PRD-INNSYNC-2026-001 |
| Versi | 1.1.0 |
| Status | Draft Revisi — siap review & sign-off |
| Tanggal Terbit | 30 September 2026 |
| Penyusun | Tim Produk InnSYnc |
| Pemilik Produk | (diisi saat sign-off) |
| Klasifikasi | Internal — Rahasia |
| Jumlah Modul | 12 modul fungsional |
| Target Rilis Penuh | 12 bulan (3 fase) |

# Riwayat Revisi

| Versi | Tanggal | Penulis | Ringkasan Perubahan |
| --- | --- | --- | --- |
| 0.1 | — | Pemilik Produk | Dokumen konsep awal InnSYnc (catatan kebutuhan per department) |
| 1.0.0 | 12 Agu 2026 | Tim Produk | PRD lengkap: struktur modul, kebutuhan fungsional ber-ID, NFR, model data, roadmap. Modul Finance & Human Resource dirancang mandiri (tidak lagi merujuk sistem eksternal). |
| 1.1.0 | 30 Sep 2026 | Tim Produk InnSYnc | Revisi menyeluruh: konsistensi requirement, status kamar multidimensi, business date & night audit, kontrol pembayaran/offline, aturan lintas modul, state machine, keamanan & observability, migrasi data, UAT/DoD, governance, risiko, dan koreksi rekap kebutuhan. |

# Ringkasan Revisi v1.1.0

- Memperjelas batas antara kebutuhan produk dan keputusan implementasi teknis serta menambahkan aturan lintas modul yang wajib konsisten.

- Memisahkan dimensi status kamar, memperkuat reservation/folio/payment/night audit, dan menambahkan kontrol konkurensi serta idempotensi.

- Memperkuat keamanan, privasi, audit, backup/restore, observability, offline synchronization, integrasi eksternal, dan ketahanan operasional 24/7.

- Menambahkan strategi migrasi data, cutover, pengujian, UAT, Definition of Done, governance, change control, serta release gate.

- Mengoreksi inkonsistensi jumlah kebutuhan fungsional pada lampiran dan menyelaraskan fase integrasi dengan roadmap modul.

# Lembar Persetujuan

Dokumen ini dianggap final dan menjadi acuan pengembangan setelah ditandatangani oleh seluruh pihak di bawah ini. Perubahan setelah sign-off wajib melalui proses change request dan menaikkan nomor versi.

| Peran | Nama | Tanggal | Tanda Tangan |
| --- | --- | --- | --- |
| Pemilik / Owner Hotel |  |  |  |
| General Manager |  |  |  |
| Product Owner |  |  |  |
| Tech Lead |  |  |  |
| Finance Manager |  |  |  |
| HR Manager |  |  |  |

# 1. Ringkasan Eksekutif

InnSYnc adalah Hotel Operating System (HOS) terintegrasi yang menyatukan seluruh aktivitas operasional properti perhotelan ke dalam satu platform: mulai dari reservasi dan check-in tamu, pengelolaan status kamar, laundry, penjualan restoran dan bar, dapur, pemeliharaan aset, pembelian dan persediaan, hingga sumber daya manusia dan keuangan.

Saat ini sebagian besar properti skala kecil-menengah menjalankan operasional dengan alat yang terpisah-pisah: buku registrasi manual, papan tulis status kamar, mesin kasir yang berdiri sendiri, grup WhatsApp untuk laporan kerusakan, dan spreadsheet untuk stok serta absensi. Akibatnya data tidak pernah sinkron, pendapatan bocor tanpa terdeteksi, dan manajemen baru mengetahui masalah setelah tutup buku bulanan.

InnSYnc menyelesaikan masalah tersebut dengan prinsip satu data, banyak sudut pandang. Setiap kejadian operasional dicatat sekali di titik terjadinya oleh petugas yang bertanggung jawab, lalu secara otomatis mengalir ke modul lain yang membutuhkannya — check-out menurunkan status kamar menjadi kotor, konsumsi mini bar langsung masuk ke tagihan kamar, pemakaian bahan dapur mengurangi stok gudang, dan seluruhnya bermuara pada dashboard manajemen serta laporan keuangan harian.

Sasaran utama rilis 1.0: menghapus rekapitulasi manual di akhir hari, menutup kebocoran pendapatan pada mini bar dan guest laundry, memangkas waktu check-in, menyediakan dashboard real-time, dan memastikan setiap transaksi operasional dapat ditelusuri dari sumber hingga laporan tanpa input ulang.

## 1.1 Ruang Lingkup Produk dalam Satu Halaman

| Modul | Pengguna Utama | Nilai Utama |
| --- | --- | --- |
| Dashboard Manajemen | Owner, GM, MOD | Kondisi properti real-time dalam satu layar |
| Front Office | Receptionist, MOD | Check-in cepat, folio akurat, kepatuhan data tamu |
| Housekeeping | HK Supervisor, Room Attendant | Status kamar akurat & perputaran kamar lebih cepat |
| Laundry | Laundry Attendant | Guest laundry tertagih, sirkulasi linen terkontrol |
| F&B Service (POS) | Waiter, Bartender, Kasir | Penjualan outlet & charge to room tanpa selisih |
| F&B Product (Kitchen) | Chef, Cook | Tiket order digital, food cost & stok bahan terkendali |
| Maintenance | Engineering | Work order terlacak, kamar OOO terkontrol |
| Inventory & Purchasing | Purchasing, Store | PR-PO-penerimaan rapi, stok minimum terpantau |
| Human Resource | HR, Kepala Department | Jadwal, absensi, kinerja SOP, service charge |
| Finance | Finance, Owner | Pendapatan, biaya, hutang-piutang, pajak |
| Reporting & Analytics | Semua manajerial | Laporan standar & terjadwal per department |
| Guest Self-Service (QRIS) | Tamu | Check-in mandiri, menu QR di kamar & restoran |

# 2. Latar Belakang dan Rumusan Masalah

## 2.1 Kondisi Saat Ini

Analisis terhadap alur kerja properti target menemukan bahwa hampir seluruh titik serah-terima informasi antar department masih dilakukan secara lisan atau di atas kertas. Titik serah-terima inilah yang menjadi sumber kesalahan dan kebocoran.

| Masalah | Dampak Operasional | Penyelesaian oleh InnSYnc |
| --- | --- | --- |
| Status kamar antara Front Office dan Housekeeping tidak sinkron | Kamar dijual saat belum siap, tamu menunggu, keluhan meningkat | Papan status kamar tunggal yang diperbarui langsung dari ponsel room attendant |
| Pendapatan tiap outlet dicatat di mesin kasir terpisah | Rekap manual tiap malam, selisih kas, laporan pemilik terlambat | Semua outlet memakai POS yang sama dan bermuara ke satu folio serta satu laporan pendapatan |
| Konsumsi mini bar dicek belakangan atau terlupakan | Kebocoran pendapatan, tamu terlanjur check-out | Pengecekan mini bar berbasis scan barcode per kamar, otomatis membentuk charge ke folio |
| Guest laundry dicatat di buku tulis | Item tertukar, hilang, atau tidak tertagih | Input item berbasis barcode oleh Housekeeping, status pengerjaan diikuti sampai kembali ke kamar |
| Laporan kerusakan disampaikan lisan atau lewat pesan instan | Tidak ada bukti, tidak ada prioritas, pekerjaan terlupakan | Work order digital lintas department dengan status dan foto wajib saat selesai |
| Stok gudang hanya diketahui saat stock opname bulanan | Kehabisan bahan mendadak, pembelian darurat berbiaya tinggi | Kartu stok bergerak otomatis dari pemakaian dan peringatan stok minimum di dashboard |
| Jadwal, absensi, dan pembagian service charge diolah manual | Perselisihan pembagian, keterlambatan penggajian | Roster dan absensi digital yang menjadi dasar perhitungan service charge dan penggajian |
| Pajak daerah dan pelaporan tamu asing dihitung manual | Risiko denda dan keterlambatan pelaporan | Perhitungan pajak per outlet otomatis dan berkas laporan tamu siap ekspor |

## 2.2 Peluang

Properti target umumnya sudah memiliki perangkat yang dibutuhkan — ponsel milik staf, tablet murah, dan koneksi internet — tetapi belum memiliki perangkat lunak yang mengikat semuanya. InnSYnc dirancang berbasis web dan dapat diakses dari peramban pada perangkat apa pun, sehingga biaya adopsi rendah dan pelatihan dapat dilakukan cepat.

# 3. Tujuan Produk dan Metrik Keberhasilan

## 3.1 Tujuan Produk

| Kode | Tujuan | Indikator Keberhasilan (KPI) |
| --- | --- | --- |
| G-01 | Menjadi satu-satunya sumber data operasional properti | 100% transaksi outlet dan pergerakan status kamar tercatat di sistem; nol rekap manual di akhir hari |
| G-02 | Menutup kebocoran pendapatan | Selisih audit mini bar di bawah 1% nilai; 100% order guest laundry tertagih pada folio |
| G-03 | Mempercepat layanan tamu di titik kedatangan | Waktu check-in di meja depan maksimal 3 menit; check-in mandiri maksimal 90 detik |
| G-04 | Mempercepat perputaran kamar | Rata-rata waktu dari status VD menjadi VR turun 20% dibanding sebelum implementasi |
| G-05 | Mempercepat tutup buku harian | Proses night audit selesai maksimal 10 menit dan laporan pendapatan harian terbit sebelum pukul 07.00 |
| G-06 | Menjamin kepatuhan pajak dan pelaporan | 100% laporan pajak daerah dan laporan tamu asing terbit tepat waktu tanpa perhitungan manual |
| G-07 | Memastikan adopsi oleh seluruh staf | Minimal 90% staf operasional aktif harian di modulnya dalam 60 hari sejak go-live |
| G-08 | Meningkatkan kualitas keputusan manajemen | Pemilik dapat melihat okupansi, pendapatan, dan biaya hari berjalan kapan saja tanpa meminta laporan |

## 3.2 Metrik Produk yang Dipantau Berkelanjutan

- Metrik bisnis: Occupancy, ADR (Average Daily Rate), RevPAR, TRevPAR, kontribusi pendapatan per outlet, rasio biaya bahan terhadap penjualan (food & beverage cost).

- Metrik operasional: rata-rata durasi pembersihan kamar, jumlah work order terbuka melebihi SLA, akurasi stok saat opname, persentase penyelesaian SOP harian per department.

- Metrik produk: jumlah pengguna aktif harian, jumlah transaksi POS per hari, tingkat kegagalan sinkronisasi, waktu respons rata-rata halaman.

- Metrik tamu: skor kepuasan pasca menginap, jumlah komplain per 100 kamar terjual, persentase tamu yang memakai check-in mandiri.

# 4. Ruang Lingkup

## 4.1 Termasuk dalam Rilis 1.0

Seluruh dua belas modul yang disebut pada bagian 1.1, dengan asumsi satu properti tunggal yang memiliki kamar, restoran, bar, spa, dan gift shop, serta gudang pusat dan gudang department.

## 4.2 Tidak Termasuk (Out of Scope) pada Rilis 1.0

| Item | Alasan | Rencana |
| --- | --- | --- |
| Channel manager dua arah ke OTA | Kompleksitas integrasi dan biaya lisensi pihak ketiga | Rilis 1.0 hanya mendukung impor reservasi/availability terbatas atau integrasi satu arah. Sinkronisasi dua arah penuh diposisikan setelah rilis 1.0. |
| Penjadwalan terapis spa secara penuh | Kebutuhan spesifik, volume transaksi relatif kecil | Spa dilayani sebagai outlet POS pada rilis 1.0 |
| Program loyalitas dan membership | Bukan penghambat operasional harian | Dipertimbangkan setelah data tamu terkumpul |
| Integrasi kunci pintu elektronik (door lock) | Bergantung merek perangkat keras di properti | Disiapkan titik integrasi API, implementasi menyusul |
| Buku besar akuntansi penuh dan e-Faktur | Wilayah akuntansi formal, umumnya sudah ada perangkat lunak khusus | InnSYnc menyediakan sub-ledger operasional, rekonsiliasi, management P&L, dan ekspor terstruktur; laporan akuntansi statutori tetap di sistem akuntansi resmi. |
| Manajemen banquet dan event | Belum menjadi lini bisnis utama | Dievaluasi setelah rilis 1.0 |
| Operasi multi-properti dalam satu akun | Menambah kompleksitas otorisasi dan konsolidasi | Arsitektur data disiapkan multi-properti sejak awal |

## 4.3 Batasan Produk

- Baseline kapasitas rilis 1.0 adalah properti hingga 150 kamar dan minimal 50 pengguna aktif bersamaan; performance test wajib menyediakan headroom dan tidak boleh mengandalkan limit aplikasi sebagai satu-satunya kontrol kapasitas.

- Antarmuka staf lapangan dioptimalkan untuk layar ponsel; antarmuka back office dioptimalkan untuk layar lebar.

- Sistem tidak menyimpan data kartu kredit; seluruh transaksi kartu diselesaikan melalui EDC atau gerbang pembayaran pihak ketiga.

## 4.4 Definisi Prioritas Kebutuhan

| Prioritas | Makna | Aturan Rilis |
| --- | --- | --- |
| Wajib | Kebutuhan yang menjadi syarat operasi, kontrol, kepatuhan, atau integritas data. | Tidak boleh go-live bila belum lulus UAT, kecuali ada waiver tertulis dari Product Owner dan pemilik risiko. |
| Sebaiknya | Memberi nilai operasional signifikan tetapi memiliki prosedur manual sementara yang dapat diterima. | Ditargetkan pada fase yang sama; boleh ditunda melalui change control tanpa mengubah integritas data inti. |
| Bisa | Peningkatan kenyamanan, analitik, atau otomasi lanjutan. | Masuk backlog terprioritas dan tidak menjadi blocker go-live. |

# 5. Pengguna, Peran, dan Hak Akses

## 5.1 Profil Pengguna

| Peran | Kebutuhan Utama | Perangkat |
| --- | --- | --- |
| Owner / General Manager | Melihat kondisi properti dan keuangan tanpa harus bertanya kepada staf | Ponsel dan laptop |
| Manager on Duty | Menyetujui diskon, kompliment, void, dan menangani eskalasi tamu | Ponsel dan desktop |
| Receptionist | Check-in dan check-out cepat, folio dan pembayaran akurat | Desktop meja depan, tablet, printer thermal |
| Housekeeping Supervisor | Membagi tugas kamar dan memastikan hasil kerja terverifikasi | Tablet dan desktop |
| Room Attendant | Menerima daftar kamar dan memperbarui status sambil bekerja | Ponsel |
| Laundry Attendant | Menerima order guest laundry dan mencatat sirkulasi linen | Ponsel dan pemindai barcode |
| Waiter / Bartender | Mengambil pesanan, membuka bill, mengirim order ke dapur | Tablet POS |
| Kitchen / Chef | Menerima tiket order dan mengelola ketersediaan menu serta bahan | Layar dapur (KDS) atau tablet |
| Engineering / Maintenance | Menerima work order, mengerjakan, dan melampirkan bukti foto | Ponsel |
| Purchasing / Store Keeper | Mengolah permintaan pembelian sampai penerimaan barang | Desktop |
| Human Resource | Menyusun jadwal, memantau absensi, mengolah dasar penggajian | Desktop |
| Finance / Accounting | Memverifikasi pendapatan, biaya, hutang, piutang, dan pajak | Desktop |
| Tamu | Check-in mandiri, memesan dari kamar, melihat tagihan | Ponsel pribadi tanpa memasang aplikasi |

## 5.2 Matriks Hak Akses Ringkas

Keterangan: P = penuh (buat, ubah, hapus), U = ubah terbatas pada tanggung jawabnya, L = hanya melihat, A = butuh persetujuan atasan, kosong = tanpa akses.

| Modul | Owner/GM | MOD | Resepsionis | HK | F&B | Teknik | Purch. | HR | Finance |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Dashboard | P | L | L | L | L | L | L | L | L |
| Front Office | L | P | U |  |  |  |  |  | L |
| Housekeeping | L | U | L | P |  |  |  |  |  |
| Laundry | L | U | L | U |  |  |  |  |  |
| POS F&B | L | P | L |  | U |  |  |  | L |
| Kitchen | L | U |  |  | U |  |  |  |  |
| Maintenance | L | U | A | A | A | P |  |  |  |
| Inventory & Purchasing | L | A |  | A | A | A | P |  | L |
| Human Resource | L | L |  |  |  |  |  | P | L |
| Finance | L |  |  |  |  |  | L | L | P |
| Pengaturan Sistem | P |  |  |  |  |  |  |  |  |

Peran bersifat dapat dikonfigurasi. Administrator dapat membuat peran baru dan menyusun kombinasi izin pada tingkat fitur, bukan sekadar tingkat modul.

# 6. Arsitektur Solusi dan Peta Integrasi Antar Modul

## 6.1 Gambaran Arsitektur

- Aplikasi web tunggal berbasis peramban dengan tiga profil antarmuka: back office (layar lebar), aplikasi staf (ponsel, terpasang sebagai PWA), dan antarmuka tamu (halaman web publik yang dibuka dari pemindaian QR).

- Basis data relasional menjadi sumber kebenaran tunggal. Seluruh entitas bisnis memiliki cakupan properti/tenant yang eksplisit. Strategi isolasi fisik data (shared schema dengan policy/RLS atau isolasi schema) ditetapkan pada keputusan arsitektur, namun pengujian isolasi lintas properti wajib tersedia sejak awal.

- Komunikasi antar modul menggunakan mekanisme kejadian (event) internal: satu aksi memicu pembaruan otomatis pada modul lain tanpa input ulang.

- Ketahanan luring (offline) untuk POS dan pelacak Housekeeping menggunakan antrean lokal terenkripsi, idempotency key, status sinkronisasi yang terlihat pengguna, retry terkontrol, serta aturan konflik yang mencegah duplikasi transaksi atau posting finansial.

- Seluruh perubahan data penting tercatat dalam jejak audit yang tidak dapat diubah, memuat siapa, kapan, nilai sebelum, dan nilai sesudah.

## 6.2 Peta Aliran Data Antar Modul

| Sumber | Tujuan | Data yang Mengalir |
| --- | --- | --- |
| Front Office (check-in) | Housekeeping, Dashboard | Status kamar menjadi terisi, jumlah tamu, tanggal keberangkatan |
| Front Office (check-out) | Housekeeping, Finance | Status kamar menjadi kotor, penutupan folio, realisasi pendapatan kamar |
| Housekeeping | Front Office, Dashboard | Perubahan status kamar menjadi siap dijual, temuan kerusakan |
| Housekeeping (guest laundry) | Laundry, Front Office | Daftar item laundry per kamar dan nilai tagihan |
| POS F&B dan mini bar | Front Office (folio), Finance | Penjualan, charge to room, pajak, dan service charge |
| POS dan Kitchen | Inventory | Pengurangan stok bahan berdasarkan resep dan pemakaian |
| Semua department | Maintenance | Laporan kerusakan menjadi work order dengan prioritas |
| Maintenance | Front Office, Dashboard | Penetapan kamar Out of Order dan tanggal perkiraan selesai |
| Semua department | Purchasing | Permintaan pembelian barang dan bahan |
| Purchasing | Inventory, Finance | Penerimaan barang, nilai persediaan, hutang kepada pemasok |
| SOP harian tiap department | Human Resource | Persentase penyelesaian tugas sebagai komponen penilaian kinerja |
| Human Resource | Finance | Kehadiran, lembur, dan dasar perhitungan gaji serta service charge |
| Seluruh modul | Dashboard & Reporting | Agregasi untuk indikator manajemen dan laporan terjadwal |

## 6.3 Prinsip Perancangan

- Catat sekali di sumbernya. Data tidak boleh diketik ulang di modul lain.

- Setiap angka dapat ditelusuri. Setiap nilai pada dashboard dapat diklik hingga ke transaksi asalnya.

- Tugas staf lapangan selesai dalam maksimal tiga ketukan pada layar ponsel.

- Tidak ada penghapusan permanen pada data transaksi; pembatalan dilakukan melalui void atau koreksi yang tercatat.

# 7. Modul 1 — Dashboard Manajemen

Tujuan modul: menyajikan kondisi properti hari berjalan dalam satu layar sehingga pemilik dan manajemen dapat mengambil keputusan tanpa meminta laporan kepada staf.

Pengguna utama: Owner, General Manager, Manager on Duty, dan kepala department (dengan cakupan data terbatas pada departmentnya).

## 7.1 Kebutuhan Fungsional

| ID | Kebutuhan Fungsional | Prioritas |
| --- | --- | --- |
| FR-DSH-001 | Menampilkan kartu okupansi hari berjalan: jumlah kamar terisi, jumlah kamar tersedia, jumlah tamu menginap, kedatangan hari ini, keberangkatan hari ini, dan reservasi masuk. Angka bersumber dari Front Office dan Housekeeping. | Wajib |
| FR-DSH-002 | Menampilkan room board dengan dimensi status yang terpisah: occupancy (vacant/occupied), housekeeping (dirty/clean/inspected), sellability (sellable/OOO/OOS), serta service flag seperti DND/Double Lock. Complimentary ditampilkan sebagai atribut tarif/folio, bukan status kebersihan kamar. | Wajib |
| FR-DSH-003 | Papan kamar bersifat template: administrator dapat menambah, mengubah, menonaktifkan kamar, menetapkan tipe, lantai, gedung, dan kapasitas tanpa bantuan pengembang. | Wajib |
| FR-DSH-004 | Menampilkan pendapatan hari berjalan per outlet (Kamar, Restoran, Bar, Spa, Gift Shop, dan outlet tambahan yang dibuat pengguna) beserta total dan perbandingan terhadap hari, minggu, serta bulan sebelumnya. | Wajib |
| FR-DSH-005 | Daftar outlet bersifat dapat diperluas; penambahan outlet baru otomatis muncul sebagai kolom pendapatan dan kategori pada laporan. | Wajib |
| FR-DSH-006 | Menampilkan ringkasan pengeluaran: pembayaran kepada pemasok dan vendor yang telah dibayar, hutang berjalan, serta daftar jatuh tempo dalam 7 dan 30 hari ke depan. | Wajib |
| FR-DSH-007 | Menampilkan peringatan stok minimum per department (Bar, Kitchen, Housekeeping, Maintenance, Galley, Reception) berdasarkan kartu stok dan hasil stock opname. | Wajib |
| FR-DSH-008 | Menampilkan ringkasan kepegawaian hari berjalan: jumlah staf bertugas per shift per department, staf libur, staf ijin dengan keterangan, dan staf tanpa keterangan (alpha). | Wajib |
| FR-DSH-009 | Menampilkan ringkasan pekerjaan pemeliharaan: work order berjalan, selesai hari ini, melewati batas waktu, dan kamar berstatus Out of Order. | Wajib |
| FR-DSH-010 | Menampilkan performa produk: sepuluh menu terlaris dan paling tidak laku, serta performa tipe kamar berdasarkan okupansi dan ADR pada periode terpilih. | Sebaiknya |
| FR-DSH-011 | Menampilkan distribusi jam transaksi per outlet dalam bentuk grafik batang per jam untuk membantu penjadwalan staf. | Sebaiknya |
| FR-DSH-012 | Menampilkan heatmap kedatangan tamu (check-in) berdasarkan jam dan hari dalam seminggu. | Sebaiknya |
| FR-DSH-013 | Menampilkan lini masa kewajiban pajak: pajak kamar, pajak restoran dan outlet lain, nilai terkumpul berjalan, tanggal jatuh tempo pelaporan, dan status pelaporan. | Wajib |
| FR-DSH-014 | Menampilkan akumulasi service charge yang terkumpul dari kamar dan outlet beserta estimasi porsi yang akan didistribusikan kepada karyawan. | Wajib |
| FR-DSH-015 | Menyediakan penyaring periode (hari ini, kemarin, 7 hari, bulan berjalan, rentang khusus) yang berlaku serentak pada seluruh kartu. | Wajib |
| FR-DSH-016 | Setiap kartu dapat diklik untuk menelusuri hingga daftar transaksi atau dokumen sumbernya. | Wajib |
| FR-DSH-017 | Susunan kartu dapat diatur per pengguna (urutan dan tampil/sembunyi) dan tersimpan pada profil pengguna. | Bisa |
| FR-DSH-018 | Data diperbarui otomatis paling lambat setiap 60 detik tanpa memuat ulang halaman, dengan penanda waktu pembaruan terakhir. | Sebaiknya |
| FR-DSH-019 | Tersedia mode layar televisi (tampilan besar tanpa navigasi) untuk dipasang di ruang manajemen. | Bisa |
| FR-DSH-020 | Menyediakan pusat exception/alert untuk kondisi yang membutuhkan tindakan: reservasi berpotensi oversold, folio belum settle, pembayaran berstatus unknown, stok negatif atau kritis, work order lewat SLA, dan kegagalan sinkronisasi. | Wajib |
| FR-DSH-021 | Setiap KPI menampilkan definisi, business date/periode, waktu data terakhir diperbarui, serta drill-down ke data sumber agar tidak terjadi perbedaan interpretasi antar department. | Wajib |
| FR-DSH-022 | Dashboard menerapkan cakupan data berdasarkan property, outlet, department, dan role; pengguna hanya melihat angka yang diizinkan tanpa mengubah sumber data. | Wajib |

## 7.2 Kriteria Penerimaan

- Angka okupansi pada dashboard selalu identik dengan hasil hitung Front Office pada waktu yang sama.

- Perubahan status kamar oleh room attendant terlihat pada dashboard dalam waktu kurang dari 60 detik.

- Total pendapatan dashboard sama persis dengan total laporan pendapatan harian setelah night audit.

- Seluruh kartu selesai dimuat dalam waktu kurang dari 3 detik pada koneksi 4G.

# 8. Modul 2 — Front Office / Reception

Tujuan modul: menjalankan seluruh siklus tamu mulai reservasi, kedatangan, penagihan, hingga keberangkatan, sekaligus menjadi pusat folio tempat seluruh transaksi outlet bermuara.

Pengguna utama: Receptionist, Manager on Duty, Night Auditor.

## 8.1 Rak Kamar dan Reservasi

| ID | Kebutuhan Fungsional | Prioritas |
| --- | --- | --- |
| FR-FO-001 | Menampilkan rak kamar interaktif yang tersambung dengan data okupansi dan status kamar; klik pada nomor kamar membuka data tamu atau formulir check-in. | Wajib |
| FR-FO-002 | Menyediakan kalender ketersediaan per tipe kamar dengan horizon minimal 365 hari dan dapat dikonfigurasi, lengkap dengan jumlah kamar tersisa, allotment/hold, dan penanda pembatasan penjualan per tanggal. | Wajib |
| FR-FO-003 | Membuat reservasi dengan sumber pemesanan (langsung, telepon, OTA, korporat, walk-in), status (tentatif, terkonfirmasi, dijamin deposit), dan catatan khusus. | Wajib |
| FR-FO-004 | Menandai reservasi yang tidak datang (no-show) dan pembatalan dengan alasan, serta menerapkan aturan denda bila dikonfigurasi. | Wajib |
| FR-FO-005 | Menandai kamar sebagai Out of Order atau Out of Service dengan rentang tanggal sehingga tidak muncul sebagai kamar yang dapat dijual. | Wajib |
| FR-FO-006 | Mendukung pemesanan grup sederhana: satu pemesan dengan beberapa kamar, satu master folio, dan opsi pemisahan tagihan per kamar. | Sebaiknya |
| FR-FO-007 | Mengelola inventory kamar per tipe dengan aturan overbooking yang dapat dikonfigurasi. Sistem tidak boleh menjual melebihi batas yang disetujui dan wajib memperingatkan pengguna sebelum menerima reservasi yang berpotensi oversold. | Wajib |
| FR-FO-008 | Mengelola rate plan, seasonal rate, corporate rate, package, inclusions, minimum stay, closed-to-arrival/departure, serta tanggal efektif tanpa mengubah histori reservasi lama. | Wajib |
| FR-FO-009 | Mendukung kebijakan guarantee, deposit due date, cancellation, no-show, dan penalty per rate plan/sumber reservasi serta menyimpan policy snapshot pada saat reservasi dibuat. | Wajib |

## 8.2 Registrasi dan Check-in

| ID | Kebutuhan Fungsional | Prioritas |
| --- | --- | --- |
| FR-FO-010 | Formulir check-in memuat: nama tamu, kewarganegaraan, jenis dan nomor identitas (paspor atau KTP), tanggal berlaku identitas, nomor visa bila diperlukan, jumlah tamu (dewasa dan anak), serta alamat sesuai identitas. | Wajib |
| FR-FO-011 | Sistem mengunggah dan menampilkan foto identitas yang diambil langsung dari kamera perangkat resepsionis atau tablet, dan melampirkannya pada data tamu. | Wajib |
| FR-FO-012 | Pemilihan lama menginap menampilkan blok tanggal menginap secara visual serta menghitung otomatis harga per malam sesuai tarif kamar yang bersangkutan. | Wajib |
| FR-FO-013 | Harga kamar dapat diubah kapan pun oleh pengguna berwenang; setiap perubahan mencatat nilai lama, nilai baru, alasan, dan pelaku. Perubahan melebihi ambang diskon yang ditetapkan memerlukan persetujuan Manager on Duty. | Wajib |
| FR-FO-014 | Sistem memperingatkan bila identitas tamu telah kedaluwarsa atau akan kedaluwarsa selama masa menginap. | Sebaiknya |
| FR-FO-015 | Sistem mendeteksi tamu berulang berdasarkan nomor identitas dan mengisi otomatis data profil beserta riwayat menginap dan preferensinya. | Sebaiknya |
| FR-FO-016 | Setelah check-in, status kamar otomatis berubah menjadi terisi dan seluruh permintaan tamu yang tercatat muncul pada kartu kamar tersebut. | Wajib |
| FR-FO-017 | Sistem mencetak atau mengirim kartu registrasi elektronik untuk ditandatangani tamu, termasuk tanda tangan digital pada tablet. | Sebaiknya |
| FR-FO-018 | Mendukung perpindahan kamar (room move) dengan pemindahan seluruh saldo folio dan pencatatan alasan. | Wajib |
| FR-FO-019 | Mendukung perpanjangan masa menginap (Stay Over) dan check-out dipercepat dengan penyesuaian tagihan otomatis. | Wajib |

## 8.3 Folio, Penagihan, dan Pembayaran

| ID | Kebutuhan Fungsional | Prioritas |
| --- | --- | --- |
| FR-FO-020 | Setiap stay memiliki minimal satu folio dan dapat memiliki beberapa folio/window untuk routing tagihan. Folio menampung room charge, pajak, service charge, charge outlet, koreksi, dan pembayaran secara terurut dan dapat ditelusuri. | Wajib |
| FR-FO-021 | Mencetak rincian tagihan (bill print out) yang menampilkan seluruh transaksi terperinci per outlet dan per tanggal. | Wajib |
| FR-FO-022 | Mendukung pemisahan tagihan (split bill) menjadi beberapa folio, misalnya folio perusahaan dan folio pribadi tamu. | Sebaiknya |
| FR-FO-023 | Mendukung pemindahan item tagihan antar folio atau antar kamar dengan pencatatan alasan. | Sebaiknya |
| FR-FO-024 | Menerima pembayaran melalui tunai, QRIS, kartu melalui EDC, transfer bank, dan pembayaran daring dari kanal pemesanan. | Wajib |
| FR-FO-025 | Mencatat deposit di muka dan mengurangkannya secara otomatis pada saat penyelesaian tagihan, termasuk pengembalian sisa deposit. | Wajib |
| FR-FO-026 | Mencatat pembayaran dengan mata uang asing beserta kurs yang berlaku bila fitur diaktifkan. | Bisa |
| FR-FO-027 | Membukukan pendapatan kamar secara otomatis ke modul Finance beserta pemisahan nilai dasar, pajak, dan service charge. | Wajib |
| FR-FO-028 | Menjalankan night audit berdasarkan business date properti: melakukan pre-check transaksi tertunda, membukukan room charge, mengunci hari yang selesai, memindahkan business date, dan menghasilkan laporan. Proses harus aman dijalankan ulang tanpa posting ganda. | Wajib |
| FR-FO-029 | Pembayaran, refund, reversal, dan koreksi folio memiliki status dan referensi yang jelas. Refund atau reversal setelah settlement memerlukan otorisasi, alasan, jejak audit, dan tidak boleh menghapus transaksi asal. | Wajib |

## 8.4 Layanan Tamu dan Tugas Harian

| ID | Kebutuhan Fungsional | Prioritas |
| --- | --- | --- |
| FR-FO-030 | Mencatat permintaan tamu (guest request) dengan template bebas isi dan meneruskannya otomatis ke Housekeeping, Restoran, atau Maintenance sesuai kategori, lengkap dengan status penyelesaian. | Wajib |
| FR-FO-031 | Mencatat komentar dan keluhan tamu beserta tingkat keparahan, penanggung jawab tindak lanjut, dan bukti penyelesaian. | Wajib |
| FR-FO-032 | Menampilkan SOP tugas harian, mingguan, dan bulanan resepsionis pada ponsel atau tablet, dengan isi template yang disusun oleh manajemen. | Wajib |
| FR-FO-033 | Staf menandai tugas selesai; persentase penyelesaian dikirim otomatis ke modul Human Resource sebagai komponen penilaian kinerja. | Wajib |
| FR-FO-034 | Menyediakan buku serah terima shift (log book) yang wajib diisi pada akhir shift dan dibaca pada awal shift berikutnya. | Sebaiknya |

## 8.5 Kontrol Operasional Front Office

| ID | Kebutuhan Fungsional | Prioritas |
| --- | --- | --- |
| FR-FO-035 | Mengelola profil perusahaan/agen, credit limit, billing instruction, dan routing charge untuk tamu korporat tanpa mencampur tagihan pribadi. | Sebaiknya |
| FR-FO-036 | Membuka dan menutup shift kasir Front Office dengan opening float, penerimaan per metode, cash drop, saldo sistem, kas fisik, dan selisih beralasan. | Wajib |
| FR-FO-037 | Mengelola early check-in, late check-out, day-use, dan biaya terkait berdasarkan kebijakan/rate plan yang dapat dikonfigurasi. | Sebaiknya |
| FR-FO-038 | Late charge setelah folio ditutup harus menggunakan alur khusus yang menaut ke stay/folio asal dan tidak mengubah laporan hari lama tanpa adjustment. | Wajib |
| FR-FO-039 | Koreksi nama tamu, identitas, room move, dan routing finansial setelah check-in disimpan sebagai perubahan ter-audit; perubahan data kritis dapat memerlukan approval. | Wajib |

## 8.6 Laporan Back Office dari Front Office

Laporan registrasi tamu wajib memuat kolom berikut dan dapat diekspor ke berkas PDF dan lembar kerja: nama tamu, kewarganegaraan, jenis dan nomor identitas, tanggal berakhir paspor, lama tinggal, tanggal check-in, dan tanggal check-out.

| ID | Kebutuhan Fungsional | Prioritas |
| --- | --- | --- |
| FR-FO-040 | Menerbitkan laporan registrasi tamu harian sesuai kolom di atas dengan penyaring tanggal dan kewarganegaraan. | Wajib |
| FR-FO-041 | Menerbitkan berkas laporan tamu warga negara asing dalam format yang siap disampaikan kepada instansi terkait. | Wajib |
| FR-FO-042 | Menerbitkan laporan pendapatan kamar per metode pembayaran: tunai, QRIS, transfer bank, kartu, dan pembayaran kanal daring. | Wajib |
| FR-FO-043 | Menerbitkan laporan kedatangan, keberangkatan, dan tamu menginap untuk keperluan operasional harian. | Wajib |
| FR-FO-044 | Menerbitkan laporan okupansi, ADR, dan RevPAR per hari, bulan, dan tahun berjalan. | Wajib |

## 8.7 Kriteria Penerimaan

- Check-in tamu dengan data lengkap dan foto identitas dapat diselesaikan dalam waktu maksimal 3 menit.

- Charge dari outlet mana pun muncul pada folio kamar dalam waktu kurang dari 10 detik setelah transaksi ditutup.

- Tidak ada folio yang dapat ditutup jika masih terdapat item tertunda dari outlet atau order laundry yang belum diselesaikan.

- Night audit menghasilkan angka pendapatan yang identik dengan penjumlahan seluruh transaksi hari tersebut.

# 9. Modul 3 — Housekeeping

Tujuan modul: menjamin status kamar selalu akurat dan mempercepat perputaran kamar, sekaligus mengendalikan pemakaian linen dan perlengkapan tamu.

Pengguna utama: Housekeeping Supervisor dan Room Attendant.

## 9.1 Kebutuhan Fungsional

| ID | Kebutuhan Fungsional | Prioritas |
| --- | --- | --- |
| FR-HK-001 | Menampilkan papan status kamar yang sama dengan dashboard dan Front Office; setiap perubahan berlaku serentak untuk seluruh modul. | Wajib |
| FR-HK-002 | Supervisor membagi kamar kepada room attendant; setiap staf menerima tautan pribadi di ponsel berisi daftar kamar dan tugasnya. | Wajib |
| FR-HK-003 | Sistem menyusun urutan prioritas pembersihan secara otomatis: kamar keberangkatan, kamar kotor kosong, permintaan tamu, lalu kamar menginap. | Sebaiknya |
| FR-HK-004 | Room attendant mengubah status kamar langsung dari ponsel dengan maksimal tiga ketukan, termasuk penanda mulai dan selesai membersihkan untuk mengukur durasi. | Wajib |
| FR-HK-005 | Menyediakan daftar periksa SOP tugas harian, mingguan, dan bulanan per kamar dan per area umum, disusun oleh manajemen sebagai template. | Wajib |
| FR-HK-006 | Daftar periksa dapat mewajibkan lampiran foto pada butir tertentu sebagai bukti pengerjaan. | Sebaiknya |
| FR-HK-007 | Supervisor melakukan inspeksi kamar dan menyetujui perubahan status menjadi siap dijual; kamar tanpa inspeksi dapat dikonfigurasi tetap masuk status bersih namun belum siap. | Wajib |
| FR-HK-008 | Room attendant membuat laporan kerusakan dengan cara memilih kamar atau lokasi, menulis keterangan, dan melampirkan foto; laporan langsung menjadi work order pada modul Maintenance. | Wajib |
| FR-HK-009 | Mencatat pemakaian linen dan perlengkapan: sprei, handuk, sarung bantal, sabun, dan amenitas lain, per kamar dan per hari. | Wajib |
| FR-HK-010 | Mencatat sirkulasi linen mengikuti alur gudang ke luar gudang, ke laundry, dan kembali ke gudang; setiap perpindahan wajib diinput saat pengambilan maupun penyimpanan. | Wajib |
| FR-HK-011 | Sistem menghitung selisih linen yang tidak kembali dan menandainya sebagai kehilangan atau kerusakan untuk ditindaklanjuti. | Sebaiknya |
| FR-HK-012 | Mencatat temuan barang tertinggal (lost and found) dengan foto, lokasi, tanggal, penemu, dan status pengembalian. | Sebaiknya |
| FR-HK-013 | Menerima permintaan tamu dari Front Office beserta batas waktu penyelesaian dan menandai status penyelesaiannya. | Wajib |
| FR-HK-014 | Mengajukan permintaan pembelian alat dan bahan ke modul Purchasing langsung dari modul Housekeeping. | Wajib |
| FR-HK-015 | Menerbitkan laporan produktivitas: jumlah kamar dibersihkan per staf, rata-rata durasi per kamar, dan persentase penyelesaian SOP. | Sebaiknya |
| FR-HK-016 | Mendeteksi room status discrepancy antara Front Office dan Housekeeping (misalnya kamar menurut FO vacant tetapi menurut HK occupied/berisi barang) dan mewajibkan resolusi supervisor sebelum kamar dijual. | Wajib |
| FR-HK-017 | Mencatat service flag DND, refused service, make-up-room, dan privacy request dengan waktu mulai/selesai tanpa mengubah occupancy status kamar. | Wajib |
| FR-HK-018 | Inspeksi supervisor dapat menghasilkan status rework dengan daftar temuan; kamar hanya menjadi ready setelah seluruh temuan wajib diselesaikan atau di-waive oleh peran berwenang. | Wajib |
| FR-HK-019 | Mengelola par level linen dan amenitas per tipe kamar/area sehingga kebutuhan replenishment dan selisih konsumsi dapat dihitung per shift. | Sebaiknya |

## 9.2 Guest Laundry — Sisi Housekeeping

| ID | Kebutuhan Fungsional | Prioritas |
| --- | --- | --- |
| FR-HK-020 | Staf Housekeeping memindai barcode kantong laundry lalu memilih kamar untuk membuka order guest laundry. | Wajib |
| FR-HK-021 | Staf mencatat rincian per item sebelum dikirim ke laundry: jenis pakaian (baju, celana, dan seterusnya), merek atau tanpa merek, jumlah, catatan kondisi, tanggal pengambilan, dan tanggal janji kembali kepada tamu. | Wajib |
| FR-HK-022 | Sistem mengirim order tersebut ke modul Laundry lengkap dengan nomor kamar dan jumlah item, dalam bentuk daftar per item sehingga petugas laundry cukup menandai centang. | Wajib |
| FR-HK-023 | Nilai tagihan laundry otomatis dibentuk berdasarkan daftar harga per item dan diposkan ke folio kamar. | Wajib |
| FR-HK-024 | Setelah laundry selesai, Housekeeping menerima notifikasi untuk mengantarkan kembali ke kamar dan menutup order dengan bukti penerimaan. | Wajib |

## 9.3 Kriteria Penerimaan

- Status kamar yang diubah dari ponsel tampil pada layar Front Office dalam waktu kurang dari 10 detik.

- Tidak ada order guest laundry yang dapat ditutup tanpa jumlah item yang sama antara catatan Housekeeping dan konfirmasi laundry.

- Setiap laporan kerusakan dari Housekeeping memiliki nomor work order yang dapat ditelusuri di modul Maintenance.

# 10. Modul 4 — Laundry

Tujuan modul: mengelola pengerjaan cucian tamu dan sirkulasi linen hotel dengan status yang terlacak sampai kembali ke pemiliknya.

Pengguna utama: Laundry Attendant dan Housekeeping Supervisor.

| ID | Kebutuhan Fungsional | Prioritas |
| --- | --- | --- |
| FR-LDY-001 | Menerima daftar order guest laundry dari Housekeeping, dikelompokkan per nomor kamar beserta jumlah item. | Wajib |
| FR-LDY-002 | Petugas memverifikasi item yang diterima dengan cara mencentang daftar; selisih jumlah wajib dicatat sebagai temuan sebelum pengerjaan dimulai. | Wajib |
| FR-LDY-003 | Mengubah status pengerjaan mengikuti tahapan: diterima, dicuci, dikeringkan, disetrika, siap, dan dikembalikan ke Housekeeping. | Wajib |
| FR-LDY-004 | Menandai order selesai sehingga status berubah menjadi selesai dan Housekeeping menerima pemberitahuan untuk pengantaran ke kamar. | Wajib |
| FR-LDY-005 | Mencatat perlakuan khusus: cuci kering, noda membandel, setrika saja, dan layanan kilat dengan tarif berbeda. | Sebaiknya |
| FR-LDY-006 | Mencatat klaim kerusakan atau kehilangan item tamu beserta foto, nilai penggantian, dan persetujuan Manager on Duty. | Sebaiknya |
| FR-LDY-007 | Mengelola linen hotel: penerimaan dari Housekeeping, jumlah dicuci, jumlah rusak atau afkir, dan pengembalian ke gudang. | Wajib |
| FR-LDY-008 | Mencatat pemakaian bahan kimia dan perlengkapan laundry sehingga terhubung dengan kartu stok gudang. | Sebaiknya |
| FR-LDY-009 | Mengajukan permintaan pembelian bahan dan alat ke modul Purchasing. | Wajib |
| FR-LDY-010 | Menerbitkan laporan volume pengerjaan harian, waktu penyelesaian rata-rata, biaya per kilogram, serta pendapatan guest laundry. | Sebaiknya |
| FR-LDY-011 | Setiap order memiliki promised time/SLA; order express dan order melewati janji selesai diberi prioritas serta notifikasi eskalasi. | Wajib |
| FR-LDY-012 | Sistem mencegah penutupan stay bila guest laundry masih berstatus aktif, kecuali diubah menjadi late charge/claim melalui persetujuan yang tercatat. | Wajib |

## 10.1 Kriteria Penerimaan

- Jumlah item pada setiap handover Housekeeping → Laundry → Housekeeping selalu dapat direkonsiliasi; selisih wajib memiliki exception/claim yang belum ditutup.

- Guest laundry yang selesai mem-posting tagihan tepat satu kali ke folio dan tidak dapat diduplikasi oleh retry atau sinkronisasi ulang.

- Order express yang melewati promised time terlihat sebagai overdue dan menghasilkan notifikasi eskalasi.

# 11. Modul 5 — F&B Service (Waiter, Bartender, Kasir)

Tujuan modul: menjadi titik penjualan seluruh outlet makanan dan minuman serta memastikan setiap konsumsi tamu, termasuk mini bar dan room service, tertagih dengan benar.

Pengguna utama: Waiter, Bartender, Kasir, dan Manager on Duty.

## 11.1 Penjualan di Outlet

| ID | Kebutuhan Fungsional | Prioritas |
| --- | --- | --- |
| FR-FBS-001 | Menampilkan denah meja per outlet dengan status kosong, terisi, dan sudah memesan; kasir dapat membuka bill dari meja atau dari nomor kamar. | Wajib |
| FR-FBS-002 | Mengambil pesanan dengan katalog menu bergambar, kategori, varian, catatan khusus, dan jumlah porsi. | Wajib |
| FR-FBS-003 | Mengirim pesanan ke layar dapur dan bar sesuai kategori item, serta mencetak tiket pada printer masing-masing bila diperlukan. | Wajib |
| FR-FBS-004 | Mendukung pemisahan bill, penggabungan bill, dan pemindahan pesanan antar meja. | Sebaiknya |
| FR-FBS-005 | Void item dan pembatalan bill hanya dapat dilakukan dengan alasan dan persetujuan penyelia; seluruh tindakan tercatat pada jejak audit. | Wajib |
| FR-FBS-006 | Diskon dan pemberian gratis (complimentary) memerlukan alasan dan persetujuan sesuai ambang yang dikonfigurasi. | Wajib |
| FR-FBS-007 | Menerima pembayaran tunai, QRIS, kartu melalui EDC, dan pembebanan ke kamar. Pembebanan ke kamar wajib memvalidasi bahwa kamar berstatus terisi dan mencocokkan nama tamu. | Wajib |
| FR-FBS-008 | Menghitung pajak dan service charge secara otomatis sesuai konfigurasi per outlet dan menampilkannya terpisah pada struk. | Wajib |
| FR-FBS-009 | Membuka dan menutup shift kasir dengan penghitungan kas fisik, kas sistem, serta pencatatan selisih beserta alasan. | Wajib |
| FR-FBS-010 | POS tetap dapat mencatat transaksi saat jaringan terputus melalui antrean lokal terenkripsi. Setiap transaksi memiliki idempotency key dan status sinkronisasi sehingga pemulihan jaringan tidak menghasilkan bill, pembayaran, atau pengurangan stok ganda. | Wajib |
| FR-FBS-011 | Mendukung modifier/add-on, tingkat kematangan, pilihan varian, dan catatan khusus yang dapat memengaruhi harga dan resep tanpa membuat item menu duplikat. | Wajib |
| FR-FBS-012 | Perubahan bill oleh beberapa perangkat menggunakan kontrol konkurensi; sistem mencegah lost update dan menampilkan konflik bila bill telah berubah di perangkat lain. | Wajib |
| FR-FBS-013 | Pembayaran QRIS/daring memiliki state initiated, pending, paid, failed, expired, unknown, dan refunded. Status unknown tidak boleh dianggap lunas sebelum rekonsiliasi atau callback valid diterima. | Wajib |
| FR-FBS-014 | Refund, void setelah pembayaran, dan reprint struk memerlukan hak akses sesuai kebijakan, alasan, serta referensi transaksi awal pada audit trail. | Wajib |
| FR-FBS-015 | Mendukung price list dan jadwal harga per outlet/channel/waktu, termasuk promo terjadwal, tanpa mengubah histori harga transaksi yang sudah ditutup. | Sebaiknya |

## 11.2 Mini Bar dan Room Service

| ID | Kebutuhan Fungsional | Prioritas |
| --- | --- | --- |
| FR-FBS-020 | Petugas memeriksa mini bar di kamar tamu dengan memindai barcode kamar lalu memilih menu mini bar. | Wajib |
| FR-FBS-021 | Jumlah minuman dan makanan yang dikonsumsi tamu diinput di tempat dan otomatis terkirim ke kasir serta folio kamar tanpa input ulang. | Wajib |
| FR-FBS-022 | Sistem menghasilkan daftar jumlah item yang harus diisi ulang per kamar untuk shift berikutnya. | Wajib |
| FR-FBS-023 | Riwayat pengisian dan konsumsi mini bar tersimpan per kamar dan per petugas untuk keperluan audit. | Wajib |
| FR-FBS-024 | Mencatat pesanan room service dengan nomor kamar, waktu janji pengantaran, dan status pengantaran. | Wajib |
| FR-FBS-025 | Sistem memblokir pembebanan mini bar setelah folio kamar ditutup dan mengarahkannya ke prosedur late charge. | Sebaiknya |

## 11.3 Persediaan dan Tugas Harian Outlet

| ID | Kebutuhan Fungsional | Prioritas |
| --- | --- | --- |
| FR-FBS-030 | Mengelola persediaan outlet (bar dan gudang outlet) beserta permintaan barang ke gudang utama. | Wajib |
| FR-FBS-031 | Melakukan stock opname harian untuk minuman dan bahan bar dengan pencatatan selisih. | Wajib |
| FR-FBS-032 | Menampilkan SOP tugas harian, mingguan, dan bulanan outlet beserta persentase penyelesaian yang dikirim ke Human Resource. | Wajib |
| FR-FBS-033 | Membuat laporan kerusakan yang diteruskan ke modul Maintenance. | Wajib |
| FR-FBS-034 | Mengajukan permintaan pembelian alat dan bahan ke modul Purchasing. | Wajib |

## 11.4 Kriteria Penerimaan

- Selisih hasil audit mini bar terhadap catatan sistem kurang dari satu persen nilai dalam satu bulan operasi.

- Tidak ada bill yang dapat dibebankan ke kamar yang berstatus kosong atau telah check-out.

- Transaksi luring tersinkronisasi seluruhnya dalam waktu kurang dari lima menit setelah jaringan pulih.

- Pembayaran QRIS berstatus pending/unknown tidak menutup bill sebagai paid dan rekonsiliasi callback tidak membuat pembayaran ganda.

# 12. Modul 6 — F&B Product (Kitchen)

Tujuan modul: mengelola produksi makanan, biaya bahan, dan ketersediaan menu sehingga dapur bekerja berdasarkan tiket digital, bukan kertas.

Pengguna utama: Chef, Cook, dan Steward.

| ID | Kebutuhan Fungsional | Prioritas |
| --- | --- | --- |
| FR-KIT-001 | Menampilkan tiket pesanan dari POS pada layar dapur secara berurutan beserta waktu tunggu dan penanda keterlambatan. | Wajib |
| FR-KIT-002 | Mengubah status tiket menjadi diproses, siap, dan sudah diantar sehingga pelayan menerima pemberitahuan. | Wajib |
| FR-KIT-003 | Mengelola resep dan komposisi bahan (bill of material) berversi untuk setiap menu, termasuk yield, waste standar, satuan, dan tanggal efektif, sebagai dasar biaya bahan dan harga pokok. | Wajib |
| FR-KIT-004 | Mengurangi stok bahan secara otomatis berdasarkan versi resep yang berlaku setiap kali item menu diposting sebagai penjualan, tepat satu kali untuk setiap transaksi. | Wajib |
| FR-KIT-005 | Menandai menu yang habis sehingga otomatis tidak dapat dipesan dari POS maupun menu QR tamu. | Wajib |
| FR-KIT-006 | Mencatat pemakaian bahan, produksi persiapan, dan pembuangan bahan rusak (waste log) beserta alasan. | Wajib |
| FR-KIT-007 | Melakukan stock opname bahan dapur dan gudang kering dengan pencatatan selisih dan nilai kerugian. | Wajib |
| FR-KIT-008 | Menampilkan daftar periksa kebersihan, suhu penyimpanan, dan tugas harian, mingguan, serta bulanan dapur. | Wajib |
| FR-KIT-009 | Mencatat tanggal kedaluwarsa dan nomor batch bahan sensitif dengan peringatan mendekati kedaluwarsa. | Sebaiknya |
| FR-KIT-010 | Membuat laporan kerusakan peralatan yang diteruskan ke modul Maintenance. | Wajib |
| FR-KIT-011 | Mengajukan permintaan pembelian bahan dan peralatan ke modul Purchasing. | Wajib |
| FR-KIT-012 | Menerbitkan laporan penjualan menu, rasio biaya bahan terhadap penjualan, dan analisis menu berdasarkan popularitas serta kontribusi margin. | Sebaiknya |
| FR-KIT-013 | Setiap perubahan resep menghasilkan versi baru bertanggal efektif; transaksi lama selalu mereferensikan versi resep yang berlaku saat transaksi diposting. | Wajib |
| FR-KIT-014 | Mendukung produksi/preparation batch (misalnya sauce, dough, stock) yang mengonsumsi bahan baku dan menghasilkan semi-finished goods beserta yield aktual. | Sebaiknya |
| FR-KIT-015 | KDS menyediakan indikator koneksi dan antrean; bila layar atau jaringan bermasalah, tiket tetap tersimpan dan dapat dialihkan ke printer/fallback queue tanpa kehilangan order. | Wajib |

## 12.1 Kriteria Penerimaan

- Order yang ditutup di POS muncul pada KDS tujuan maksimal 3 detik pada jaringan normal dan tidak hilang saat perangkat KDS reconnect.

- Setiap item terjual mengurangi stok bahan tepat satu kali berdasarkan versi resep yang berlaku saat posting.

- Perubahan sold-out di Kitchen tercermin pada POS dan menu QR maksimal 10 detik.

# 13. Modul 7 — Maintenance / Engineering

Tujuan modul: memastikan setiap kerusakan yang dilaporkan department mana pun tercatat, dikerjakan, dan dibuktikan penyelesaiannya, serta mencegah kerusakan melalui pemeliharaan terjadwal.

Pengguna utama: Chief Engineering dan teknisi.

| ID | Kebutuhan Fungsional | Prioritas |
| --- | --- | --- |
| FR-MTC-001 | Menerima laporan kerusakan dari seluruh department dan mengubahnya menjadi work order bernomor unik dengan lokasi, kategori, dan foto. | Wajib |
| FR-MTC-002 | Menetapkan prioritas (mendesak, tinggi, normal, rendah) dan batas waktu penyelesaian sesuai kesepakatan tingkat layanan. | Wajib |
| FR-MTC-003 | Menugaskan work order kepada teknisi tertentu dan menampilkannya pada ponsel teknisi. | Wajib |
| FR-MTC-004 | Mengikuti status pekerjaan: berjalan, selesai, dan belum selesai beserta alasan bila tertunda (menunggu suku cadang, menunggu vendor, atau menunggu akses kamar). | Wajib |
| FR-MTC-005 | Foto hasil pekerjaan wajib dilampirkan sebelum work order dapat ditandai selesai; sistem menolak penutupan tanpa foto. | Wajib |
| FR-MTC-006 | Menetapkan kamar menjadi Out of Order atau Out of Service beserta perkiraan tanggal selesai; status ini langsung memblokir penjualan kamar di Front Office. | Wajib |
| FR-MTC-007 | Mengelola daftar aset properti (mesin, peralatan, kendaraan) beserta nomor aset, tanggal perolehan, garansi, dan riwayat perbaikan. | Sebaiknya |
| FR-MTC-008 | Menyusun jadwal pemeliharaan pencegahan berkala per aset dan menghasilkan work order secara otomatis pada tanggalnya. | Sebaiknya |
| FR-MTC-009 | Mengelola persediaan suku cadang dan mencatat pemakaiannya pada setiap work order. | Sebaiknya |
| FR-MTC-010 | Mengajukan permintaan pembelian alat dan suku cadang ke modul Purchasing, termasuk pekerjaan yang dikerjakan vendor luar. | Wajib |
| FR-MTC-011 | Menampilkan SOP tugas harian, mingguan, dan bulanan teknik seperti pemeriksaan genset, pompa, dan pendingin ruangan. | Wajib |
| FR-MTC-012 | Menerbitkan laporan: work order per status dan department pelapor, waktu penyelesaian rata-rata, kerusakan berulang per kamar, biaya perbaikan, dan hari kamar tidak dapat dijual. | Wajib |
| FR-MTC-013 | Work order yang mendekati atau melewati SLA menghasilkan eskalasi ke supervisor/MOD sesuai matriks prioritas dan shift. | Wajib |
| FR-MTC-014 | Mencatat meter reading/usage counter untuk aset yang membutuhkan preventive maintenance berdasarkan jam operasi, kilometer, atau siklus selain kalender. | Sebaiknya |
| FR-MTC-015 | Pekerjaan vendor eksternal memiliki quotation, approval, jadwal, biaya aktual, bukti pekerjaan, dan relasi ke aset/work order. | Sebaiknya |

## 13.1 Kriteria Penerimaan

- Setiap laporan kerusakan menghasilkan satu work order unik; retry/notifikasi ulang tidak membuat duplikasi.

- Perubahan kamar menjadi OOO/OOS langsung memengaruhi availability Front Office maksimal 10 detik dan kembali sellable hanya setelah status dilepas oleh peran berwenang.

- Work order yang mewajibkan bukti tidak dapat ditutup tanpa foto/attachment dan, bila dikonfigurasi, verifikasi supervisor.

# 14. Modul 8 — Inventory dan Purchasing

Tujuan modul: mengendalikan seluruh arus barang mulai dari permintaan department, pembelian ke pemasok, penerimaan barang, hingga nilai persediaan yang tersaji di laporan keuangan.

Pengguna utama: Purchasing, Store Keeper, kepala department, dan Finance.

## 14.1 Data Induk dan Persediaan

| ID | Kebutuhan Fungsional | Prioritas |
| --- | --- | --- |
| FR-INV-001 | Mengelola data induk barang: kode, nama, kategori, satuan dasar, konversi satuan (dus ke botol, kilogram ke gram), dan department pemilik. | Wajib |
| FR-INV-002 | Mengelola beberapa lokasi penyimpanan: gudang utama, gudang bar, gudang dapur, gudang housekeeping, gudang teknik, dan galley. | Wajib |
| FR-INV-003 | Menetapkan stok minimum dan stok maksimum per barang per lokasi; pelanggaran batas minimum otomatis muncul sebagai peringatan pada dashboard. | Wajib |
| FR-INV-004 | Mencatat mutasi stok otomatis dari penjualan POS, pemakaian dapur, pemakaian housekeeping, dan pemakaian teknik. | Wajib |
| FR-INV-005 | Melakukan pemindahan barang antar gudang dengan dokumen serah terima dan konfirmasi penerima. | Wajib |
| FR-INV-006 | Melakukan stock opname terjadwal maupun mendadak, membandingkan stok fisik dengan stok sistem, dan menghasilkan berita acara selisih beserta nilai kerugian. | Wajib |
| FR-INV-007 | Menghitung nilai persediaan menggunakan metode rata-rata bergerak dan menyajikannya sebagai laporan nilai persediaan per tanggal. | Sebaiknya |
| FR-INV-008 | Mengelola tanggal kedaluwarsa dan nomor batch untuk barang konsumsi. | Sebaiknya |
| FR-INV-009 | Konversi satuan bersifat berversi dan tidak boleh mengubah histori transaksi; setiap mutasi menyimpan kuantitas satuan transaksi dan ekuivalen satuan dasar. | Wajib |
| FR-INV-010 | Stock adjustment, write-off, dan pembukaan stok negatif memerlukan reason code dan otorisasi sesuai threshold. Kebijakan stok negatif dapat diblokir per kategori/lokasi. | Wajib |
| FR-INV-011 | Mendukung retur ke pemasok dan retur antar gudang dengan dokumen referensi sehingga stok, hutang/kredit, dan histori barang tetap dapat direkonsiliasi. | Wajib |
| FR-INV-012 | Stock opname menggunakan snapshot waktu mulai; mutasi selama opname tetap tercatat dan sistem menghitung expected quantity yang konsisten untuk mencegah selisih semu. | Wajib |

## 14.2 Siklus Pembelian

| ID | Kebutuhan Fungsional | Prioritas |
| --- | --- | --- |
| FR-PUR-001 | Setiap department mengajukan permintaan pembelian (purchase request) berisi barang, jumlah, alasan, dan tingkat urgensi. | Wajib |
| FR-PUR-002 | Permintaan melewati alur persetujuan berjenjang yang dapat dikonfigurasi berdasarkan nilai nominal. | Wajib |
| FR-PUR-003 | Purchasing menggabungkan permintaan yang disetujui menjadi pesanan pembelian (purchase order) kepada pemasok terpilih. | Wajib |
| FR-PUR-004 | Mengelola data pemasok dan vendor secara terpisah dari data barang, meliputi kontak, syarat pembayaran, daftar harga, dan riwayat penilaian. | Wajib |
| FR-PUR-005 | Membandingkan penawaran harga dari beberapa pemasok untuk barang yang sama sebelum penerbitan pesanan. | Sebaiknya |
| FR-PUR-006 | Mencatat penerimaan barang beserta jumlah diterima, jumlah ditolak, kondisi, dan foto; penerimaan sebagian didukung. | Wajib |
| FR-PUR-007 | Mencocokkan tiga dokumen: pesanan pembelian, bukti penerimaan barang, dan faktur pemasok; selisih ditandai untuk ditindaklanjuti. | Wajib |
| FR-PUR-008 | Penerimaan barang otomatis menambah stok gudang tujuan dan membentuk hutang kepada pemasok di modul Finance. | Wajib |
| FR-PUR-009 | Menerbitkan laporan pembelian per department dalam bentuk jumlah barang maupun nilai uang, per periode dan per pemasok. | Wajib |
| FR-PUR-010 | Menerbitkan laporan penerimaan barang dan laporan ketepatan waktu pengiriman pemasok. | Sebaiknya |
| FR-PUR-011 | Perubahan PO yang sudah disetujui menghasilkan revisi bernomor dan memerlukan persetujuan ulang bila mengubah nilai, pemasok, atau kuantitas di atas toleransi. | Wajib |
| FR-PUR-012 | Faktur pemasok mencatat nomor unik pemasok, tanggal, pajak, dan dokumen pendukung; sistem mencegah duplikasi invoice dan menjaga relasi ke PO serta penerimaan barang. | Wajib |
| FR-PUR-013 | Permintaan dan PO menampilkan sisa budget department; kebijakan dapat berupa warning atau hard block sesuai threshold yang dikonfigurasi. | Sebaiknya |

## 14.3 Kriteria Penerimaan

- Transfer antar gudang menjaga total kuantitas properti; stok sumber dan tujuan berubah tepat satu kali setelah handover dikonfirmasi.

- Penerimaan barang menambah stok dan membentuk kewajiban/GRNI tepat satu kali berdasarkan dokumen penerimaan yang sama.

- Stock opname menghasilkan adjustment yang dapat ditelusuri ke snapshot, hitungan fisik, approver, dan reason code; transaksi historis tidak ditimpa.

# 15. Modul 9 — Human Resource

Catatan: pada dokumen konsep awal, modul ini dinyatakan mengikuti sistem lain yang tidak tersedia. Modul berikut karena itu dirancang mandiri di dalam InnSYnc, dengan penekanan pada kebutuhan khas perhotelan: kerja bershift, penilaian berbasis penyelesaian SOP, dan pembagian service charge.

Tujuan modul: mengelola siklus kerja karyawan mulai dari data induk, penjadwalan shift, kehadiran, perizinan, penilaian kinerja, sampai penyediaan dasar perhitungan penggajian dan service charge bagi modul Finance.

Pengguna utama: HR, kepala department, General Manager, dan seluruh karyawan untuk akses mandiri.

## 15.1 Data Induk Karyawan

| ID | Kebutuhan Fungsional | Prioritas |
| --- | --- | --- |
| FR-HR-001 | Mengelola data induk karyawan: nomor induk, nama, department, jabatan, tanggal bergabung, jenis kontrak, masa berlaku kontrak, atasan langsung, dan status aktif. | Wajib |
| FR-HR-002 | Menyimpan berkas kepegawaian: kontrak kerja, identitas, sertifikat keahlian, dan hasil pemeriksaan wajib, beserta tanggal berlaku. | Wajib |
| FR-HR-003 | Memberi peringatan otomatis menjelang berakhirnya kontrak, sertifikat, atau dokumen wajib lainnya. | Sebaiknya |
| FR-HR-004 | Menyediakan portal mandiri karyawan untuk melihat jadwal, sisa cuti, riwayat kehadiran, dan slip pendapatan. | Sebaiknya |
| FR-HR-005 | Proses offboarding menonaktifkan akses, menutup assignment/shift mendatang, mencatat pengembalian aset, dan mempertahankan histori transaksi karyawan tanpa menghapus data historis. | Wajib |

## 15.2 Penjadwalan dan Kehadiran

| ID | Kebutuhan Fungsional | Prioritas |
| --- | --- | --- |
| FR-HR-010 | Menyusun roster shift per department dengan pola shift yang dapat dikonfigurasi (pagi, siang, malam, split, libur) untuk periode mingguan dan bulanan. | Wajib |
| FR-HR-011 | Sistem memperingatkan bila jumlah staf pada suatu shift berada di bawah kebutuhan minimum department. | Sebaiknya |
| FR-HR-012 | Karyawan melakukan presensi masuk dan pulang melalui ponsel dengan verifikasi lokasi (geofence) dan swafoto, atau melalui perangkat presensi di properti. | Wajib |
| FR-HR-013 | Sistem menghitung keterlambatan, pulang lebih awal, jam lembur, dan ketidakhadiran tanpa keterangan secara otomatis terhadap jadwal. | Wajib |
| FR-HR-014 | Jumlah staf bertugas per shift per department dikirim ke dashboard secara langsung. | Wajib |
| FR-HR-015 | Mengelola pengajuan cuti, ijin, dan sakit dengan alur persetujuan berjenjang serta lampiran bukti; hasilnya otomatis mengubah roster. | Wajib |
| FR-HR-016 | Mengelola saldo cuti tahunan, cuti yang sudah diambil, dan sisa cuti per karyawan. | Wajib |
| FR-HR-017 | Mendukung pertukaran shift antar karyawan dengan persetujuan penyelia. | Bisa |
| FR-HR-018 | Mencatat lembur yang telah disetujui sebelumnya dan membedakannya dari kelebihan jam kerja yang tidak disetujui. | Wajib |
| FR-HR-019 | Koreksi presensi setelah periode berjalan memerlukan alasan dan approval; nilai sebelum/sesudah disimpan dan perubahan otomatis memicu hitung ulang komponen terkait. | Wajib |

## 15.3 Kinerja dan Kedisiplinan

| ID | Kebutuhan Fungsional | Prioritas |
| --- | --- | --- |
| FR-HR-020 | Menerima persentase penyelesaian SOP tugas harian, mingguan, dan bulanan dari seluruh modul operasional sebagai komponen penilaian kinerja objektif. | Wajib |
| FR-HR-021 | Menampilkan papan kinerja per karyawan: kehadiran, ketepatan waktu, penyelesaian tugas, jumlah komplain tamu terkait, dan produktivitas department. | Sebaiknya |
| FR-HR-022 | Melakukan penilaian kinerja berkala dengan formulir yang dapat dikonfigurasi dan tanda tangan digital atasan serta karyawan. | Sebaiknya |
| FR-HR-023 | Mencatat teguran, surat peringatan, dan penghargaan karyawan beserta lampiran dan masa berlaku. | Sebaiknya |
| FR-HR-024 | Menyediakan papan pengumuman internal dan distribusi kebijakan yang wajib dibaca dengan pencatatan konfirmasi. | Bisa |

## 15.4 Dasar Penggajian dan Service Charge

| ID | Kebutuhan Fungsional | Prioritas |
| --- | --- | --- |
| FR-HR-030 | Mengelola komponen pendapatan karyawan: gaji pokok, tunjangan tetap, tunjangan tidak tetap, uang makan, dan uang transport. | Wajib |
| FR-HR-031 | Menghitung usulan penggajian periodik berdasarkan kehadiran, lembur, potongan keterlambatan, dan ketidakhadiran, lalu meneruskannya ke modul Finance untuk verifikasi dan pembayaran. | Wajib |
| FR-HR-032 | Menghitung distribusi service charge yang terkumpul dari kamar dan outlet berdasarkan sistem poin per jabatan dan proporsi kehadiran, dengan penyisihan untuk kerusakan atau kehilangan sesuai kebijakan properti. | Wajib |
| FR-HR-033 | Menampilkan simulasi distribusi service charge sebelum disahkan, dan mengunci nilainya setelah disetujui oleh General Manager. | Wajib |
| FR-HR-034 | Menerbitkan slip pendapatan elektronik per karyawan yang memuat rincian gaji, lembur, potongan, dan bagian service charge. | Wajib |
| FR-HR-035 | Mengekspor data penggajian ke berkas lembar kerja atau format yang dapat diterima sistem penggajian pihak ketiga. | Sebaiknya |
| FR-HR-036 | Menyimpan dasar perhitungan pajak penghasilan karyawan dan iuran jaminan sosial sebagai parameter yang dapat dikonfigurasi. | Sebaiknya |
| FR-HR-037 | Payroll run memiliki lifecycle draft, calculated, reviewed, approved, paid, dan locked; hanya periode approved yang boleh diteruskan untuk pembayaran. | Wajib |
| FR-HR-038 | Perubahan setelah payroll/service-charge dikunci dilakukan melalui adjustment pada periode berikutnya atau reopening berizin tinggi; transaksi lama tidak ditimpa. | Wajib |

## 15.5 Kriteria Penerimaan

- Roster satu bulan untuk satu department dapat disusun dalam waktu kurang dari 30 menit.

- Perhitungan distribusi service charge dapat ditelusuri sampai ke transaksi asal dan ke data kehadiran setiap karyawan.

- Tidak ada karyawan yang dapat melakukan presensi di luar radius properti tanpa persetujuan khusus.

# 16. Modul 10 — Finance

Catatan: sebagaimana modul Human Resource, modul ini pada dokumen konsep awal merujuk pada sistem lain. Modul berikut dirancang mandiri dan dibatasi pada akuntansi operasional properti — bukan menggantikan perangkat lunak akuntansi formal, melainkan menyiapkan data yang bersih dan siap dibukukan.

Tujuan modul: menyatukan seluruh pendapatan, biaya, hutang, piutang, dan kewajiban pajak properti dalam satu tempat sehingga posisi keuangan hari berjalan selalu diketahui.

Pengguna utama: Finance atau Accounting, General Manager, dan pemilik.

## 16.1 Pendapatan

| ID | Kebutuhan Fungsional | Prioritas |
| --- | --- | --- |
| FR-FIN-001 | Menerima pembukuan pendapatan otomatis dari Front Office dan seluruh POS outlet, terpisah antara nilai dasar, pajak, dan service charge. | Wajib |
| FR-FIN-002 | Menerbitkan laporan pendapatan harian per outlet dan per metode pembayaran, serta rekapitulasi bulanan. | Wajib |
| FR-FIN-003 | Melakukan rekonsiliasi setoran kasir: kas fisik yang disetor dibandingkan dengan kas sistem per shift dan per kasir, dengan pencatatan selisih. | Wajib |
| FR-FIN-004 | Merekonsiliasi penerimaan QRIS dan kartu terhadap mutasi rekening bank, termasuk pemotongan biaya transaksi. | Sebaiknya |
| FR-FIN-005 | Memverifikasi dan mengunci transaksi hari sebelumnya setelah night audit sehingga tidak dapat diubah tanpa jurnal koreksi. | Wajib |
| FR-FIN-006 | Setiap posting keuangan menyimpan property, business date, event time, source document, actor, dan correlation ID agar rekonsiliasi lintas modul dapat dilakukan tanpa ambigu. | Wajib |

## 16.2 Biaya, Hutang, dan Piutang

| ID | Kebutuhan Fungsional | Prioritas |
| --- | --- | --- |
| FR-FIN-010 | Mengelola daftar akun biaya sederhana yang dikelompokkan per department dan per kategori. | Wajib |
| FR-FIN-011 | Mencatat hutang kepada pemasok dan vendor secara otomatis dari penerimaan barang dan faktur, lengkap dengan syarat pembayaran dan tanggal jatuh tempo. | Wajib |
| FR-FIN-012 | Menampilkan jadwal jatuh tempo pembayaran dan laporan umur hutang, serta mengirimkannya sebagai peringatan ke dashboard. | Wajib |
| FR-FIN-013 | Mencatat pembayaran kepada pemasok dan vendor, baik penuh maupun sebagian, beserta bukti pembayaran. | Wajib |
| FR-FIN-014 | Mengelola piutang dari perusahaan, agen perjalanan, dan kanal pemesanan daring beserta umur piutang dan penagihan. | Wajib |
| FR-FIN-015 | Mengelola kas kecil (petty cash): pengisian, pengeluaran dengan bukti, dan pertanggungjawaban. | Wajib |
| FR-FIN-016 | Mencatat biaya tetap berulang seperti sewa, listrik, air, dan langganan, dengan pengingat jatuh tempo. | Sebaiknya |
| FR-FIN-017 | Menyusun anggaran per department dan menampilkan perbandingan anggaran terhadap realisasi. | Sebaiknya |
| FR-FIN-018 | Pembayaran vendor/pengeluaran di atas threshold menggunakan maker-checker; pembuat transaksi tidak boleh menjadi satu-satunya penyetuju. | Wajib |
| FR-FIN-019 | Refund tamu, chargeback, settlement discrepancy, dan pembayaran berstatus unknown dikelola sebagai exception sampai direkonsiliasi, bukan diedit langsung pada transaksi asal. | Wajib |

## 16.3 Pajak dan Service Charge

| ID | Kebutuhan Fungsional | Prioritas |
| --- | --- | --- |
| FR-FIN-020 | Menghitung pajak daerah atas jasa perhotelan dan makanan minuman secara otomatis per outlet dengan tarif yang dapat dikonfigurasi. | Wajib |
| FR-FIN-021 | Menyajikan lini masa kewajiban pajak: nilai terkumpul berjalan, periode pelaporan, tanggal jatuh tempo, dan status penyetoran. | Wajib |
| FR-FIN-022 | Menghitung akumulasi service charge dari kamar dan outlet serta menyiapkan nilai yang akan didistribusikan melalui modul Human Resource. | Wajib |
| FR-FIN-023 | Menerbitkan berkas rekapitulasi pajak yang siap dilaporkan kepada instansi pajak daerah. | Wajib |
| FR-FIN-024 | Memisahkan pencatatan pendapatan yang tidak dikenai pajak, kompliment, dan penghapusan tagihan agar dasar pengenaan pajak tetap akurat. | Sebaiknya |
| FR-FIN-025 | Tarif pajak, service charge, dan aturan pembulatan memiliki tanggal efektif; perubahan konfigurasi tidak boleh mengubah perhitungan transaksi historis. | Wajib |

## 16.4 Laporan Keuangan Operasional

| ID | Kebutuhan Fungsional | Prioritas |
| --- | --- | --- |
| FR-FIN-030 | Menerbitkan management P&L operasional per department berdasarkan pemetaan pendapatan dan biaya yang tersedia. Laporan diberi label jelas sebagai laporan manajemen, bukan laporan keuangan statutori pengganti buku besar akuntansi. | Wajib |
| FR-FIN-031 | Menerbitkan laporan arus kas ringkas: penerimaan, pengeluaran, dan saldo kas serta bank. | Wajib |
| FR-FIN-032 | Menerbitkan laporan biaya bahan terhadap penjualan untuk outlet makanan dan minuman. | Sebaiknya |
| FR-FIN-033 | Menerbitkan laporan nilai persediaan pada tanggal tertentu berdasarkan data modul Inventory. | Sebaiknya |
| FR-FIN-034 | Mengekspor data transaksi ke format lembar kerja atau format impor perangkat lunak akuntansi yang digunakan properti. | Wajib |
| FR-FIN-035 | Menyimpan jejak audit atas seluruh perubahan angka keuangan beserta pelaku dan waktunya. | Wajib |
| FR-FIN-036 | Transaksi keuangan yang telah locked hanya dapat dikoreksi melalui reversal/adjustment yang menaut ke transaksi asal dan memerlukan alasan serta otorisasi. | Wajib |
| FR-FIN-037 | Rekonsiliasi harian menghasilkan daftar exception antara POS/folio, payment provider/EDC, kas fisik, dan bank; hari dianggap clean hanya bila exception telah diselesaikan atau di-waive. | Wajib |

## 16.5 Kriteria Penerimaan

- Laporan pendapatan harian terbit otomatis setelah night audit tanpa entri manual tambahan.

- Setiap nilai pada laporan laba rugi ringkas dapat ditelusuri sampai ke transaksi asalnya.

- Seluruh hutang yang jatuh tempo dalam tujuh hari selalu muncul pada dashboard tanpa perlu dibuka modulnya.

# 17. Modul 11 — Reporting dan Analytics

Tujuan modul: menjadi pusat seluruh laporan department dalam satu tempat dengan format, periode, dan penerima yang dapat diatur.

| ID | Kebutuhan Fungsional | Prioritas |
| --- | --- | --- |
| FR-RPT-001 | Menyediakan pusat laporan yang mengelompokkan seluruh laporan berdasarkan department dan tema. | Wajib |
| FR-RPT-002 | Seluruh laporan mendukung penyaring rentang tanggal, outlet, department, dan pengguna. | Wajib |
| FR-RPT-003 | Seluruh laporan dapat diekspor ke PDF dan lembar kerja, serta dicetak. | Wajib |
| FR-RPT-004 | Laporan dapat dijadwalkan untuk dikirim otomatis melalui surel atau pesan instan pada waktu tertentu kepada penerima tertentu. | Sebaiknya |
| FR-RPT-005 | Menyediakan laporan ringkas harian untuk manajemen (flash report) yang memuat okupansi, pendapatan, biaya utama, dan kejadian penting. | Wajib |
| FR-RPT-006 | Menyediakan pembanding antar periode: hari ini dibanding kemarin, bulan ini dibanding bulan lalu, dan tahun berjalan dibanding tahun sebelumnya. | Sebaiknya |
| FR-RPT-007 | Menyediakan jejak audit yang dapat dicari berdasarkan pengguna, modul, dan rentang waktu. | Wajib |
| FR-RPT-008 | Menyediakan pembuat laporan sederhana bagi pengguna mahir untuk memilih kolom dan penyaring sendiri. | Bisa |
| FR-RPT-009 | Setiap laporan menampilkan generated-at time, business date/periode, filter yang digunakan, dan sumber data utama sehingga hasil dapat direproduksi. | Wajib |
| FR-RPT-010 | Kolom PII pada laporan mengikuti hak akses dan dapat dimasking; ekspor data sensitif dicatat pada audit log dengan pengguna, waktu, filter, dan tujuan. | Wajib |
| FR-RPT-011 | Ekspor besar diproses asynchronous dengan status pekerjaan dan notifikasi selesai agar tidak membebani transaksi operasional. | Sebaiknya |

## 17.1 Daftar Laporan Standar

| Department | Laporan Standar |
| --- | --- |
| Front Office | Registrasi tamu; tamu warga negara asing; kedatangan dan keberangkatan; pendapatan kamar per metode bayar; okupansi, ADR, dan RevPAR; laporan night audit; batal dan no-show |
| Housekeeping | Produktivitas room attendant; durasi pembersihan; pemakaian linen dan amenitas; sirkulasi linen; lost and found; penyelesaian SOP |
| Laundry | Volume dan waktu pengerjaan; pendapatan guest laundry; klaim kerusakan; pemakaian bahan kimia |
| F&B | Penjualan per outlet dan per jam; bauran penjualan menu; rasio biaya bahan; void, diskon, dan kompliment; selisih kas kasir; konsumsi dan pengisian mini bar |
| Maintenance | Work order per status dan pelapor; waktu penyelesaian; kerusakan berulang; hari kamar tidak dapat dijual; biaya perbaikan dan suku cadang |
| Purchasing & Inventory | Pembelian per department dan pemasok; penerimaan barang; nilai persediaan; hasil stock opname dan selisih; barang di bawah stok minimum |
| Human Resource | Kehadiran dan keterlambatan; lembur; cuti dan ijin; penyelesaian SOP per karyawan; rekap penggajian; distribusi service charge |
| Finance | Pendapatan harian dan bulanan; laba rugi ringkas per department; arus kas; umur hutang dan piutang; rekapitulasi pajak; anggaran terhadap realisasi |

## 17.2 Kriteria Penerimaan

- Angka pada laporan standar sama dengan total transaksi sumber untuk filter dan business date yang sama.

- Pengguna tanpa hak PII tidak dapat melihat data sensitif melalui layar, ekspor, scheduled report, maupun direct URL.

- Laporan terjadwal menyimpan status pengiriman dan kegagalan dapat dicoba ulang tanpa mengirim duplikat yang tidak terkendali.

# 18. Modul 12 — Guest Self-Service (QRIS dan QR Menu)

Tujuan modul: memberi tamu jalur mandiri untuk mendaftar, memesan, dan membayar tanpa memasang aplikasi, sekaligus mengurangi antrean di meja depan dan mempercepat pesanan outlet.

## 18.1 Check-in Mandiri

| ID | Kebutuhan Fungsional | Prioritas |
| --- | --- | --- |
| FR-GST-001 | Tamu memindai kode QR di area lobi atau menerima tautan sebelum kedatangan untuk membuka halaman pendaftaran mandiri. | Wajib |
| FR-GST-002 | Tamu mengisi data diri, mengunggah atau memotret identitas, dan membubuhkan tanda tangan digital pada kartu registrasi. | Wajib |
| FR-GST-003 | Tamu melakukan pembayaran atau deposit melalui QRIS; status pembayaran otomatis tercatat pada folio. | Wajib |
| FR-GST-004 | Setelah verifikasi oleh resepsionis, tamu menerima konfirmasi berisi nomor kamar dan petunjuk pengambilan kunci. | Wajib |
| FR-GST-005 | Data hasil check-in mandiri masuk ke antrean verifikasi Front Office, bukan langsung mengubah status kamar tanpa persetujuan petugas. | Wajib |
| FR-GST-006 | Tautan check-in mandiri menggunakan token acak berumur terbatas, rate limiting, dan validasi reservasi; tautan kedaluwarsa tidak dapat digunakan kembali. | Wajib |
| FR-GST-007 | Sebelum mengirim identitas/tanda tangan, tamu diberikan pemberitahuan privasi dan persetujuan yang versinya tersimpan bersama waktu persetujuan. | Wajib |

## 18.2 Menu QR di Kamar dan Meja Restoran

| ID | Kebutuhan Fungsional | Prioritas |
| --- | --- | --- |
| FR-GST-010 | Setiap kamar dan setiap meja restoran memiliki kode QR unik yang membuka menu digital. | Wajib |
| FR-GST-011 | Pemesanan dari kamar mewajibkan pengisian nomor kamar dan mencocokkannya dengan nama tamu yang sedang menginap agar terintegrasi dengan kasir dan Front Office. | Wajib |
| FR-GST-012 | Pesanan dari menu QR masuk ke POS outlet dan layar dapur seperti pesanan yang diambil pelayan, dengan penanda sumber pesanan. | Wajib |
| FR-GST-013 | Menu yang ditandai habis oleh dapur otomatis tidak dapat dipesan melalui menu QR. | Wajib |
| FR-GST-014 | Tamu memilih pembayaran langsung melalui QRIS atau pembebanan ke kamar; pembebanan ke kamar memerlukan verifikasi petugas. | Wajib |
| FR-GST-015 | Tamu dapat mengirim permintaan layanan dan keluhan dari halaman yang sama, yang langsung masuk ke antrean department terkait. | Sebaiknya |
| FR-GST-016 | Tamu dapat melihat rincian tagihan berjalan dan mengisi survei kepuasan menjelang keberangkatan. | Sebaiknya |
| FR-GST-017 | Halaman tamu tersedia dalam Bahasa Indonesia dan Bahasa Inggris, ringan dibuka pada jaringan lambat, dan tidak memerlukan pemasangan aplikasi. | Wajib |
| FR-GST-018 | QR kamar/meja tidak mengekspos identifier internal yang mudah ditebak. Session tamu berumur terbatas dan aksi sensitif seperti room charge memerlukan verifikasi konteks stay. | Wajib |
| FR-GST-019 | Tamu dapat melihat status pesanan/permintaan layanan tanpa memperoleh akses ke data tamu lain atau histori stay sebelumnya. | Sebaiknya |

## 18.3 Kriteria Penerimaan

- Self check-in tidak pernah langsung mengubah kamar menjadi occupied sebelum verifikasi Front Office dan room assignment yang sah.

- Callback pembayaran QRIS diproses idempotent; refresh halaman atau callback berulang tidak menambah pembayaran kedua.

- Token/tamu hanya dapat mengakses reservasi, order, permintaan, dan folio miliknya sendiri; percobaan akses lintas stay ditolak dan dicatat.

# 19. Aturan Bisnis Lintas Modul

Aturan berikut berlaku pada seluruh modul dan mengalahkan interpretasi lokal yang bertentangan pada requirement per modul.

| Kode | Aturan Lintas Modul | Implikasi |
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

## 19.1 Sumber Kebenaran Data (System of Record)

| Domain | System of Record | Konsumen Utama |
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

# 20. State Machine dan Invariant Kritis

State machine berikut menjadi acuan implementasi backend, UI, audit trail, dan pengujian agar status tidak dapat meloncat secara tidak sah.

| Domain | State Utama | Invariant Kritis |
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

# 21. Model Data Utama

Bagian ini menetapkan entitas inti beserta keterkaitannya sebagai acuan model domain dan kontrak data. Setiap entitas memiliki identifier unik, property scope, created/updated timestamp, actor/audit reference, serta aturan versioning/locking sesuai jenis datanya.

| Entitas | Atribut Kunci | Keterkaitan |
| --- | --- | --- |
| Properti | Nama, alamat, zona waktu, mata uang, tarif pajak, kebijakan service charge | Menaungi seluruh entitas lain |
| Kamar | Nomor, tipe, lantai, kapasitas, tarif dasar, status, penanda aktif | Berelasi dengan Reservasi, Folio, Work Order |
| Tipe Kamar | Nama, fasilitas, tarif musiman | Dimiliki banyak Kamar |
| Tamu | Nama, kewarganegaraan, jenis dan nomor identitas, masa berlaku, alamat, kontak, foto identitas | Berelasi dengan Reservasi dan Folio |
| Reservasi | Sumber, tanggal masuk dan keluar, jumlah tamu, tarif, status, deposit | Menghubungkan Tamu dan Kamar |
| Folio | Nomor, kamar, tamu, status, saldo | Menampung Item Tagihan dan Pembayaran |
| Item Tagihan | Deskripsi, outlet, jumlah, harga, pajak, service charge, waktu | Milik satu Folio, berasal dari POS atau Front Office |
| Pembayaran | Metode, nominal, referensi, waktu, kasir | Milik satu Folio atau Bill POS |
| Outlet | Nama, jenis, tarif pajak, konfigurasi printer | Menaungi Menu dan Bill |
| Menu Item | Nama, kategori, harga, resep, status ketersediaan | Berelasi dengan Resep dan Barang |
| Bill POS | Outlet, meja atau kamar, pelayan, status, total | Menghasilkan Item Tagihan pada Folio bila dibebankan ke kamar |
| Order Laundry | Kamar, daftar item, status, tanggal ambil dan kembali, nilai | Berelasi dengan Folio dan Housekeeping |
| Work Order | Nomor, pelapor, lokasi, kategori, prioritas, status, foto sebelum dan sesudah | Berelasi dengan Kamar atau Aset |
| Aset | Kode, nama, lokasi, tanggal perolehan, garansi, jadwal pemeliharaan | Berelasi dengan Work Order |
| Barang | Kode, nama, kategori, satuan, konversi, stok minimum | Berelasi dengan Gudang, Mutasi Stok, Resep |
| Mutasi Stok | Barang, gudang, jenis mutasi, jumlah, referensi dokumen | Sumber nilai persediaan |
| Purchase Request / Order | Department, daftar barang, jumlah, status persetujuan, pemasok | Menghasilkan Penerimaan Barang dan Hutang |
| Pemasok | Nama, kontak, syarat pembayaran, daftar harga | Berelasi dengan Purchase Order dan Hutang |
| Karyawan | Nomor induk, nama, department, jabatan, kontrak, status | Berelasi dengan Shift, Kehadiran, Penggajian |
| Shift & Kehadiran | Tanggal, jenis shift, jam masuk dan pulang, status, lokasi presensi | Dasar penggajian dan service charge |
| Transaksi Keuangan | Jenis, akun, nominal, referensi, tanggal, status | Agregasi dari seluruh modul |
| Pengguna & Peran | Nama pengguna, peran, izin, status, autentikasi | Mengendalikan akses seluruh modul |
| Jejak Audit | Pengguna, modul, aksi, nilai sebelum, nilai sesudah, waktu | Melekat pada seluruh entitas transaksional |
| Rate Plan & Restriction | Nama, channel/source, tarif per tanggal, inclusions, min/max stay, CTA/CTD, tanggal efektif | Dipakai Reservasi, Stay, dan pricing |
| Stay | Reservasi, tamu, kamar, check-in/out aktual, business date, status | Menghubungkan Reservasi, Folio, Housekeeping, Guest Service |
| Business Date / Night Audit | Property, tanggal bisnis, status audit, waktu mulai/selesai, operator, exception | Menjadi batas posting harian seluruh modul |
| Cashier Shift | Outlet/front office, device/register, operator, opening float, close total, variance | Berelasi dengan Pembayaran dan Rekonsiliasi |
| Tax & Service Rule | Jenis, rate, basis, rounding, outlet, tanggal efektif | Dipakai semua perhitungan pajak/service charge |
| Approval | Jenis dokumen, maker, approver, threshold, status, komentar, waktu | Melekat pada diskon, void, PO, payment, adjustment, payroll |
| Integration Event | Source, event type, correlation ID, idempotency key, status, retry count | Audit dan retry integrasi/webhook/outbox |
| Data Export Job | Jenis laporan, filter, requester, status, lokasi file, expiry | Mengendalikan ekspor besar dan data sensitif |

# 22. Kebutuhan Non-Fungsional

| Kode | Kategori | Kebutuhan |
| --- | --- | --- |
| NFR-01 | Kinerja | Halaman POS dan pelacak Housekeeping merespons dalam waktu kurang dari 2 detik; dashboard selesai dimuat kurang dari 3 detik pada koneksi 4G. |
| NFR-02 | Kapasitas | Mendukung hingga 150 kamar, 50 pengguna bersamaan, dan 5.000 transaksi POS per hari tanpa penurunan kinerja berarti. |
| NFR-03 | Ketersediaan | Ketersediaan layanan target minimal 99,9% per bulan di luar jendela pemeliharaan terjadwal. Degradasi fungsi non-kritis tidak boleh memblokir check-in, checkout, POS, atau room status. |
| NFR-04 | Ketahanan luring | POS dan Housekeeping tetap dapat mencatat aktivitas tanpa jaringan minimal 4 jam. Data lokal dienkripsi, memiliki status sync, retry, idempotency key, dan aturan konflik; kegagalan sinkronisasi tidak boleh silently discard data. |
| NFR-05 | Keamanan akses | Autentikasi dengan kata sandi kuat, penguncian akun setelah percobaan gagal berulang, dan verifikasi dua langkah untuk peran manajerial. |
| NFR-06 | Otorisasi | Kontrol akses berbasis role hingga tingkat fitur dan scope property/outlet/department. Aksi sensitif menggunakan maker-checker/approval dan, bila approval diwajibkan, pembuat tidak boleh menjadi satu-satunya approver. |
| NFR-07 | Kerahasiaan data | Data dienkripsi saat transit dan saat disimpan; foto identitas tamu disimpan terenkripsi dengan akses terbatas dan masa retensi yang dapat dikonfigurasi. |
| NFR-08 | Kepatuhan privasi | Mendukung privacy notice/consent, pembatasan tujuan, hak akses/koreksi/penghapusan sesuai kebijakan yang berlaku, data retention configurable, serta audit atas akses dan ekspor PII. |
| NFR-09 | Pembayaran | Sistem tidak menyimpan nomor kartu; transaksi kartu diselesaikan melalui perangkat atau gerbang pembayaran bersertifikat. |
| NFR-10 | Jejak audit | Seluruh perubahan data transaksional tercatat lengkap dan tidak dapat dihapus oleh pengguna mana pun. |
| NFR-11 | Cadangan data | Backup otomatis mencakup full backup harian dan mekanisme point-in-time/incremental yang mencapai RPO ≤15 menit. Target RTO ≤4 jam. Restore test dijalankan berkala dan hasilnya dicatat. |
| NFR-12 | Kegunaan | Antarmuka dua bahasa (Indonesia dan Inggris), rancangan mengutamakan ponsel untuk staf lapangan, dan pelatihan dasar cukup satu jam per peran. |
| NFR-13 | Kompatibilitas | Mendukung peramban terkini pada Android dan iOS, printer thermal standar ESC/POS, pemindai barcode, dan layar dapur. |
| NFR-14 | Terpelihara | Pemisahan tegas antara logika dan tampilan, penomoran versi aplikasi yang tampil pada antarmuka, serta catatan perubahan setiap rilis. |
| NFR-15 | Dokumentasi | Tersedia panduan penggunaan berbasis peran di dalam aplikasi, bukan sekadar dokumen instalasi. |
| NFR-16 | Skalabilitas | Struktur data disiapkan untuk pengoperasian banyak properti dalam satu akun pada rilis berikutnya. |
| NFR-17 | Konsistensi transaksi | Operasi lintas modul yang bersifat finansial atau stok menggunakan atomic transaction/outbox yang sesuai; sistem tidak boleh meninggalkan partial posting tanpa exception yang terlihat. |
| NFR-18 | Idempotensi | Endpoint/action kritis, offline sync, import, webhook, dan payment callback wajib aman terhadap retry menggunakan idempotency key/correlation ID. |
| NFR-19 | Konkurensi | Bill, folio, reservasi/availability, stock count, dan approval memakai optimistic/pessimistic control yang mencegah lost update; konflik harus ditampilkan kepada pengguna. |
| NFR-20 | Observability | Tersedia structured logs, audit/security logs, health check, metrics, trace/correlation ID, serta alert untuk payment unknown, sync backlog, error rate, job failure, backup failure, dan kapasitas kritis. |
| NFR-21 | Disaster recovery | Prosedur DR terdokumentasi, dependency dan credential recovery diuji, serta simulasi restore dilakukan minimal triwulanan atau sesuai kebijakan operasional properti. |
| NFR-22 | Keamanan sesi | Session timeout, revocation, secure cookie/token storage, device/session list, rate limiting login, CSRF protection untuk web, dan forced re-authentication pada aksi sensitif diterapkan sesuai risiko. |
| NFR-23 | Manajemen rahasia | Secret, API key, private key, dan credential tidak disimpan pada source code/log; rotasi dan pencabutan dapat dilakukan tanpa redeploy penuh bila memungkinkan. |
| NFR-24 | Keamanan ekspor | File ekspor sensitif memiliki akses terbatas, expiry, dan audit download; tautan publik permanen untuk data tamu/karyawan dilarang. |
| NFR-25 | Ketahanan integrasi | Integrasi eksternal memiliki timeout, retry dengan backoff, circuit breaker/queue bila relevan, dead-letter handling, dan rekonsiliasi manual untuk state unknown. |
| NFR-26 | Waktu & zona | Semua timestamp disimpan konsisten dan ditampilkan menurut zona waktu property; business date dipisahkan dari clock date untuk night audit dan laporan. |
| NFR-27 | Aksesibilitas | Antarmuka back office dan tamu menargetkan praktik aksesibilitas modern: keyboard navigation, label form, kontras, focus state, pesan error yang dapat dipahami, dan dukungan pembaca layar untuk alur utama. |
| NFR-28 | API & kompatibilitas | API/integration contract memiliki versioning dan backward-compatibility policy; browser/device support matrix didokumentasikan dan diuji pada versi minimum yang disepakati. |
| NFR-29 | Retensi audit | Audit trail, security event, dan evidence approval memiliki retensi minimum yang dapat dikonfigurasi dan tidak dapat dihapus oleh pengguna operasional biasa. |
| NFR-30 | Pemulihan operasional | Untuk kegagalan layanan kritis, tersedia runbook, fallback manual, serta mekanisme rekonsiliasi saat layanan pulih agar transaksi fallback tidak hilang atau terduplikasi. |

# 23. Integrasi Eksternal

| Integrasi | Tujuan | Fase |
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

## 23.1 Kepatuhan dan Regulasi

- Pajak daerah atas jasa perhotelan dan jasa makanan minuman dihitung per outlet dengan tarif yang dapat dikonfigurasi karena berbeda antar daerah.

- Service charge dicatat terpisah dari pendapatan properti dan didistribusikan sesuai kebijakan ketenagakerjaan yang berlaku serta kebijakan internal properti.

- Data registrasi tamu diarsipkan sesuai kewajiban penyimpanan dokumen dan dapat diterbitkan kembali untuk keperluan pemeriksaan.

- Pengolahan data pribadi tamu dan karyawan mengikuti ketentuan perlindungan data pribadi, termasuk pembatasan akses foto identitas dan penghapusan setelah masa retensi berakhir.

## 23.2 Prinsip Integrasi Eksternal

- Setiap provider eksternal diperlakukan sebagai dependency yang dapat gagal; state internal tidak boleh bergantung pada satu callback tanpa mekanisme rekonsiliasi.

- Webhook inbound diverifikasi autentikasi/signature sesuai kemampuan provider, diproses idempotent, dan disimpan correlation/reference ID-nya.

- Webhook/outbound job memiliki retry terbatas, backoff, dead-letter/exception queue, serta dashboard operasional untuk item gagal.

- Credential provider dipisahkan per environment/property bila diperlukan dan tidak boleh tampil pada log atau UI biasa.

# 24. Migrasi Data, Cutover, dan Rollback

## 24.1 Cakupan Migrasi

- Data induk minimum: properti, kamar/tipe kamar, rate plan, outlet, menu/harga, barang/satuan/konversi, gudang, pemasok, aset, karyawan, role/permission, pajak, dan service charge.

- Data transaksi historis hanya dimigrasikan bila disepakati; bila tidak, saldo pembuka, outstanding reservation, deposit, AR/AP, stok, dan open work order harus memiliki cut-off yang terdokumentasi.

- Setiap template impor memiliki schema/version, validasi wajib, laporan error per baris, dan mode dry-run sebelum commit.

## 24.2 Rekonsiliasi dan Cutover

| Tahap | Gate Minimum | Output |
| --- | --- | --- |
| Dry Run 1 | Template lengkap dan mapping disetujui | Daftar error, duplikasi, data hilang, dan owner perbaikan |
| Dry Run 2 | Error kritis nol; sampling bisnis tervalidasi | Baseline migrasi dan estimasi durasi cutover |
| Pre Cutover | Backup sumber, freeze window, daftar transaksi terbuka, user/role siap | Go/No-Go checklist |
| Cutover | Import final, rekonsiliasi count & amount, smoke test alur kritis | Berita acara cutover dan timestamp |
| Hypercare | Monitoring, reconciliation harian, issue triage | Daftar isu dan keputusan rollback/continue |
| Rollback | Dijalankan bila gate kritis gagal dalam window yang disepakati | Kembali ke sumber lama + daftar transaksi yang perlu re-entry/reconcile |

# 25. Strategi Pengujian, UAT, dan Definition of Done

## 25.1 Lapisan Pengujian

| Jenis Uji | Fokus Minimum | Contoh Gate |
| --- | --- | --- |
| Unit/Domain | Perhitungan harga, pajak, service charge, rate restriction, costing, payroll basis | Kasus batas dan rounding tervalidasi |
| Integration/Contract | Payment, email/message, storage, printer/KDS, import/export, webhook | Retry, timeout, duplicate callback, signature, provider down |
| End-to-End | Reservation → check-in → outlet/laundry → payment → checkout → night audit | Saldo dan laporan rekonsiliasi penuh |
| Offline/Sync | POS & Housekeeping putus-sambung, konflik, retry, backlog | Tidak ada transaksi hilang/ganda |
| Security | RBAC, privilege escalation, IDOR, session, PII export, audit | Akses lintas property/role ditolak |
| Performance | Jam sibuk POS, dashboard, report, night audit, bulk import | Memenuhi NFR pada volume target |
| Backup/Restore | Restore DB/file, credential dependency, runbook | RPO/RTO dibuktikan |
| UAT | Skenario per role dan per shift dari data realistis | Semua Wajib lulus atau waiver tertulis |

## 25.2 Definition of Done

- Acceptance criteria terpenuhi dan automated/manual test evidence tersedia untuk requirement Wajib.

- Permission, audit trail, error state, empty state, loading state, retry, dan edge case telah diuji; bukan hanya happy path.

- Migration/backfill bila diperlukan tersedia, reversible atau memiliki rollback plan, serta tidak merusak data existing.

- Monitoring/alert, log tanpa PII berlebihan, dokumentasi pengguna, dan runbook operasional diperbarui.

- Tidak ada defect severity Critical/Blocker terbuka; defect High harus memiliki keputusan risiko tertulis sebelum release.

# 26. Rencana Rilis

Pengembangan dibagi menjadi tiga fase agar properti memperoleh manfaat sejak bulan-bulan awal, dimulai dari modul yang paling menentukan operasional harian.

| Fase | Periode | Cakupan | Hasil yang Diharapkan |
| --- | --- | --- | --- |
| Fase 1 — Inti Operasional | Bulan 1–4 | Data induk dan pengguna; Front Office lengkap; Housekeeping dan Laundry; papan status kamar; Dashboard versi awal; laporan tamu dan pendapatan kamar | Properti dapat beroperasi tanpa buku registrasi manual dan status kamar akurat sepanjang hari |
| Fase 2 — Pendapatan dan Persediaan | Bulan 5–8 | POS seluruh outlet; layar dapur; mini bar dan room service; Inventory dan Purchasing; Maintenance; Finance versi awal | Seluruh pendapatan dan biaya tercatat dalam satu sistem, kebocoran mini bar dan laundry tertutup |
| Fase 3 — Optimasi dan Mandiri | Bulan 9–12 | Human Resource dan distribusi service charge; pemeliharaan pencegahan; check-in mandiri dan menu QR; analitik lanjutan; integrasi kanal pemesanan | Beban administrasi manajemen berkurang dan tamu memperoleh jalur layanan mandiri |

## 26.1 Kriteria Go-Live per Fase

- Seluruh kebutuhan berprioritas Wajib pada fase tersebut telah lulus uji penerimaan pengguna.

- Data induk (kamar, menu, barang, pemasok, karyawan) telah dimigrasikan dan diverifikasi.

- Pelatihan seluruh peran terkait telah dilaksanakan dan panduan dalam aplikasi tersedia.

- Prosedur cadangan manual telah disiapkan untuk mengantisipasi gangguan pada minggu pertama.

- Berjalan paralel dengan cara kerja lama selama minimal tujuh hari sebelum penghentian cara lama.

- Rekonsiliasi data migrasi mencapai 100% untuk count/amount yang ditetapkan sebagai control total.

- Backup dan restore test berhasil, monitoring/alert aktif, serta runbook incident dan rollback telah disimulasikan.

- Tidak terdapat defect Critical/Blocker terbuka; seluruh exception High memiliki owner, workaround, dan persetujuan risiko.

- Minimal satu siklus business date/night audit dan satu shift POS/Front Office end-to-end berhasil pada data pilot.

# 27. Risiko dan Mitigasi

| Risiko | Dampak | Mitigasi |
| --- | --- | --- |
| Staf enggan berpindah dari cara kerja manual | Data tidak lengkap sehingga seluruh laporan kehilangan makna | Rancangan tiga ketukan untuk staf lapangan, pelatihan per peran, pendampingan di lokasi pada dua minggu pertama, dan pemantauan adopsi harian |
| Jaringan internet properti tidak stabil | POS dan pelacak kamar terhenti pada jam sibuk | Mode luring dengan antrean sinkronisasi, serta rekomendasi jalur internet cadangan |
| Data induk tidak siap saat go-live | Penundaan implementasi dan kesalahan harga | Templat impor data induk disiapkan sejak awal proyek dan diverifikasi dua minggu sebelum go-live |
| Kebocoran data identitas tamu | Risiko hukum dan reputasi | Enkripsi penyimpanan, pembatasan akses berbasis peran, jejak audit, dan kebijakan retensi |
| Perbedaan tarif pajak antar daerah | Perhitungan pajak keliru | Tarif pajak dan aturan pembulatan dijadikan parameter yang dapat dikonfigurasi per outlet |
| Perselisihan pembagian service charge | Ketidakpuasan karyawan | Rumus poin transparan, simulasi sebelum pengesahan, dan slip rincian per karyawan |
| Cakupan pekerjaan meluas selama pengembangan | Jadwal meleset dan biaya membengkak | Penetapan prioritas Wajib, Sebaiknya, dan Bisa; perubahan setelah sign-off melalui change request |
| Ketergantungan pada satu vendor pembayaran | Gangguan pembayaran saat vendor bermasalah | Rancangan lapisan pembayaran yang dapat menampung lebih dari satu penyedia |
| Kondisi oversold/availability tidak sinkron | Kamar terjual melebihi kapasitas dan menimbulkan relokasi/kompensasi | Inventory control terpusat, reservation lock, alert oversell, dan rekonsiliasi integrasi OTA/channel. |
| Duplicate posting akibat retry/offline sync | Pendapatan, pembayaran, atau stok tercatat ganda | Idempotency key, correlation ID, unique constraint, outbox/queue, serta reconciliation exception. |
| Payment callback terlambat/unknown | Bill dianggap belum/atau sudah lunas secara keliru | State machine pembayaran, polling/rekonsiliasi provider, dan aturan bahwa unknown tidak boleh dianggap paid. |
| Penyalahgunaan hak akses internal | Void, diskon, refund, ekspor PII, atau perubahan data tanpa kewenangan | Least privilege, maker-checker, MFA peran istimewa, audit immutable, dan review akses berkala. |
| Backup tersedia tetapi tidak dapat direstore | Kehilangan data dan downtime panjang saat incident | Restore test berkala, monitoring backup, runbook DR, dan penyimpanan backup terpisah. |
| Konfigurasi pajak/rate/service charge salah | Tagihan dan laporan salah secara massal | Effective dating, preview/simulation, dual approval untuk konfigurasi sensitif, dan audit perubahan. |

# 28. Governance, Change Control, dan Operasional Produk

## 28.1 Ownership Keputusan

| Area | Decision Owner | Wajib Dikonsultasikan |
| --- | --- | --- |
| Scope & prioritas produk | Product Owner | Owner/GM, Tech Lead, kepala department terkait |
| Kebijakan operasional hotel | Owner/GM | Department Manager, Product Owner |
| Kebijakan finansial, pajak, service charge | Finance Manager / Owner | HR, Product Owner |
| Keamanan, data, arsitektur | Tech Lead | Product Owner, pihak IT properti |
| Go-live / rollback | Product Owner + Owner/GM | Tech Lead, Finance, department pilot |

## 28.2 Change Control

- Perubahan setelah sign-off wajib memiliki change request yang menjelaskan alasan, dampak scope, requirement/NFR terdampak, risiko, estimasi, fase target, dan approver.

- Perubahan kebijakan bisnis yang memengaruhi transaksi historis harus menggunakan effective date dan tidak melakukan retroactive rewrite tanpa proses koreksi resmi.

- Setiap release memiliki release notes, daftar migration, rollback plan, feature flag bila relevan, dan keputusan go/no-go.

- Permission dan konfigurasi sensitif ditinjau berkala; akun karyawan nonaktif harus dicabut segera melalui proses offboarding.

# 29. Asumsi, Ketergantungan, dan Pertanyaan Terbuka

## 29.1 Asumsi

- Properti memiliki koneksi internet dan setiap staf operasional memiliki ponsel pintar yang dapat membuka peramban.

- Struktur organisasi mencakup department Front Office, Housekeeping, Laundry, F&B Service, Kitchen, Maintenance, Purchasing, HR, dan Finance.

- Kebijakan tarif kamar, pajak, dan service charge telah ditetapkan manajemen sebelum konfigurasi sistem.

- Properti bersedia menjalankan proses paralel dengan cara lama selama masa transisi.

## 29.2 Ketergantungan

- Ketersediaan data induk dari properti: daftar kamar dan tarif, menu dan harga, daftar barang, pemasok, dan data karyawan.

- Penunjukan satu penanggung jawab proyek dari pihak properti sebagai pengambil keputusan harian.

- Pengadaan perangkat keras pendukung: tablet POS, printer thermal, dan pemindai barcode.

## 29.3 Pertanyaan Terbuka yang Perlu Dijawab Sebelum Pengembangan

| No | Pertanyaan | Penanggung Jawab |
| --- | --- | --- |
| Q-01 | Berapa jumlah kamar, tipe kamar, dan outlet yang akan dikonfigurasi pada tahap awal? | Pemilik / GM |
| Q-02 | Apakah sistem akan digunakan untuk lebih dari satu properti dalam dua tahun ke depan? | Pemilik |
| Q-03 | Apakah properti sudah memakai sistem kasir atau PMS lain yang datanya perlu dimigrasikan? | GM / IT |
| Q-04 | Penyedia gerbang pembayaran QRIS mana yang akan digunakan dan bank penampung mana? | Finance |
| Q-05 | Berapa tarif pajak daerah yang berlaku dan bagaimana skema service charge saat ini? | Finance / HR |
| Q-06 | Bagaimana rumus pembagian service charge yang berlaku (poin per jabatan dan penyisihan)? | HR / GM |
| Q-07 | Perangkat lunak akuntansi apa yang dipakai sebagai tujuan ekspor data keuangan? | Finance |
| Q-08 | Apakah diperlukan integrasi kunci pintu elektronik, dan merek apa yang terpasang? | Engineering |
| Q-09 | Format laporan tamu asing seperti apa yang diminta instansi setempat? | Front Office Manager |
| Q-10 | Apakah guest laundry ditarifkan per item atau per kilogram, dan bagaimana daftar harganya? | Housekeeping Manager |
| Q-11 | Jam berapa business date berakhir dan apakah night audit boleh dijalankan sebelum/ sesudah tengah malam kalender? | GM / Finance / Front Office |
| Q-12 | Rate plan apa saja yang wajib tersedia saat go-live (BAR, corporate, OTA, package, complimentary) dan apa aturan min stay/CTA/CTD-nya? | Revenue/GM / Front Office |
| Q-13 | Bagaimana aturan pembulatan pajak, service charge, diskon, refund, dan selisih pembayaran di setiap outlet? | Finance |
| Q-14 | Browser/perangkat minimum yang dipakai staf dan berapa lama kebutuhan offline realistis pada titik POS/Housekeeping? | IT / Operasional |
| Q-15 | Berapa masa retensi foto identitas tamu, dokumen HR, audit log, invoice, dan file ekspor sensitif? | Owner / HR / Finance / IT |
| Q-16 | Bagaimana settlement, callback, refund, dan dispute flow dari penyedia QRIS/payment yang dipilih? | Finance / IT |
| Q-17 | Apakah registrasi/tanda tangan elektronik diterima sebagai prosedur resmi properti, dan apakah ada format dokumen tertentu yang wajib dipertahankan? | GM / Front Office |
| Q-18 | Mapping akun/kategori apa yang dibutuhkan untuk ekspor ke sistem akuntansi resmi, termasuk tax/service charge dan AR/AP? | Finance |

# Lampiran A — Glosarium Status Kamar

## Status Kamar Kosong (Vacant)

| Kode | Nama | Arti |
| --- | --- | --- |
| VC | Vacant Clean | Kamar kosong dengan housekeeping clean; belum tentu ready sampai inspeksi sesuai policy selesai. |
| VD | Vacant Dirty | Kamar kosong dengan housekeeping dirty dan tidak siap dijual. |
| VR | Vacant Ready | Kamar vacant, sellable, clean/inspected sesuai policy, dan siap dialokasikan. |

## Status Kamar Terisi (Occupied)

| Kode | Nama | Arti |
| --- | --- | --- |
| O / OC | Occupied / Occupied Clean | Kamar occupied; OC adalah housekeeping clean pada dimensi kebersihan. |
| OD | Occupied Dirty | Kamar occupied dengan housekeeping dirty atau belum serviced. |
| SO | Stay Over | Flag stay-over/perpanjangan; bukan pengganti occupancy status. |

## Status Khusus dan Perawatan

| Kode | Nama | Arti |
| --- | --- | --- |
| DND | Do Not Disturb | Service flag: tamu meminta privasi; occupancy tidak berubah. |
| OOO | Out of Order | Sellability flag: kamar diblokir dari penjualan karena kerusakan/perbaikan sesuai kebijakan. |
| DL | Double Lock | Security/service flag: double lock terdeteksi/dilaporkan; occupancy tidak berubah. |
| Comp* | Complimentary (atribut tarif) | Atribut rate/folio yang menandakan kamar diberikan gratis; bukan room status. Ditampilkan di sini hanya sebagai istilah legacy. |

# Lampiran B — Glosarium Istilah

| Istilah | Penjelasan |
| --- | --- |
| ADR | Average Daily Rate, rata-rata tarif kamar terjual dalam satu periode |
| RevPAR | Revenue per Available Room, pendapatan kamar dibagi jumlah kamar tersedia |
| Folio | Rekening tagihan tamu selama menginap yang menampung seluruh transaksi |
| Night Audit | Proses tutup buku harian yang membukukan sewa kamar dan memindahkan tanggal sistem |
| Work Order | Perintah kerja pemeliharaan bernomor unik yang dapat dilacak statusnya |
| Purchase Request | Permintaan pembelian yang diajukan department sebelum menjadi pesanan pembelian |
| Purchase Order | Pesanan pembelian resmi kepada pemasok |
| Stock Opname | Penghitungan fisik persediaan untuk dibandingkan dengan catatan sistem |
| KDS | Kitchen Display System, layar dapur yang menampilkan tiket pesanan |
| SOP | Standard Operating Procedure, prosedur baku tugas harian, mingguan, dan bulanan |
| Late Charge | Tagihan yang muncul setelah folio tamu ditutup |
| Service Charge | Biaya layanan yang ditambahkan pada tagihan dan sebagian besar didistribusikan kepada karyawan |
| Business Date | Tanggal operasi properti yang dipakai untuk posting harian dan berubah melalui night audit; dapat berbeda dari tanggal kalender saat transaksi terjadi. |
| Idempotency Key | Kunci unik untuk memastikan retry operasi yang sama tidak membuat transaksi kedua. |
| Correlation ID | Pengenal yang menghubungkan event, request, posting, dan log lintas modul/integrasi untuk troubleshooting dan rekonsiliasi. |
| Maker-Checker | Prinsip pemisahan pihak yang membuat transaksi dari pihak yang menyetujui pada tindakan berisiko. |
| Management P&L | Laporan laba-rugi operasional untuk pengambilan keputusan internal; bukan pengganti laporan akuntansi/statutori resmi. |
| RPO / RTO | Recovery Point Objective / Recovery Time Objective: target kehilangan data maksimum dan target waktu pemulihan layanan. |
| State Unknown (Payment) | Status ketika provider/settlement belum memberi kepastian final; tidak boleh diperlakukan sebagai Paid tanpa rekonsiliasi. |

# Lampiran C — Rekapitulasi Jumlah Kebutuhan Fungsional

| Modul | Kode | Jumlah Kebutuhan |
| --- | --- | --- |
| Dashboard Manajemen | FR-DSH | 22 |
| Front Office | FR-FO | 44 |
| Housekeeping | FR-HK | 24 |
| Laundry | FR-LDY | 12 |
| F&B Service | FR-FBS | 26 |
| F&B Product (Kitchen) | FR-KIT | 15 |
| Maintenance | FR-MTC | 15 |
| Inventory & Purchasing | FR-INV / FR-PUR | 25 |
| Human Resource | FR-HR | 29 |
| Finance | FR-FIN | 30 |
| Reporting & Analytics | FR-RPT | 11 |
| Guest Self-Service | FR-GST | 17 |
| Total | — | 270 |

# Lampiran D — Release Gate Ringkas

| Gate | Kriteria Minimum |
| --- | --- |
| Produk | Semua FR Wajib fase terkait lulus UAT atau waiver tertulis dengan owner risiko. |
| Data | Migrasi direkonsiliasi terhadap control total; tidak ada orphan/duplicate critical data. |
| Finance | Folio/POS/payment/settlement/night audit dapat direkonsiliasi end-to-end. |
| Security | RBAC, privilege, session, PII access/export, dan audit log lulus uji prioritas tinggi. |
| Reliability | Offline/sync, retry/idempotency, backup/restore, monitoring, alert, dan runbook terbukti. |
| Operations | Training, SOP, fallback manual, support roster, escalation path, dan rollback window tersedia. |
| Go/No-Go | Product Owner, Owner/GM, Tech Lead, dan owner area kritis menyetujui go-live. |

— Akhir dokumen —
