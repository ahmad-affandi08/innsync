# Functional Requirements Catalog

| ID | Module | Priority | Requirement |
| --- | --- | --- | --- |
| FR-DSH-001 | Dashboard Manajemen | Wajib | Menampilkan kartu okupansi hari berjalan: jumlah kamar terisi, jumlah kamar tersedia, jumlah tamu menginap, kedatangan hari ini, keberangkatan hari ini, dan reservasi masuk. Angka bersumber dari Front Office dan Housekeeping. |
| FR-DSH-002 | Dashboard Manajemen | Wajib | Menampilkan room board dengan dimensi status yang terpisah: occupancy (vacant/occupied), housekeeping (dirty/clean/inspected), sellability (sellable/OOO/OOS), serta service flag seperti DND/Double Lock. Complimentary ditampilkan sebagai atribut tarif/folio, bukan status kebersihan kamar. |
| FR-DSH-003 | Dashboard Manajemen | Wajib | Papan kamar bersifat template: administrator dapat menambah, mengubah, menonaktifkan kamar, menetapkan tipe, lantai, gedung, dan kapasitas tanpa bantuan pengembang. |
| FR-DSH-004 | Dashboard Manajemen | Wajib | Menampilkan pendapatan hari berjalan per outlet (Kamar, Restoran, Bar, Spa, Gift Shop, dan outlet tambahan yang dibuat pengguna) beserta total dan perbandingan terhadap hari, minggu, serta bulan sebelumnya. |
| FR-DSH-005 | Dashboard Manajemen | Wajib | Daftar outlet bersifat dapat diperluas; penambahan outlet baru otomatis muncul sebagai kolom pendapatan dan kategori pada laporan. |
| FR-DSH-006 | Dashboard Manajemen | Wajib | Menampilkan ringkasan pengeluaran: pembayaran kepada pemasok dan vendor yang telah dibayar, hutang berjalan, serta daftar jatuh tempo dalam 7 dan 30 hari ke depan. |
| FR-DSH-007 | Dashboard Manajemen | Wajib | Menampilkan peringatan stok minimum per department (Bar, Kitchen, Housekeeping, Maintenance, Galley, Reception) berdasarkan kartu stok dan hasil stock opname. |
| FR-DSH-008 | Dashboard Manajemen | Wajib | Menampilkan ringkasan kepegawaian hari berjalan: jumlah staf bertugas per shift per department, staf libur, staf ijin dengan keterangan, dan staf tanpa keterangan (alpha). |
| FR-DSH-009 | Dashboard Manajemen | Wajib | Menampilkan ringkasan pekerjaan pemeliharaan: work order berjalan, selesai hari ini, melewati batas waktu, dan kamar berstatus Out of Order. |
| FR-DSH-010 | Dashboard Manajemen | Sebaiknya | Menampilkan performa produk: sepuluh menu terlaris dan paling tidak laku, serta performa tipe kamar berdasarkan okupansi dan ADR pada periode terpilih. |
| FR-DSH-011 | Dashboard Manajemen | Sebaiknya | Menampilkan distribusi jam transaksi per outlet dalam bentuk grafik batang per jam untuk membantu penjadwalan staf. |
| FR-DSH-012 | Dashboard Manajemen | Sebaiknya | Menampilkan heatmap kedatangan tamu (check-in) berdasarkan jam dan hari dalam seminggu. |
| FR-DSH-013 | Dashboard Manajemen | Wajib | Menampilkan lini masa kewajiban pajak: pajak kamar, pajak restoran dan outlet lain, nilai terkumpul berjalan, tanggal jatuh tempo pelaporan, dan status pelaporan. |
| FR-DSH-014 | Dashboard Manajemen | Wajib | Menampilkan akumulasi service charge yang terkumpul dari kamar dan outlet beserta estimasi porsi yang akan didistribusikan kepada karyawan. |
| FR-DSH-015 | Dashboard Manajemen | Wajib | Menyediakan penyaring periode (hari ini, kemarin, 7 hari, bulan berjalan, rentang khusus) yang berlaku serentak pada seluruh kartu. |
| FR-DSH-016 | Dashboard Manajemen | Wajib | Setiap kartu dapat diklik untuk menelusuri hingga daftar transaksi atau dokumen sumbernya. |
| FR-DSH-017 | Dashboard Manajemen | Bisa | Susunan kartu dapat diatur per pengguna (urutan dan tampil/sembunyi) dan tersimpan pada profil pengguna. |
| FR-DSH-018 | Dashboard Manajemen | Sebaiknya | Data diperbarui otomatis paling lambat setiap 60 detik tanpa memuat ulang halaman, dengan penanda waktu pembaruan terakhir. |
| FR-DSH-019 | Dashboard Manajemen | Bisa | Tersedia mode layar televisi (tampilan besar tanpa navigasi) untuk dipasang di ruang manajemen. |
| FR-DSH-020 | Dashboard Manajemen | Wajib | Menyediakan pusat exception/alert untuk kondisi yang membutuhkan tindakan: reservasi berpotensi oversold, folio belum settle, pembayaran berstatus unknown, stok negatif atau kritis, work order lewat SLA, dan kegagalan sinkronisasi. |
| FR-DSH-021 | Dashboard Manajemen | Wajib | Setiap KPI menampilkan definisi, business date/periode, waktu data terakhir diperbarui, serta drill-down ke data sumber agar tidak terjadi perbedaan interpretasi antar department. |
| FR-DSH-022 | Dashboard Manajemen | Wajib | Dashboard menerapkan cakupan data berdasarkan property, outlet, department, dan role; pengguna hanya melihat angka yang diizinkan tanpa mengubah sumber data. |
| FR-FO-001 | Front Office | Wajib | Menampilkan rak kamar interaktif yang tersambung dengan data okupansi dan status kamar; klik pada nomor kamar membuka data tamu atau formulir check-in. |
| FR-FO-002 | Front Office | Wajib | Menyediakan kalender ketersediaan per tipe kamar dengan horizon minimal 365 hari dan dapat dikonfigurasi, lengkap dengan jumlah kamar tersisa, allotment/hold, dan penanda pembatasan penjualan per tanggal. |
| FR-FO-003 | Front Office | Wajib | Membuat reservasi dengan sumber pemesanan (langsung, telepon, OTA, korporat, walk-in), status (tentatif, terkonfirmasi, dijamin deposit), dan catatan khusus. |
| FR-FO-004 | Front Office | Wajib | Menandai reservasi yang tidak datang (no-show) dan pembatalan dengan alasan, serta menerapkan aturan denda bila dikonfigurasi. |
| FR-FO-005 | Front Office | Wajib | Menandai kamar sebagai Out of Order atau Out of Service dengan rentang tanggal sehingga tidak muncul sebagai kamar yang dapat dijual. |
| FR-FO-006 | Front Office | Sebaiknya | Mendukung pemesanan grup sederhana: satu pemesan dengan beberapa kamar, satu master folio, dan opsi pemisahan tagihan per kamar. |
| FR-FO-007 | Front Office | Wajib | Mengelola inventory kamar per tipe dengan aturan overbooking yang dapat dikonfigurasi. Sistem tidak boleh menjual melebihi batas yang disetujui dan wajib memperingatkan pengguna sebelum menerima reservasi yang berpotensi oversold. |
| FR-FO-008 | Front Office | Wajib | Mengelola rate plan, seasonal rate, corporate rate, package, inclusions, minimum stay, closed-to-arrival/departure, serta tanggal efektif tanpa mengubah histori reservasi lama. |
| FR-FO-009 | Front Office | Wajib | Mendukung kebijakan guarantee, deposit due date, cancellation, no-show, dan penalty per rate plan/sumber reservasi serta menyimpan policy snapshot pada saat reservasi dibuat. |
| FR-FO-010 | Front Office | Wajib | Formulir check-in memuat: nama tamu, kewarganegaraan, jenis dan nomor identitas (paspor atau KTP), tanggal berlaku identitas, nomor visa bila diperlukan, jumlah tamu (dewasa dan anak), serta alamat sesuai identitas. |
| FR-FO-011 | Front Office | Wajib | Sistem mengunggah dan menampilkan foto identitas yang diambil langsung dari kamera perangkat resepsionis atau tablet, dan melampirkannya pada data tamu. |
| FR-FO-012 | Front Office | Wajib | Pemilihan lama menginap menampilkan blok tanggal menginap secara visual serta menghitung otomatis harga per malam sesuai tarif kamar yang bersangkutan. |
| FR-FO-013 | Front Office | Wajib | Harga kamar dapat diubah kapan pun oleh pengguna berwenang; setiap perubahan mencatat nilai lama, nilai baru, alasan, dan pelaku. Perubahan melebihi ambang diskon yang ditetapkan memerlukan persetujuan Manager on Duty. |
| FR-FO-014 | Front Office | Sebaiknya | Sistem memperingatkan bila identitas tamu telah kedaluwarsa atau akan kedaluwarsa selama masa menginap. |
| FR-FO-015 | Front Office | Sebaiknya | Sistem mendeteksi tamu berulang berdasarkan nomor identitas dan mengisi otomatis data profil beserta riwayat menginap dan preferensinya. |
| FR-FO-016 | Front Office | Wajib | Setelah check-in, status kamar otomatis berubah menjadi terisi dan seluruh permintaan tamu yang tercatat muncul pada kartu kamar tersebut. |
| FR-FO-017 | Front Office | Sebaiknya | Sistem mencetak atau mengirim kartu registrasi elektronik untuk ditandatangani tamu, termasuk tanda tangan digital pada tablet. |
| FR-FO-018 | Front Office | Wajib | Mendukung perpindahan kamar (room move) dengan pemindahan seluruh saldo folio dan pencatatan alasan. |
| FR-FO-019 | Front Office | Wajib | Mendukung perpanjangan masa menginap (Stay Over) dan check-out dipercepat dengan penyesuaian tagihan otomatis. |
| FR-FO-020 | Front Office | Wajib | Setiap stay memiliki minimal satu folio dan dapat memiliki beberapa folio/window untuk routing tagihan. Folio menampung room charge, pajak, service charge, charge outlet, koreksi, dan pembayaran secara terurut dan dapat ditelusuri. |
| FR-FO-021 | Front Office | Wajib | Mencetak rincian tagihan (bill print out) yang menampilkan seluruh transaksi terperinci per outlet dan per tanggal. |
| FR-FO-022 | Front Office | Sebaiknya | Mendukung pemisahan tagihan (split bill) menjadi beberapa folio, misalnya folio perusahaan dan folio pribadi tamu. |
| FR-FO-023 | Front Office | Sebaiknya | Mendukung pemindahan item tagihan antar folio atau antar kamar dengan pencatatan alasan. |
| FR-FO-024 | Front Office | Wajib | Menerima pembayaran melalui tunai, QRIS, kartu melalui EDC, transfer bank, dan pembayaran daring dari kanal pemesanan. |
| FR-FO-025 | Front Office | Wajib | Mencatat deposit di muka dan mengurangkannya secara otomatis pada saat penyelesaian tagihan, termasuk pengembalian sisa deposit. |
| FR-FO-026 | Front Office | Bisa | Mencatat pembayaran dengan mata uang asing beserta kurs yang berlaku bila fitur diaktifkan. |
| FR-FO-027 | Front Office | Wajib | Membukukan pendapatan kamar secara otomatis ke modul Finance beserta pemisahan nilai dasar, pajak, dan service charge. |
| FR-FO-028 | Front Office | Wajib | Menjalankan night audit berdasarkan business date properti: melakukan pre-check transaksi tertunda, membukukan room charge, mengunci hari yang selesai, memindahkan business date, dan menghasilkan laporan. Proses harus aman dijalankan ulang tanpa posting ganda. |
| FR-FO-029 | Front Office | Wajib | Pembayaran, refund, reversal, dan koreksi folio memiliki status dan referensi yang jelas. Refund atau reversal setelah settlement memerlukan otorisasi, alasan, jejak audit, dan tidak boleh menghapus transaksi asal. |
| FR-FO-030 | Front Office | Wajib | Mencatat permintaan tamu (guest request) dengan template bebas isi dan meneruskannya otomatis ke Housekeeping, Restoran, atau Maintenance sesuai kategori, lengkap dengan status penyelesaian. |
| FR-FO-031 | Front Office | Wajib | Mencatat komentar dan keluhan tamu beserta tingkat keparahan, penanggung jawab tindak lanjut, dan bukti penyelesaian. |
| FR-FO-032 | Front Office | Wajib | Menampilkan SOP tugas harian, mingguan, dan bulanan resepsionis pada ponsel atau tablet, dengan isi template yang disusun oleh manajemen. |
| FR-FO-033 | Front Office | Wajib | Staf menandai tugas selesai; persentase penyelesaian dikirim otomatis ke modul Human Resource sebagai komponen penilaian kinerja. |
| FR-FO-034 | Front Office | Sebaiknya | Menyediakan buku serah terima shift (log book) yang wajib diisi pada akhir shift dan dibaca pada awal shift berikutnya. |
| FR-FO-035 | Front Office | Sebaiknya | Mengelola profil perusahaan/agen, credit limit, billing instruction, dan routing charge untuk tamu korporat tanpa mencampur tagihan pribadi. |
| FR-FO-036 | Front Office | Wajib | Membuka dan menutup shift kasir Front Office dengan opening float, penerimaan per metode, cash drop, saldo sistem, kas fisik, dan selisih beralasan. |
| FR-FO-037 | Front Office | Sebaiknya | Mengelola early check-in, late check-out, day-use, dan biaya terkait berdasarkan kebijakan/rate plan yang dapat dikonfigurasi. |
| FR-FO-038 | Front Office | Wajib | Late charge setelah folio ditutup harus menggunakan alur khusus yang menaut ke stay/folio asal dan tidak mengubah laporan hari lama tanpa adjustment. |
| FR-FO-039 | Front Office | Wajib | Koreksi nama tamu, identitas, room move, dan routing finansial setelah check-in disimpan sebagai perubahan ter-audit; perubahan data kritis dapat memerlukan approval. |
| FR-FO-040 | Front Office | Wajib | Menerbitkan laporan registrasi tamu harian sesuai kolom di atas dengan penyaring tanggal dan kewarganegaraan. |
| FR-FO-041 | Front Office | Wajib | Menerbitkan berkas laporan tamu warga negara asing dalam format yang siap disampaikan kepada instansi terkait. |
| FR-FO-042 | Front Office | Wajib | Menerbitkan laporan pendapatan kamar per metode pembayaran: tunai, QRIS, transfer bank, kartu, dan pembayaran kanal daring. |
| FR-FO-043 | Front Office | Wajib | Menerbitkan laporan kedatangan, keberangkatan, dan tamu menginap untuk keperluan operasional harian. |
| FR-FO-044 | Front Office | Wajib | Menerbitkan laporan okupansi, ADR, dan RevPAR per hari, bulan, dan tahun berjalan. |
| FR-HK-001 | Housekeeping | Wajib | Menampilkan papan status kamar yang sama dengan dashboard dan Front Office; setiap perubahan berlaku serentak untuk seluruh modul. |
| FR-HK-002 | Housekeeping | Wajib | Supervisor membagi kamar kepada room attendant; setiap staf menerima tautan pribadi di ponsel berisi daftar kamar dan tugasnya. |
| FR-HK-003 | Housekeeping | Sebaiknya | Sistem menyusun urutan prioritas pembersihan secara otomatis: kamar keberangkatan, kamar kotor kosong, permintaan tamu, lalu kamar menginap. |
| FR-HK-004 | Housekeeping | Wajib | Room attendant mengubah status kamar langsung dari ponsel dengan maksimal tiga ketukan, termasuk penanda mulai dan selesai membersihkan untuk mengukur durasi. |
| FR-HK-005 | Housekeeping | Wajib | Menyediakan daftar periksa SOP tugas harian, mingguan, dan bulanan per kamar dan per area umum, disusun oleh manajemen sebagai template. |
| FR-HK-006 | Housekeeping | Sebaiknya | Daftar periksa dapat mewajibkan lampiran foto pada butir tertentu sebagai bukti pengerjaan. |
| FR-HK-007 | Housekeeping | Wajib | Supervisor melakukan inspeksi kamar dan menyetujui perubahan status menjadi siap dijual; kamar tanpa inspeksi dapat dikonfigurasi tetap masuk status bersih namun belum siap. |
| FR-HK-008 | Housekeeping | Wajib | Room attendant membuat laporan kerusakan dengan cara memilih kamar atau lokasi, menulis keterangan, dan melampirkan foto; laporan langsung menjadi work order pada modul Maintenance. |
| FR-HK-009 | Housekeeping | Wajib | Mencatat pemakaian linen dan perlengkapan: sprei, handuk, sarung bantal, sabun, dan amenitas lain, per kamar dan per hari. |
| FR-HK-010 | Housekeeping | Wajib | Mencatat sirkulasi linen mengikuti alur gudang ke luar gudang, ke laundry, dan kembali ke gudang; setiap perpindahan wajib diinput saat pengambilan maupun penyimpanan. |
| FR-HK-011 | Housekeeping | Sebaiknya | Sistem menghitung selisih linen yang tidak kembali dan menandainya sebagai kehilangan atau kerusakan untuk ditindaklanjuti. |
| FR-HK-012 | Housekeeping | Sebaiknya | Mencatat temuan barang tertinggal (lost and found) dengan foto, lokasi, tanggal, penemu, dan status pengembalian. |
| FR-HK-013 | Housekeeping | Wajib | Menerima permintaan tamu dari Front Office beserta batas waktu penyelesaian dan menandai status penyelesaiannya. |
| FR-HK-014 | Housekeeping | Wajib | Mengajukan permintaan pembelian alat dan bahan ke modul Purchasing langsung dari modul Housekeeping. |
| FR-HK-015 | Housekeeping | Sebaiknya | Menerbitkan laporan produktivitas: jumlah kamar dibersihkan per staf, rata-rata durasi per kamar, dan persentase penyelesaian SOP. |
| FR-HK-016 | Housekeeping | Wajib | Mendeteksi room status discrepancy antara Front Office dan Housekeeping (misalnya kamar menurut FO vacant tetapi menurut HK occupied/berisi barang) dan mewajibkan resolusi supervisor sebelum kamar dijual. |
| FR-HK-017 | Housekeeping | Wajib | Mencatat service flag DND, refused service, make-up-room, dan privacy request dengan waktu mulai/selesai tanpa mengubah occupancy status kamar. |
| FR-HK-018 | Housekeeping | Wajib | Inspeksi supervisor dapat menghasilkan status rework dengan daftar temuan; kamar hanya menjadi ready setelah seluruh temuan wajib diselesaikan atau di-waive oleh peran berwenang. |
| FR-HK-019 | Housekeeping | Sebaiknya | Mengelola par level linen dan amenitas per tipe kamar/area sehingga kebutuhan replenishment dan selisih konsumsi dapat dihitung per shift. |
| FR-HK-020 | Housekeeping | Wajib | Staf Housekeeping memindai barcode kantong laundry lalu memilih kamar untuk membuka order guest laundry. |
| FR-HK-021 | Housekeeping | Wajib | Staf mencatat rincian per item sebelum dikirim ke laundry: jenis pakaian (baju, celana, dan seterusnya), merek atau tanpa merek, jumlah, catatan kondisi, tanggal pengambilan, dan tanggal janji kembali kepada tamu. |
| FR-HK-022 | Housekeeping | Wajib | Sistem mengirim order tersebut ke modul Laundry lengkap dengan nomor kamar dan jumlah item, dalam bentuk daftar per item sehingga petugas laundry cukup menandai centang. |
| FR-HK-023 | Housekeeping | Wajib | Nilai tagihan laundry otomatis dibentuk berdasarkan daftar harga per item dan diposkan ke folio kamar. |
| FR-HK-024 | Housekeeping | Wajib | Setelah laundry selesai, Housekeeping menerima notifikasi untuk mengantarkan kembali ke kamar dan menutup order dengan bukti penerimaan. |
| FR-LDY-001 | Laundry | Wajib | Menerima daftar order guest laundry dari Housekeeping, dikelompokkan per nomor kamar beserta jumlah item. |
| FR-LDY-002 | Laundry | Wajib | Petugas memverifikasi item yang diterima dengan cara mencentang daftar; selisih jumlah wajib dicatat sebagai temuan sebelum pengerjaan dimulai. |
| FR-LDY-003 | Laundry | Wajib | Mengubah status pengerjaan mengikuti tahapan: diterima, dicuci, dikeringkan, disetrika, siap, dan dikembalikan ke Housekeeping. |
| FR-LDY-004 | Laundry | Wajib | Menandai order selesai sehingga status berubah menjadi selesai dan Housekeeping menerima pemberitahuan untuk pengantaran ke kamar. |
| FR-LDY-005 | Laundry | Sebaiknya | Mencatat perlakuan khusus: cuci kering, noda membandel, setrika saja, dan layanan kilat dengan tarif berbeda. |
| FR-LDY-006 | Laundry | Sebaiknya | Mencatat klaim kerusakan atau kehilangan item tamu beserta foto, nilai penggantian, dan persetujuan Manager on Duty. |
| FR-LDY-007 | Laundry | Wajib | Mengelola linen hotel: penerimaan dari Housekeeping, jumlah dicuci, jumlah rusak atau afkir, dan pengembalian ke gudang. |
| FR-LDY-008 | Laundry | Sebaiknya | Mencatat pemakaian bahan kimia dan perlengkapan laundry sehingga terhubung dengan kartu stok gudang. |
| FR-LDY-009 | Laundry | Wajib | Mengajukan permintaan pembelian bahan dan alat ke modul Purchasing. |
| FR-LDY-010 | Laundry | Sebaiknya | Menerbitkan laporan volume pengerjaan harian, waktu penyelesaian rata-rata, biaya per kilogram, serta pendapatan guest laundry. |
| FR-LDY-011 | Laundry | Wajib | Setiap order memiliki promised time/SLA; order express dan order melewati janji selesai diberi prioritas serta notifikasi eskalasi. |
| FR-LDY-012 | Laundry | Wajib | Sistem mencegah penutupan stay bila guest laundry masih berstatus aktif, kecuali diubah menjadi late charge/claim melalui persetujuan yang tercatat. |
| FR-FBS-001 | F&B Service | Wajib | Menampilkan denah meja per outlet dengan status kosong, terisi, dan sudah memesan; kasir dapat membuka bill dari meja atau dari nomor kamar. |
| FR-FBS-002 | F&B Service | Wajib | Mengambil pesanan dengan katalog menu bergambar, kategori, varian, catatan khusus, dan jumlah porsi. |
| FR-FBS-003 | F&B Service | Wajib | Mengirim pesanan ke layar dapur dan bar sesuai kategori item, serta mencetak tiket pada printer masing-masing bila diperlukan. |
| FR-FBS-004 | F&B Service | Sebaiknya | Mendukung pemisahan bill, penggabungan bill, dan pemindahan pesanan antar meja. |
| FR-FBS-005 | F&B Service | Wajib | Void item dan pembatalan bill hanya dapat dilakukan dengan alasan dan persetujuan penyelia; seluruh tindakan tercatat pada jejak audit. |
| FR-FBS-006 | F&B Service | Wajib | Diskon dan pemberian gratis (complimentary) memerlukan alasan dan persetujuan sesuai ambang yang dikonfigurasi. |
| FR-FBS-007 | F&B Service | Wajib | Menerima pembayaran tunai, QRIS, kartu melalui EDC, dan pembebanan ke kamar. Pembebanan ke kamar wajib memvalidasi bahwa kamar berstatus terisi dan mencocokkan nama tamu. |
| FR-FBS-008 | F&B Service | Wajib | Menghitung pajak dan service charge secara otomatis sesuai konfigurasi per outlet dan menampilkannya terpisah pada struk. |
| FR-FBS-009 | F&B Service | Wajib | Membuka dan menutup shift kasir dengan penghitungan kas fisik, kas sistem, serta pencatatan selisih beserta alasan. |
| FR-FBS-010 | F&B Service | Wajib | POS tetap dapat mencatat transaksi saat jaringan terputus melalui antrean lokal terenkripsi. Setiap transaksi memiliki idempotency key dan status sinkronisasi sehingga pemulihan jaringan tidak menghasilkan bill, pembayaran, atau pengurangan stok ganda. |
| FR-FBS-011 | F&B Service | Wajib | Mendukung modifier/add-on, tingkat kematangan, pilihan varian, dan catatan khusus yang dapat memengaruhi harga dan resep tanpa membuat item menu duplikat. |
| FR-FBS-012 | F&B Service | Wajib | Perubahan bill oleh beberapa perangkat menggunakan kontrol konkurensi; sistem mencegah lost update dan menampilkan konflik bila bill telah berubah di perangkat lain. |
| FR-FBS-013 | F&B Service | Wajib | Pembayaran QRIS/daring memiliki state initiated, pending, paid, failed, expired, unknown, dan refunded. Status unknown tidak boleh dianggap lunas sebelum rekonsiliasi atau callback valid diterima. |
| FR-FBS-014 | F&B Service | Wajib | Refund, void setelah pembayaran, dan reprint struk memerlukan hak akses sesuai kebijakan, alasan, serta referensi transaksi awal pada audit trail. |
| FR-FBS-015 | F&B Service | Sebaiknya | Mendukung price list dan jadwal harga per outlet/channel/waktu, termasuk promo terjadwal, tanpa mengubah histori harga transaksi yang sudah ditutup. |
| FR-FBS-020 | F&B Service | Wajib | Petugas memeriksa mini bar di kamar tamu dengan memindai barcode kamar lalu memilih menu mini bar. |
| FR-FBS-021 | F&B Service | Wajib | Jumlah minuman dan makanan yang dikonsumsi tamu diinput di tempat dan otomatis terkirim ke kasir serta folio kamar tanpa input ulang. |
| FR-FBS-022 | F&B Service | Wajib | Sistem menghasilkan daftar jumlah item yang harus diisi ulang per kamar untuk shift berikutnya. |
| FR-FBS-023 | F&B Service | Wajib | Riwayat pengisian dan konsumsi mini bar tersimpan per kamar dan per petugas untuk keperluan audit. |
| FR-FBS-024 | F&B Service | Wajib | Mencatat pesanan room service dengan nomor kamar, waktu janji pengantaran, dan status pengantaran. |
| FR-FBS-025 | F&B Service | Sebaiknya | Sistem memblokir pembebanan mini bar setelah folio kamar ditutup dan mengarahkannya ke prosedur late charge. |
| FR-FBS-030 | F&B Service | Wajib | Mengelola persediaan outlet (bar dan gudang outlet) beserta permintaan barang ke gudang utama. |
| FR-FBS-031 | F&B Service | Wajib | Melakukan stock opname harian untuk minuman dan bahan bar dengan pencatatan selisih. |
| FR-FBS-032 | F&B Service | Wajib | Menampilkan SOP tugas harian, mingguan, dan bulanan outlet beserta persentase penyelesaian yang dikirim ke Human Resource. |
| FR-FBS-033 | F&B Service | Wajib | Membuat laporan kerusakan yang diteruskan ke modul Maintenance. |
| FR-FBS-034 | F&B Service | Wajib | Mengajukan permintaan pembelian alat dan bahan ke modul Purchasing. |
| FR-KIT-001 | F&B Product / Kitchen | Wajib | Menampilkan tiket pesanan dari POS pada layar dapur secara berurutan beserta waktu tunggu dan penanda keterlambatan. |
| FR-KIT-002 | F&B Product / Kitchen | Wajib | Mengubah status tiket menjadi diproses, siap, dan sudah diantar sehingga pelayan menerima pemberitahuan. |
| FR-KIT-003 | F&B Product / Kitchen | Wajib | Mengelola resep dan komposisi bahan (bill of material) berversi untuk setiap menu, termasuk yield, waste standar, satuan, dan tanggal efektif, sebagai dasar biaya bahan dan harga pokok. |
| FR-KIT-004 | F&B Product / Kitchen | Wajib | Mengurangi stok bahan secara otomatis berdasarkan versi resep yang berlaku setiap kali item menu diposting sebagai penjualan, tepat satu kali untuk setiap transaksi. |
| FR-KIT-005 | F&B Product / Kitchen | Wajib | Menandai menu yang habis sehingga otomatis tidak dapat dipesan dari POS maupun menu QR tamu. |
| FR-KIT-006 | F&B Product / Kitchen | Wajib | Mencatat pemakaian bahan, produksi persiapan, dan pembuangan bahan rusak (waste log) beserta alasan. |
| FR-KIT-007 | F&B Product / Kitchen | Wajib | Melakukan stock opname bahan dapur dan gudang kering dengan pencatatan selisih dan nilai kerugian. |
| FR-KIT-008 | F&B Product / Kitchen | Wajib | Menampilkan daftar periksa kebersihan, suhu penyimpanan, dan tugas harian, mingguan, serta bulanan dapur. |
| FR-KIT-009 | F&B Product / Kitchen | Sebaiknya | Mencatat tanggal kedaluwarsa dan nomor batch bahan sensitif dengan peringatan mendekati kedaluwarsa. |
| FR-KIT-010 | F&B Product / Kitchen | Wajib | Membuat laporan kerusakan peralatan yang diteruskan ke modul Maintenance. |
| FR-KIT-011 | F&B Product / Kitchen | Wajib | Mengajukan permintaan pembelian bahan dan peralatan ke modul Purchasing. |
| FR-KIT-012 | F&B Product / Kitchen | Sebaiknya | Menerbitkan laporan penjualan menu, rasio biaya bahan terhadap penjualan, dan analisis menu berdasarkan popularitas serta kontribusi margin. |
| FR-KIT-013 | F&B Product / Kitchen | Wajib | Setiap perubahan resep menghasilkan versi baru bertanggal efektif; transaksi lama selalu mereferensikan versi resep yang berlaku saat transaksi diposting. |
| FR-KIT-014 | F&B Product / Kitchen | Sebaiknya | Mendukung produksi/preparation batch (misalnya sauce, dough, stock) yang mengonsumsi bahan baku dan menghasilkan semi-finished goods beserta yield aktual. |
| FR-KIT-015 | F&B Product / Kitchen | Wajib | KDS menyediakan indikator koneksi dan antrean; bila layar atau jaringan bermasalah, tiket tetap tersimpan dan dapat dialihkan ke printer/fallback queue tanpa kehilangan order. |
| FR-MTC-001 | Maintenance / Engineering | Wajib | Menerima laporan kerusakan dari seluruh department dan mengubahnya menjadi work order bernomor unik dengan lokasi, kategori, dan foto. |
| FR-MTC-002 | Maintenance / Engineering | Wajib | Menetapkan prioritas (mendesak, tinggi, normal, rendah) dan batas waktu penyelesaian sesuai kesepakatan tingkat layanan. |
| FR-MTC-003 | Maintenance / Engineering | Wajib | Menugaskan work order kepada teknisi tertentu dan menampilkannya pada ponsel teknisi. |
| FR-MTC-004 | Maintenance / Engineering | Wajib | Mengikuti status pekerjaan: berjalan, selesai, dan belum selesai beserta alasan bila tertunda (menunggu suku cadang, menunggu vendor, atau menunggu akses kamar). |
| FR-MTC-005 | Maintenance / Engineering | Wajib | Foto hasil pekerjaan wajib dilampirkan sebelum work order dapat ditandai selesai; sistem menolak penutupan tanpa foto. |
| FR-MTC-006 | Maintenance / Engineering | Wajib | Menetapkan kamar menjadi Out of Order atau Out of Service beserta perkiraan tanggal selesai; status ini langsung memblokir penjualan kamar di Front Office. |
| FR-MTC-007 | Maintenance / Engineering | Sebaiknya | Mengelola daftar aset properti (mesin, peralatan, kendaraan) beserta nomor aset, tanggal perolehan, garansi, dan riwayat perbaikan. |
| FR-MTC-008 | Maintenance / Engineering | Sebaiknya | Menyusun jadwal pemeliharaan pencegahan berkala per aset dan menghasilkan work order secara otomatis pada tanggalnya. |
| FR-MTC-009 | Maintenance / Engineering | Sebaiknya | Mengelola persediaan suku cadang dan mencatat pemakaiannya pada setiap work order. |
| FR-MTC-010 | Maintenance / Engineering | Wajib | Mengajukan permintaan pembelian alat dan suku cadang ke modul Purchasing, termasuk pekerjaan yang dikerjakan vendor luar. |
| FR-MTC-011 | Maintenance / Engineering | Wajib | Menampilkan SOP tugas harian, mingguan, dan bulanan teknik seperti pemeriksaan genset, pompa, dan pendingin ruangan. |
| FR-MTC-012 | Maintenance / Engineering | Wajib | Menerbitkan laporan: work order per status dan department pelapor, waktu penyelesaian rata-rata, kerusakan berulang per kamar, biaya perbaikan, dan hari kamar tidak dapat dijual. |
| FR-MTC-013 | Maintenance / Engineering | Wajib | Work order yang mendekati atau melewati SLA menghasilkan eskalasi ke supervisor/MOD sesuai matriks prioritas dan shift. |
| FR-MTC-014 | Maintenance / Engineering | Sebaiknya | Mencatat meter reading/usage counter untuk aset yang membutuhkan preventive maintenance berdasarkan jam operasi, kilometer, atau siklus selain kalender. |
| FR-MTC-015 | Maintenance / Engineering | Sebaiknya | Pekerjaan vendor eksternal memiliki quotation, approval, jadwal, biaya aktual, bukti pekerjaan, dan relasi ke aset/work order. |
| FR-INV-001 | Inventory | Wajib | Mengelola data induk barang: kode, nama, kategori, satuan dasar, konversi satuan (dus ke botol, kilogram ke gram), dan department pemilik. |
| FR-INV-002 | Inventory | Wajib | Mengelola beberapa lokasi penyimpanan: gudang utama, gudang bar, gudang dapur, gudang housekeeping, gudang teknik, dan galley. |
| FR-INV-003 | Inventory | Wajib | Menetapkan stok minimum dan stok maksimum per barang per lokasi; pelanggaran batas minimum otomatis muncul sebagai peringatan pada dashboard. |
| FR-INV-004 | Inventory | Wajib | Mencatat mutasi stok otomatis dari penjualan POS, pemakaian dapur, pemakaian housekeeping, dan pemakaian teknik. |
| FR-INV-005 | Inventory | Wajib | Melakukan pemindahan barang antar gudang dengan dokumen serah terima dan konfirmasi penerima. |
| FR-INV-006 | Inventory | Wajib | Melakukan stock opname terjadwal maupun mendadak, membandingkan stok fisik dengan stok sistem, dan menghasilkan berita acara selisih beserta nilai kerugian. |
| FR-INV-007 | Inventory | Sebaiknya | Menghitung nilai persediaan menggunakan metode rata-rata bergerak dan menyajikannya sebagai laporan nilai persediaan per tanggal. |
| FR-INV-008 | Inventory | Sebaiknya | Mengelola tanggal kedaluwarsa dan nomor batch untuk barang konsumsi. |
| FR-INV-009 | Inventory | Wajib | Konversi satuan bersifat berversi dan tidak boleh mengubah histori transaksi; setiap mutasi menyimpan kuantitas satuan transaksi dan ekuivalen satuan dasar. |
| FR-INV-010 | Inventory | Wajib | Stock adjustment, write-off, dan pembukaan stok negatif memerlukan reason code dan otorisasi sesuai threshold. Kebijakan stok negatif dapat diblokir per kategori/lokasi. |
| FR-INV-011 | Inventory | Wajib | Mendukung retur ke pemasok dan retur antar gudang dengan dokumen referensi sehingga stok, hutang/kredit, dan histori barang tetap dapat direkonsiliasi. |
| FR-INV-012 | Inventory | Wajib | Stock opname menggunakan snapshot waktu mulai; mutasi selama opname tetap tercatat dan sistem menghitung expected quantity yang konsisten untuk mencegah selisih semu. |
| FR-PUR-001 | Purchasing | Wajib | Setiap department mengajukan permintaan pembelian (purchase request) berisi barang, jumlah, alasan, dan tingkat urgensi. |
| FR-PUR-002 | Purchasing | Wajib | Permintaan melewati alur persetujuan berjenjang yang dapat dikonfigurasi berdasarkan nilai nominal. |
| FR-PUR-003 | Purchasing | Wajib | Purchasing menggabungkan permintaan yang disetujui menjadi pesanan pembelian (purchase order) kepada pemasok terpilih. |
| FR-PUR-004 | Purchasing | Wajib | Mengelola data pemasok dan vendor secara terpisah dari data barang, meliputi kontak, syarat pembayaran, daftar harga, dan riwayat penilaian. |
| FR-PUR-005 | Purchasing | Sebaiknya | Membandingkan penawaran harga dari beberapa pemasok untuk barang yang sama sebelum penerbitan pesanan. |
| FR-PUR-006 | Purchasing | Wajib | Mencatat penerimaan barang beserta jumlah diterima, jumlah ditolak, kondisi, dan foto; penerimaan sebagian didukung. |
| FR-PUR-007 | Purchasing | Wajib | Mencocokkan tiga dokumen: pesanan pembelian, bukti penerimaan barang, dan faktur pemasok; selisih ditandai untuk ditindaklanjuti. |
| FR-PUR-008 | Purchasing | Wajib | Penerimaan barang otomatis menambah stok gudang tujuan dan membentuk hutang kepada pemasok di modul Finance. |
| FR-PUR-009 | Purchasing | Wajib | Menerbitkan laporan pembelian per department dalam bentuk jumlah barang maupun nilai uang, per periode dan per pemasok. |
| FR-PUR-010 | Purchasing | Sebaiknya | Menerbitkan laporan penerimaan barang dan laporan ketepatan waktu pengiriman pemasok. |
| FR-PUR-011 | Purchasing | Wajib | Perubahan PO yang sudah disetujui menghasilkan revisi bernomor dan memerlukan persetujuan ulang bila mengubah nilai, pemasok, atau kuantitas di atas toleransi. |
| FR-PUR-012 | Purchasing | Wajib | Faktur pemasok mencatat nomor unik pemasok, tanggal, pajak, dan dokumen pendukung; sistem mencegah duplikasi invoice dan menjaga relasi ke PO serta penerimaan barang. |
| FR-PUR-013 | Purchasing | Sebaiknya | Permintaan dan PO menampilkan sisa budget department; kebijakan dapat berupa warning atau hard block sesuai threshold yang dikonfigurasi. |
| FR-HR-001 | Human Resource | Wajib | Mengelola data induk karyawan: nomor induk, nama, department, jabatan, tanggal bergabung, jenis kontrak, masa berlaku kontrak, atasan langsung, dan status aktif. |
| FR-HR-002 | Human Resource | Wajib | Menyimpan berkas kepegawaian: kontrak kerja, identitas, sertifikat keahlian, dan hasil pemeriksaan wajib, beserta tanggal berlaku. |
| FR-HR-003 | Human Resource | Sebaiknya | Memberi peringatan otomatis menjelang berakhirnya kontrak, sertifikat, atau dokumen wajib lainnya. |
| FR-HR-004 | Human Resource | Sebaiknya | Menyediakan portal mandiri karyawan untuk melihat jadwal, sisa cuti, riwayat kehadiran, dan slip pendapatan. |
| FR-HR-005 | Human Resource | Wajib | Proses offboarding menonaktifkan akses, menutup assignment/shift mendatang, mencatat pengembalian aset, dan mempertahankan histori transaksi karyawan tanpa menghapus data historis. |
| FR-HR-010 | Human Resource | Wajib | Menyusun roster shift per department dengan pola shift yang dapat dikonfigurasi (pagi, siang, malam, split, libur) untuk periode mingguan dan bulanan. |
| FR-HR-011 | Human Resource | Sebaiknya | Sistem memperingatkan bila jumlah staf pada suatu shift berada di bawah kebutuhan minimum department. |
| FR-HR-012 | Human Resource | Wajib | Karyawan melakukan presensi masuk dan pulang melalui ponsel dengan verifikasi lokasi (geofence) dan swafoto, atau melalui perangkat presensi di properti. |
| FR-HR-013 | Human Resource | Wajib | Sistem menghitung keterlambatan, pulang lebih awal, jam lembur, dan ketidakhadiran tanpa keterangan secara otomatis terhadap jadwal. |
| FR-HR-014 | Human Resource | Wajib | Jumlah staf bertugas per shift per department dikirim ke dashboard secara langsung. |
| FR-HR-015 | Human Resource | Wajib | Mengelola pengajuan cuti, ijin, dan sakit dengan alur persetujuan berjenjang serta lampiran bukti; hasilnya otomatis mengubah roster. |
| FR-HR-016 | Human Resource | Wajib | Mengelola saldo cuti tahunan, cuti yang sudah diambil, dan sisa cuti per karyawan. |
| FR-HR-017 | Human Resource | Bisa | Mendukung pertukaran shift antar karyawan dengan persetujuan penyelia. |
| FR-HR-018 | Human Resource | Wajib | Mencatat lembur yang telah disetujui sebelumnya dan membedakannya dari kelebihan jam kerja yang tidak disetujui. |
| FR-HR-019 | Human Resource | Wajib | Koreksi presensi setelah periode berjalan memerlukan alasan dan approval; nilai sebelum/sesudah disimpan dan perubahan otomatis memicu hitung ulang komponen terkait. |
| FR-HR-020 | Human Resource | Wajib | Menerima persentase penyelesaian SOP tugas harian, mingguan, dan bulanan dari seluruh modul operasional sebagai komponen penilaian kinerja objektif. |
| FR-HR-021 | Human Resource | Sebaiknya | Menampilkan papan kinerja per karyawan: kehadiran, ketepatan waktu, penyelesaian tugas, jumlah komplain tamu terkait, dan produktivitas department. |
| FR-HR-022 | Human Resource | Sebaiknya | Melakukan penilaian kinerja berkala dengan formulir yang dapat dikonfigurasi dan tanda tangan digital atasan serta karyawan. |
| FR-HR-023 | Human Resource | Sebaiknya | Mencatat teguran, surat peringatan, dan penghargaan karyawan beserta lampiran dan masa berlaku. |
| FR-HR-024 | Human Resource | Bisa | Menyediakan papan pengumuman internal dan distribusi kebijakan yang wajib dibaca dengan pencatatan konfirmasi. |
| FR-HR-030 | Human Resource | Wajib | Mengelola komponen pendapatan karyawan: gaji pokok, tunjangan tetap, tunjangan tidak tetap, uang makan, dan uang transport. |
| FR-HR-031 | Human Resource | Wajib | Menghitung usulan penggajian periodik berdasarkan kehadiran, lembur, potongan keterlambatan, dan ketidakhadiran, lalu meneruskannya ke modul Finance untuk verifikasi dan pembayaran. |
| FR-HR-032 | Human Resource | Wajib | Menghitung distribusi service charge yang terkumpul dari kamar dan outlet berdasarkan sistem poin per jabatan dan proporsi kehadiran, dengan penyisihan untuk kerusakan atau kehilangan sesuai kebijakan properti. |
| FR-HR-033 | Human Resource | Wajib | Menampilkan simulasi distribusi service charge sebelum disahkan, dan mengunci nilainya setelah disetujui oleh General Manager. |
| FR-HR-034 | Human Resource | Wajib | Menerbitkan slip pendapatan elektronik per karyawan yang memuat rincian gaji, lembur, potongan, dan bagian service charge. |
| FR-HR-035 | Human Resource | Sebaiknya | Mengekspor data penggajian ke berkas lembar kerja atau format yang dapat diterima sistem penggajian pihak ketiga. |
| FR-HR-036 | Human Resource | Sebaiknya | Menyimpan dasar perhitungan pajak penghasilan karyawan dan iuran jaminan sosial sebagai parameter yang dapat dikonfigurasi. |
| FR-HR-037 | Human Resource | Wajib | Payroll run memiliki lifecycle draft, calculated, reviewed, approved, paid, dan locked; hanya periode approved yang boleh diteruskan untuk pembayaran. |
| FR-HR-038 | Human Resource | Wajib | Perubahan setelah payroll/service-charge dikunci dilakukan melalui adjustment pada periode berikutnya atau reopening berizin tinggi; transaksi lama tidak ditimpa. |
| FR-FIN-001 | Finance | Wajib | Menerima pembukuan pendapatan otomatis dari Front Office dan seluruh POS outlet, terpisah antara nilai dasar, pajak, dan service charge. |
| FR-FIN-002 | Finance | Wajib | Menerbitkan laporan pendapatan harian per outlet dan per metode pembayaran, serta rekapitulasi bulanan. |
| FR-FIN-003 | Finance | Wajib | Melakukan rekonsiliasi setoran kasir: kas fisik yang disetor dibandingkan dengan kas sistem per shift dan per kasir, dengan pencatatan selisih. |
| FR-FIN-004 | Finance | Sebaiknya | Merekonsiliasi penerimaan QRIS dan kartu terhadap mutasi rekening bank, termasuk pemotongan biaya transaksi. |
| FR-FIN-005 | Finance | Wajib | Memverifikasi dan mengunci transaksi hari sebelumnya setelah night audit sehingga tidak dapat diubah tanpa jurnal koreksi. |
| FR-FIN-006 | Finance | Wajib | Setiap posting keuangan menyimpan property, business date, event time, source document, actor, dan correlation ID agar rekonsiliasi lintas modul dapat dilakukan tanpa ambigu. |
| FR-FIN-010 | Finance | Wajib | Mengelola daftar akun biaya sederhana yang dikelompokkan per department dan per kategori. |
| FR-FIN-011 | Finance | Wajib | Mencatat hutang kepada pemasok dan vendor secara otomatis dari penerimaan barang dan faktur, lengkap dengan syarat pembayaran dan tanggal jatuh tempo. |
| FR-FIN-012 | Finance | Wajib | Menampilkan jadwal jatuh tempo pembayaran dan laporan umur hutang, serta mengirimkannya sebagai peringatan ke dashboard. |
| FR-FIN-013 | Finance | Wajib | Mencatat pembayaran kepada pemasok dan vendor, baik penuh maupun sebagian, beserta bukti pembayaran. |
| FR-FIN-014 | Finance | Wajib | Mengelola piutang dari perusahaan, agen perjalanan, dan kanal pemesanan daring beserta umur piutang dan penagihan. |
| FR-FIN-015 | Finance | Wajib | Mengelola kas kecil (petty cash): pengisian, pengeluaran dengan bukti, dan pertanggungjawaban. |
| FR-FIN-016 | Finance | Sebaiknya | Mencatat biaya tetap berulang seperti sewa, listrik, air, dan langganan, dengan pengingat jatuh tempo. |
| FR-FIN-017 | Finance | Sebaiknya | Menyusun anggaran per department dan menampilkan perbandingan anggaran terhadap realisasi. |
| FR-FIN-018 | Finance | Wajib | Pembayaran vendor/pengeluaran di atas threshold menggunakan maker-checker; pembuat transaksi tidak boleh menjadi satu-satunya penyetuju. |
| FR-FIN-019 | Finance | Wajib | Refund tamu, chargeback, settlement discrepancy, dan pembayaran berstatus unknown dikelola sebagai exception sampai direkonsiliasi, bukan diedit langsung pada transaksi asal. |
| FR-FIN-020 | Finance | Wajib | Menghitung pajak daerah atas jasa perhotelan dan makanan minuman secara otomatis per outlet dengan tarif yang dapat dikonfigurasi. |
| FR-FIN-021 | Finance | Wajib | Menyajikan lini masa kewajiban pajak: nilai terkumpul berjalan, periode pelaporan, tanggal jatuh tempo, dan status penyetoran. |
| FR-FIN-022 | Finance | Wajib | Menghitung akumulasi service charge dari kamar dan outlet serta menyiapkan nilai yang akan didistribusikan melalui modul Human Resource. |
| FR-FIN-023 | Finance | Wajib | Menerbitkan berkas rekapitulasi pajak yang siap dilaporkan kepada instansi pajak daerah. |
| FR-FIN-024 | Finance | Sebaiknya | Memisahkan pencatatan pendapatan yang tidak dikenai pajak, kompliment, dan penghapusan tagihan agar dasar pengenaan pajak tetap akurat. |
| FR-FIN-025 | Finance | Wajib | Tarif pajak, service charge, dan aturan pembulatan memiliki tanggal efektif; perubahan konfigurasi tidak boleh mengubah perhitungan transaksi historis. |
| FR-FIN-030 | Finance | Wajib | Menerbitkan management P&L operasional per department berdasarkan pemetaan pendapatan dan biaya yang tersedia. Laporan diberi label jelas sebagai laporan manajemen, bukan laporan keuangan statutori pengganti buku besar akuntansi. |
| FR-FIN-031 | Finance | Wajib | Menerbitkan laporan arus kas ringkas: penerimaan, pengeluaran, dan saldo kas serta bank. |
| FR-FIN-032 | Finance | Sebaiknya | Menerbitkan laporan biaya bahan terhadap penjualan untuk outlet makanan dan minuman. |
| FR-FIN-033 | Finance | Sebaiknya | Menerbitkan laporan nilai persediaan pada tanggal tertentu berdasarkan data modul Inventory. |
| FR-FIN-034 | Finance | Wajib | Mengekspor data transaksi ke format lembar kerja atau format impor perangkat lunak akuntansi yang digunakan properti. |
| FR-FIN-035 | Finance | Wajib | Menyimpan jejak audit atas seluruh perubahan angka keuangan beserta pelaku dan waktunya. |
| FR-FIN-036 | Finance | Wajib | Transaksi keuangan yang telah locked hanya dapat dikoreksi melalui reversal/adjustment yang menaut ke transaksi asal dan memerlukan alasan serta otorisasi. |
| FR-FIN-037 | Finance | Wajib | Rekonsiliasi harian menghasilkan daftar exception antara POS/folio, payment provider/EDC, kas fisik, dan bank; hari dianggap clean hanya bila exception telah diselesaikan atau di-waive. |
| FR-RPT-001 | Reporting & Analytics | Wajib | Menyediakan pusat laporan yang mengelompokkan seluruh laporan berdasarkan department dan tema. |
| FR-RPT-002 | Reporting & Analytics | Wajib | Seluruh laporan mendukung penyaring rentang tanggal, outlet, department, dan pengguna. |
| FR-RPT-003 | Reporting & Analytics | Wajib | Seluruh laporan dapat diekspor ke PDF dan lembar kerja, serta dicetak. |
| FR-RPT-004 | Reporting & Analytics | Sebaiknya | Laporan dapat dijadwalkan untuk dikirim otomatis melalui surel atau pesan instan pada waktu tertentu kepada penerima tertentu. |
| FR-RPT-005 | Reporting & Analytics | Wajib | Menyediakan laporan ringkas harian untuk manajemen (flash report) yang memuat okupansi, pendapatan, biaya utama, dan kejadian penting. |
| FR-RPT-006 | Reporting & Analytics | Sebaiknya | Menyediakan pembanding antar periode: hari ini dibanding kemarin, bulan ini dibanding bulan lalu, dan tahun berjalan dibanding tahun sebelumnya. |
| FR-RPT-007 | Reporting & Analytics | Wajib | Menyediakan jejak audit yang dapat dicari berdasarkan pengguna, modul, dan rentang waktu. |
| FR-RPT-008 | Reporting & Analytics | Bisa | Menyediakan pembuat laporan sederhana bagi pengguna mahir untuk memilih kolom dan penyaring sendiri. |
| FR-RPT-009 | Reporting & Analytics | Wajib | Setiap laporan menampilkan generated-at time, business date/periode, filter yang digunakan, dan sumber data utama sehingga hasil dapat direproduksi. |
| FR-RPT-010 | Reporting & Analytics | Wajib | Kolom PII pada laporan mengikuti hak akses dan dapat dimasking; ekspor data sensitif dicatat pada audit log dengan pengguna, waktu, filter, dan tujuan. |
| FR-RPT-011 | Reporting & Analytics | Sebaiknya | Ekspor besar diproses asynchronous dengan status pekerjaan dan notifikasi selesai agar tidak membebani transaksi operasional. |
| FR-GST-001 | Guest Self-Service | Wajib | Tamu memindai kode QR di area lobi atau menerima tautan sebelum kedatangan untuk membuka halaman pendaftaran mandiri. |
| FR-GST-002 | Guest Self-Service | Wajib | Tamu mengisi data diri, mengunggah atau memotret identitas, dan membubuhkan tanda tangan digital pada kartu registrasi. |
| FR-GST-003 | Guest Self-Service | Wajib | Tamu melakukan pembayaran atau deposit melalui QRIS; status pembayaran otomatis tercatat pada folio. |
| FR-GST-004 | Guest Self-Service | Wajib | Setelah verifikasi oleh resepsionis, tamu menerima konfirmasi berisi nomor kamar dan petunjuk pengambilan kunci. |
| FR-GST-005 | Guest Self-Service | Wajib | Data hasil check-in mandiri masuk ke antrean verifikasi Front Office, bukan langsung mengubah status kamar tanpa persetujuan petugas. |
| FR-GST-006 | Guest Self-Service | Wajib | Tautan check-in mandiri menggunakan token acak berumur terbatas, rate limiting, dan validasi reservasi; tautan kedaluwarsa tidak dapat digunakan kembali. |
| FR-GST-007 | Guest Self-Service | Wajib | Sebelum mengirim identitas/tanda tangan, tamu diberikan pemberitahuan privasi dan persetujuan yang versinya tersimpan bersama waktu persetujuan. |
| FR-GST-010 | Guest Self-Service | Wajib | Setiap kamar dan setiap meja restoran memiliki kode QR unik yang membuka menu digital. |
| FR-GST-011 | Guest Self-Service | Wajib | Pemesanan dari kamar mewajibkan pengisian nomor kamar dan mencocokkannya dengan nama tamu yang sedang menginap agar terintegrasi dengan kasir dan Front Office. |
| FR-GST-012 | Guest Self-Service | Wajib | Pesanan dari menu QR masuk ke POS outlet dan layar dapur seperti pesanan yang diambil pelayan, dengan penanda sumber pesanan. |
| FR-GST-013 | Guest Self-Service | Wajib | Menu yang ditandai habis oleh dapur otomatis tidak dapat dipesan melalui menu QR. |
| FR-GST-014 | Guest Self-Service | Wajib | Tamu memilih pembayaran langsung melalui QRIS atau pembebanan ke kamar; pembebanan ke kamar memerlukan verifikasi petugas. |
| FR-GST-015 | Guest Self-Service | Sebaiknya | Tamu dapat mengirim permintaan layanan dan keluhan dari halaman yang sama, yang langsung masuk ke antrean department terkait. |
| FR-GST-016 | Guest Self-Service | Sebaiknya | Tamu dapat melihat rincian tagihan berjalan dan mengisi survei kepuasan menjelang keberangkatan. |
| FR-GST-017 | Guest Self-Service | Wajib | Halaman tamu tersedia dalam Bahasa Indonesia dan Bahasa Inggris, ringan dibuka pada jaringan lambat, dan tidak memerlukan pemasangan aplikasi. |
| FR-GST-018 | Guest Self-Service | Wajib | QR kamar/meja tidak mengekspos identifier internal yang mudah ditebak. Session tamu berumur terbatas dan aksi sensitif seperti room charge memerlukan verifikasi konteks stay. |
| FR-GST-019 | Guest Self-Service | Sebaiknya | Tamu dapat melihat status pesanan/permintaan layanan tanpa memperoleh akses ke data tamu lain atau histori stay sebelumnya. |

**Total functional requirements: 270.**
