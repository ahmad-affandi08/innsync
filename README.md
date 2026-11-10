# InnSYnc

InnSYnc adalah sistem operasi hotel: satu aplikasi web untuk resepsionis, tata graha, dapur dan restoran, laundry, perawatan, gudang dan pembelian, SDM, keuangan, dan pelaporan. Aplikasi dibangun sebagai **modular monolith Laravel 13 dengan pola DDD / Clean Architecture** (Laravel + Inertia + React/TypeScript), dan target hosting utamanya adalah **shared hosting ala Niagahoster/Hostinger** (tanpa proses latar permanen). Repositori ini diatur oleh [AGENTS.md](AGENTS.md) dan dokumen kontrak di bawah `docs/`.

## Daftar isi

1. [Gambaran produk dan modul](#1-gambaran-produk-dan-modul)
2. [Kebutuhan sistem](#2-kebutuhan-sistem)
3. [Setup lokal (pengembangan)](#3-setup-lokal-pengembangan)
4. [Deploy ke VPS](#4-deploy-ke-vps)
5. [Deploy ke shared hosting / web hosting](#5-deploy-ke-shared-hosting--web-hosting)
6. [Referensi: perintah Artisan, skrip, dan variabel `.env`](#6-referensi-perintah-artisan-skrip-dan-variabel-env)
7. [Status implementasi dan dokumentasi](#7-status-implementasi-dan-dokumentasi)

---

## 1. Gambaran produk dan modul

Satu instalasi dapat memegang satu atau beberapa properti (hotel). Data dipisahkan per properti pada skema bersama (ADR-0002), dengan hak akses berbasis peran (RBAC) dan persetujuan *maker-checker* untuk tindakan sensitif (void, refund, koreksi folio, payroll, dan sebagainya).

Modul aplikasi (folder `app/Modules/`):

| Modul | Cakupan singkat |
| --- | --- |
| `Property` | Pengaturan properti, tipe kamar dan kamar, tarif, pajak dan service charge, daftar periksa *First-time setup* |
| `IdentityAccess` | Akun, peran, izin, kebijakan persetujuan, penguncian akun |
| `FrontOffice` | Reservasi, check-in/out, folio dan kasir, grup, perusahaan/agen, day-use, gift card, loyalty, night audit, kunci pintu |
| `Housekeeping` | Status dan tugas kamar, inspeksi, linen, barang tertinggal, permintaan tamu |
| `Laundry` | Pesanan laundry, harga, klaim |
| `FnbSales` | Kasir restoran/bar (POS), menu, minibar, pesanan tamu |
| `Kitchen` | Tiket dapur, resep, penggunaan bahan, limbah |
| `Maintenance` | Work order, perawatan preventif, laporan kerusakan |
| `InventoryPurchasing` | Stok, permintaan, pembelian, penerimaan barang, stok opname |
| `HumanResource` | Karyawan, absensi (termasuk pencocokan wajah dan geofence), jadwal, cuti, lembur, payroll, service charge |
| `Finance` | Piutang, utang, kas kecil, jurnal, pajak, aset tetap, anggaran, rekonsiliasi bank |
| `Reporting` | Dasbor, laporan terjadwal, ekspor |
| `GuestExperience` | Halaman tamu (check-in mandiri, pesanan, booking online) |
| `Routines` | Rutinitas dan tugas berulang |

Fitur lintas modul: log audit append-only, outbox transaksional untuk pekerjaan latar, antrean berbasis database, antarmuka dua bahasa (Indonesia dan Inggris), mode operasi darurat/offline untuk kasus tertentu, backup terenkripsi, dan pemantauan kesehatan (`/health`).

**Stack:** Laravel 13 / PHP 8.3+, MySQL 8 (InnoDB), Inertia.js 3, React 19 + TypeScript (strict), Tailwind CSS 4, komponen berbasis Radix/shadcn-ui, TanStack Query dan TanStack Table, Vite 8.

---

## 2. Kebutuhan sistem

| Komponen | Versi / catatan |
| --- | --- |
| PHP | **8.3 atau lebih baru** (`composer.json`: `^8.3`). Ekstensi yang diperiksa `deploy:preflight`: `ctype`, `curl`, `dom`, `fileinfo`, `json`, `mbstring`, `openssl`, `pcre`, `pdo`, `pdo_mysql`, `sodium`, `tokenizer`, `xml`, `intl` |
| Composer | 2.x |
| Node.js + npm | Versi acuan CI ada di `.node-version` (**Node 22**). npm dipakai dengan `ignore-scripts=true` (`.npmrc`) |
| Database | **MySQL 8** (disarankan). MariaDB 10.5+ didukung (lihat catatan kolasi di bagian shared hosting) |
| `mysqldump` / `mysql` | Wajib untuk `backup:run`, `backup:verify`, dan tes backup |
| Git | Untuk clone dan, pada deploy, mengambil branch `release` |

> **Catatan versi PHP:** beberapa mesin memiliki PHP default lebih lama (mis. 8.2). Pastikan `php -v` menampilkan 8.3+. Bila ada beberapa versi terpasang, panggil biner yang tepat (mis. `php8.3 artisan ...`) dan jalankan Composer dengan biner itu: `php8.3 $(which composer) install`.

---

## 3. Setup lokal (pengembangan)

### 3.1 Clone dan siapkan database

```bash
git clone <url-repositori> innsync
cd innsync
```

Buat database kosong (contoh dengan pengguna MySQL khusus; ganti `GANTI_PASSWORD`):

```sql
CREATE DATABASE innsync CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE DATABASE innsync_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE DATABASE innsync_restore_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER 'innsync'@'localhost' IDENTIFIED BY 'GANTI_PASSWORD';
GRANT ALL PRIVILEGES ON innsync.* TO 'innsync'@'localhost';
GRANT ALL PRIVILEGES ON innsync_test.* TO 'innsync'@'localhost';
GRANT ALL PRIVILEGES ON innsync_restore_test.* TO 'innsync'@'localhost';
```

- `innsync` adalah database kerja. `innsync_test` dipakai suite tes PHP (`phpunit.xml` memaksa `DB_DATABASE=innsync_test`; tes menolak mereset database lain). `innsync_restore_test` dipakai tes backup/restore.
- Tanpa tes backup, dua database terakhir boleh dilewati sementara.

### 3.2 Pasang dependensi dan konfigurasi

Cara cepat, satu perintah (menjalankan `composer install`, menyalin `.env.example` ke `.env` bila belum ada, `key:generate`, `npm install --ignore-scripts`, dan `npm run build`):

```bash
composer run setup
```

Atau langkah demi langkah:

```bash
composer install
cp .env.example .env
php artisan key:generate
npm install --ignore-scripts
```

Edit `.env` dan sesuaikan kredensial database (nilai lain bawaan `.env.example` sudah cukup untuk lokal):

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=innsync
DB_USERNAME=innsync
DB_PASSWORD=GANTI_PASSWORD
```

Pada lokal, sesi, cache, dan antrean memakai tabel database (`SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`), sehingga **migrasi wajib dijalankan** sebelum aplikasi dibuka.

### 3.3 Migrasi dan data pengembangan

```bash
php artisan migrate
php artisan db:seed --class=DevelopmentSeeder
```

> Jangan memakai `migrate:fresh` atau `migrate:refresh` pada database yang dipakai bersama orang lain; keduanya menghapus semua data.

`DevelopmentSeeder` **hanya berjalan bila `APP_ENV` adalah `local` atau `testing`** dan tidak pernah menjadi bagian dari deploy. Seeder ini membuat properti demo, peran *Administrator* dengan seluruh izin, peran awal, kebijakan persetujuan awal, dan tanggal bisnis awal. Akun pengembangan:

| Properti | Email | Kata sandi |
| --- | --- | --- |
| Demo Hotel | `dev@innsync.test` | `Password123!` |

Kata sandi ini hanya untuk lokal. Seeder dapat dijalankan ulang dengan aman (`firstOrCreate`).

`DevelopmentRoleAccountsSeeder` (jalankan setelah `DevelopmentSeeder`: `php artisan db:seed --class=DevelopmentRoleAccountsSeeder`) membuat satu akun per peran awal agar menu tiap peran bisa dicoba. Email diturunkan dari nama peran (mis. "Night Auditor" menjadi `night-auditor@innsync.test`), dengan kata sandi yang sama seperti di atas; daftar lengkapnya dicetak oleh perintah tersebut.

### 3.4 Menjalankan aplikasi

Terminal pertama (server PHP):

```bash
php artisan serve
```

Terminal kedua (Vite dengan hot reload):

```bash
npm run dev
```

Buka `http://localhost:8000` dan masuk dengan akun pengembangan. Alternatifnya, `composer run dev` menjalankan proses pengembangan terdaftar lewat `php artisan dev` (daftar proses: `php artisan dev:list`).

Bila ingin memeriksa pekerjaan latar secara lokal (outbox, laporan terjadwal, backup), jalankan penjadwal di terminal lain:

```bash
php artisan schedule:work
```

Tanpa penjadwal, pesan outbox tidak terkirim dan tugas terjadwal tidak berjalan; layar "System status" dan `/health` akan menunjukkan penjadwal belum berdetak.

### 3.5 Tes dan pemeriksaan kualitas

```bash
composer test               # seluruh tes PHP (membersihkan cache konfigurasi lebih dulu)
composer test:architecture  # batas arsitektur DDD
composer test:integration   # tes integrasi (butuh MySQL, termasuk innsync_restore_test)
composer lint               # Pint (--test: hanya memeriksa)
composer quality            # gerbang kualitas PHP penuh (lihat di bawah)

npm run typecheck           # TypeScript strict
npm test                    # tes logika frontend (node --test)
npm run build               # build produksi (membuat ulang form-requirements lalu vite build)
npm run quality             # typecheck + test + build
```

- `composer quality` = `composer validate --strict`, Pint, semua tes PHP, `test:deploy` (tes skrip `deploy/host-release.sh` tanpa PHP/MySQL), dan pemeriksaan cache route/config.
- `composer quality` dan `npm run quality` adalah gerbang CI (`.github/workflows/ci.yml`) pada setiap pull request dan push ke `main`; perubahan tidak boleh digabung bila salah satunya gagal. CI memakai MySQL 8.0.
- Tes PHP memerlukan MySQL yang bisa dijangkau dengan database `innsync_test`. Pada mesin dengan banyak versi PHP, jalankan dengan biner 8.3 (`php8.3 artisan test`).
- Opsional: `npm run audit:browser` dan `npm run audit:load` menjalankan audit browser (axe) dan probe beban (`scripts/browser-audit.mjs`, `scripts/load-probe.mjs`).
- Setelah mengubah aturan formulir di sisi PHP: `npm run forms:rules` (mengekspor aturan dengan `tools/export-form-rules.php` lalu membuat ulang `resources/js/generated/form-requirements.json`).

---

## 4. Deploy ke VPS

Bagian ini memakai contoh **Ubuntu 24.04 LTS + Nginx + PHP 8.3-FPM + MySQL 8** dengan akses root/sudo. Repositori **tidak memuat konfigurasi Nginx, systemd, atau Supervisor**; contoh di bawah adalah panduan umum dan belum diuji pada VPS sungguhan. Hal yang sudah diuji oleh repositori adalah skrip rilis (`scripts/release/*`, `deploy/host-release.sh`), `deploy:preflight`, `deploy:smoke`, dan backup/restore.

> **Penting:** profil infrastruktur yang disetujui (ADR-0003, ADR-0008) tidak mensyaratkan proses permanen apa pun. Aplikasi sengaja dirancang agar berjalan **hanya dengan satu cron penjadwal per menit**; penjadwal sudah memuat pengurasan antrean/outbox terbatas, heartbeat, peringatan, backup harian, dan tes restore mingguan. Di VPS, cukup pakai cron (atau systemd timer). Worker permanen (4.8) bersifat opsional dan merupakan asumsi infrastruktur di luar profil yang disetujui, jadi putuskan bersama pemilik sistem sebelum dipakai.

### 4.1 Paket server

```bash
sudo apt update
sudo apt install -y nginx mysql-server git unzip curl \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl \
  php8.3-intl php8.3-zip
```

Bila repositori paket bawaan belum menyediakan PHP 8.3, tambahkan repositori PHP yang Anda percaya (mis. PPA Ondrej) terlebih dahulu. Ekstensi `sodium`, `openssl`, `fileinfo`, `tokenizer`, `ctype`, dan `pdo` biasanya sudah termasuk; verifikasi dengan:

```bash
php8.3 -m | grep -iE "ctype|curl|dom|fileinfo|json|mbstring|openssl|pcre|pdo|pdo_mysql|sodium|tokenizer|xml|intl"
```

Pasang juga Composer 2 dan (bila membangun aset di server) Node 22 + npm, atau bangun di mesin lain (lihat 4.3).

### 4.2 Database

```bash
sudo mysql_secure_installation
sudo mysql
```

```sql
CREATE DATABASE innsync CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER 'innsync'@'localhost' IDENTIFIED BY 'GANTI_PASSWORD_KUAT';
GRANT ALL PRIVILEGES ON innsync.* TO 'innsync'@'localhost';

-- Database kosong untuk tes restore mingguan (backup:verify). Isinya dihapus setiap tes.
CREATE DATABASE innsync_restore_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
GRANT ALL PRIVILEGES ON innsync_restore_test.* TO 'innsync'@'localhost';
```

Biarkan MySQL hanya mendengarkan `127.0.0.1` kecuali ada kebutuhan lain.

### 4.3 Mengambil kode: dua jalur

Tempatkan aplikasi **di luar** direktori web publik, misalnya `/var/www/innsync` (milik pengguna deploy, bukan root).

**Jalur A (disarankan; sama dengan runbook repositori): branch `release` yang sudah jadi.**
CI membuat artefak terverifikasi (dependensi Composer produksi + aset Vite siap pakai, tanpa rahasia) dan menerbitkannya ke branch `release` setiap kali tag `v*` dipush. Server tidak membutuhkan Composer maupun Node.

```bash
sudo mkdir -p /var/www && sudo chown "$USER": /var/www
git clone --branch release --single-branch <url-repositori-readonly> /var/www/innsync
cd /var/www/innsync
```

Gunakan kredensial **baca-saja** (deploy key atau token fine-grained), jangan kredensial pribadi, dan jangan menyimpannya di repositori atau `.env`. Pembuatan deploy key dijelaskan di [DEPLOYMENT-RUNBOOK.md](docs/OPERATIONS/DEPLOYMENT-RUNBOOK.md#first-time-host-setup).

Cara kerja pipeline rilis:

```text
tag v* -> CI membangun + memverifikasi (scripts/release/build.sh, verify.sh)
       -> scripts/release/publish-release-branch.sh -> branch `release`
server: git clone -b release -> deploy/host-release.sh update
```

Tag harus sama dengan `APP_VERSION` dan punya bagian bertanggal di `CHANGELOG.md`. Anda juga bisa membangun artefak sendiri di mesin build (butuh git, php 8.3+, composer, node/npm, zip, sha256sum, dan working tree bersih):

```bash
scripts/release/build.sh     # menulis build/release/innsync-<versi>-<commit>.zip + .sha256
scripts/release/verify.sh build/release/innsync-<versi>-<commit>.zip
```

**Jalur B: bangun dari sumber di server** (bila branch `release` belum tersedia). Ini jalur yang tidak dipaketkan oleh skrip repositori, jadi ikuti dengan hati-hati:

```bash
git clone <url-repositori> /var/www/innsync && cd /var/www/innsync
git checkout <tag-rilis>
composer install --no-dev --optimize-autoloader --no-interaction
npm ci --ignore-scripts
npm run build
```

Pada jalur B, `deploy/host-release.sh` tidak bisa dipakai (ia mengasumsikan klon branch `release`); jalankan langkah 4.5 dan 4.9 secara manual.

### 4.4 Konfigurasi `.env`

```bash
cp .env.example .env
php8.3 artisan key:generate --show      # salin hasilnya ke APP_KEY
php8.3 artisan backup:keygen            # salin hasilnya ke BACKUP_ENCRYPTION_KEY
chmod 600 .env
```

Nilai minimal produksi (semua rahasia adalah placeholder; **jangan commit `.env`**):

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://hotel.contoh.co.id
APP_KEY=base64:HASIL_KEY_GENERATE
APP_LOCALE=id   # opsional; berkas bahasa `id` dan `en` tersedia di lang/

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=innsync
DB_USERNAME=innsync
DB_PASSWORD=GANTI_PASSWORD_KUAT

SESSION_DRIVER=database
SESSION_ENCRYPT=true
# SESSION_SECURE_COOKIE: biarkan kosong (otomatis true di production) atau set true
QUEUE_CONNECTION=database
CACHE_STORE=database
INERTIA_SSR_ENABLED=false

HEALTH_TOKEN=GANTI_TOKEN_PANJANG_ACAK
IDEMPOTENCY_HASH_KEY=GANTI_RAHASIA_ACAK_TERPISAH

BACKUP_PATH=/var/backups/innsync
BACKUP_ENCRYPTION_KEY=base64:HASIL_BACKUP_KEYGEN
BACKUP_RESTORE_TEST_DATABASE=innsync_restore_test
# BACKUP_KEEP_LAST=35

MAIL_MAILER=smtp
MAIL_HOST=smtp.contoh.co.id
MAIL_PORT=587
MAIL_USERNAME=GANTI
MAIL_PASSWORD=GANTI
MAIL_FROM_ADDRESS="no-reply@hotel.contoh.co.id"
```

Hal yang perlu diperhatikan:

- `APP_KEY` harus **dipertahankan selamanya**; tanpa nilai asli, kolom terenkripsi, payload outbox, dan hasil idempotensi tidak bisa dibaca setelah restore.
- `BACKUP_ENCRYPTION_KEY` sengaja terpisah dari `APP_KEY`. **Simpan salinan kedua di luar server** (pengelola kata sandi/brankas). Tanpa kunci ini semua backup tidak terbaca.
- `BACKUP_PATH` harus **di luar** pohon aplikasi, idealnya mount lain atau folder yang disinkronkan keluar server (aplikasi menolak path di dalam pohon aplikasi). `mkdir -p /var/backups/innsync` dan beri hak ke pengguna web.
- `BACKUP_RESTORE_TEST_DATABASE` harus berupa database *scratch*; isinya dihapus tiap tes.
- Kunci web push opsional: `php8.3 artisan innsync:push-keys` lalu salin barisnya ke `.env` (lihat `docs/OPERATIONS/WEB-PUSH.md`).
- Seluruh variabel lain ada di [`.env.example`](.env.example) dengan komentar.

### 4.5 Izin berkas, migrasi, dan cache

```bash
cd /var/www/innsync
sudo chown -R "$USER":www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache

php8.3 artisan deploy:preflight         # periksa PHP, ekstensi, pengaturan produksi, folder tulis, dll.
php8.3 artisan migrate --force
php8.3 artisan config:cache
php8.3 artisan route:cache
php8.3 artisan view:cache
```

`deploy:preflight --strict` juga menggagalkan peringatan. Di luar `APP_ENV=production`, banyak temuan hanya berupa peringatan.

Buat akun administrator pertama (di server, sekali saja; ini membuat properti, peran awal, dan kebijakan persetujuan awal):

```bash
php8.3 artisan innsync:create-admin --email=admin@hotel.contoh.co.id --name="Nama Admin" --property="Nama Hotel"
```

Tambahkan `--password=...` hanya bila perlu; hindari menaruh kata sandi pada riwayat shell. Setelah masuk, ikuti daftar **First-time setup** (`/setup`, lihat [FIRST-TIME-SETUP.md](docs/OPERATIONS/FIRST-TIME-SETUP.md)). **Jangan menjalankan `DevelopmentSeeder` di produksi** (seeder ini menolak berjalan di luar local/testing, tetapi memang tidak untuk produksi).

Pembuatan symlink `storage:link` tidak disyaratkan oleh runbook repositori; berkas privat memakai disk `private_files` (`storage/app/private-files`) yang tidak dipublikasikan.

### 4.6 Nginx dan PHP-FPM

Document root **harus** `public/`. Contoh `/etc/nginx/sites-available/innsync` (ganti domain dan versi socket bila perlu):

```nginx
server {
    listen 80;
    server_name hotel.contoh.co.id;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name hotel.contoh.co.id;

    ssl_certificate     /etc/letsencrypt/live/hotel.contoh.co.id/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/hotel.contoh.co.id/privkey.pem;

    root /var/www/innsync/public;
    index index.php;

    client_max_body_size 10M;

    add_header X-Content-Type-Options nosniff always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }

    # Jangan sajikan berkas tersembunyi (.env, .git) kecuali .well-known
    location ~ /\.(?!well-known) {
        deny all;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/innsync /etc/nginx/sites-enabled/innsync
sudo nginx -t && sudo systemctl reload nginx
```

Pastikan pool PHP-FPM berjalan sebagai pengguna yang sesuai dengan kepemilikan `storage/` (bawaan: `www-data`), dan `opcache` aktif. Setelah rilis baru, `sudo systemctl reload php8.3-fpm` membersihkan opcache bila `opcache.validate_timestamps=0`.

Catatan: konfigurasi proxy tepercaya (*trusted proxies*) **tidak** diatur secara khusus di `bootstrap/app.php`. Jangan menaruh aplikasi di belakang reverse proxy/CDN yang mengakhiri TLS tanpa memeriksa bahwa skema HTTPS dan IP klien terbaca benar (belum diverifikasi di repositori ini).

### 4.7 HTTPS

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d hotel.contoh.co.id
sudo systemctl status certbot.timer     # pembaruan otomatis
```

`deploy:smoke` mensyaratkan cookie sesi `HttpOnly`, `SameSite`, dan `Secure`, jadi HTTPS wajib di produksi.

### 4.8 Penjadwal (wajib) dan antrean

**Cukup satu entri cron per menit** sebagai pengguna aplikasi:

```bash
sudo crontab -u www-data -e
```

```cron
* * * * * cd /var/www/innsync && /usr/bin/php8.3 artisan schedule:run >> /dev/null 2>&1
```

Penjadwal (`routes/console.php`) sudah menjalankan, masing-masing dengan pengaman tumpang tindih: pengurasan outbox (`outbox:drain`) dan `queue:work --stop-when-empty --max-time` terbatas untuk antrean outbox, ekspor dan laporan terjadwal, eskalasi perawatan dan laundry, heartbeat dan peringatan kesehatan, backup harian (`backup:run` pada `BACKUP_RUN_AT`, bawaan 02:00), tes restore mingguan (`backup:verify`, bila `BACKUP_RESTORE_TEST_DATABASE` diisi), serta penghapusan berkas yang masa retensinya habis (`retention:purge`). Cek dengan `php8.3 artisan schedule:list`.

**Opsional, worker permanen.** Semua pekerjaan antrean aplikasi berada di antrean `outbox`, dan sudah dikuras tiap menit oleh penjadwal. Worker permanen hanya mengurangi latensi (sampai beberapa detik). Ia tidak diperlukan, dan ADR-0008 menyatakan proses permanen bukan bagian dari arsitektur awal; pakai hanya dengan persetujuan pemilik. Contoh unit systemd `/etc/systemd/system/innsync-queue.service`:

```ini
[Unit]
Description=InnSYnc outbox queue worker
After=network.target mysql.service

[Service]
User=www-data
Group=www-data
WorkingDirectory=/var/www/innsync
ExecStart=/usr/bin/php8.3 artisan queue:work database --queue=outbox --sleep=1 --tries=5 --timeout=45 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now innsync-queue
```

Setelah setiap rilis, jalankan `php8.3 artisan queue:restart` agar worker memuat kode baru. Dengan Supervisor, pakai perintah `queue:work` yang sama dalam blok `[program:...]`. Nilai `--tries` dan `--timeout` mengikuti `OUTBOX_MAX_ATTEMPTS` dan `OUTBOX_JOB_TIMEOUT_SECONDS`. Cron penjadwal tetap wajib.

### 4.9 Rilis pertama dan verifikasi

Urutan lengkap (Jalur A, setelah `.env` siap dan database terbuat):

```bash
cd /var/www/innsync
php8.3 artisan deploy:preflight
php8.3 artisan migrate --force
php8.3 artisan config:cache && php8.3 artisan route:cache && php8.3 artisan view:cache
php8.3 artisan innsync:create-admin --email=admin@hotel.contoh.co.id --name="Nama Admin" --property="Nama Hotel"
php8.3 artisan backup:run               # backup pertama; readiness `down` sampai ada backup terverifikasi
php8.3 artisan backup:verify            # tes restore ke database scratch
php8.3 artisan health:heartbeat         # atau tunggu satu menit cron
php8.3 artisan health:check
php8.3 artisan deploy:smoke https://hotel.contoh.co.id
```

`deploy:smoke` memeriksa liveness dan readiness, bahwa `/health/details` tersembunyi tanpa token, bahwa berkas repositori (`.env`, `composer.json`, `vendor/`, `storage/logs/`, `.git/`, dan lainnya) **tidak** disajikan, bahwa cookie sesi aman, dan bahwa respons membawa correlation ID. Kegagalan `exposure/...` berarti document root salah: matikan situs dan perbaiki. Detail kesehatan: `curl -H "Authorization: Bearer $HEALTH_TOKEN" https://hotel.contoh.co.id/health/details`.

### 4.10 Update rilis berikutnya

Jalur A, dengan skrip rilis (tiap langkah berhenti pada kegagalan pertama):

```bash
cd /var/www/innsync
PHP_BIN=/usr/bin/php8.3 SMOKE_URL=https://hotel.contoh.co.id deploy/host-release.sh update
# atau rilis tertentu:
PHP_BIN=/usr/bin/php8.3 SMOKE_URL=https://hotel.contoh.co.id deploy/host-release.sh update --ref release-0.1.0-abc123def456
```

Yang dilakukan `update`, berurutan:

1. memastikan klon bersih dan ada `.env`, mengambil branch `release`, dan menolak commit yang bukan riwayat rilis terbit;
2. mode pemeliharaan aktif;
3. **backup** sebelum apa pun berubah (gagal berarti tidak ada yang diubah dan situs dibuka lagi);
4. mengganti kode;
5. **preflight pada kode baru**; gagal berarti kode lama dipulihkan dan tidak ada migrasi;
6. `migrate --force`; gagal berarti situs **dibiarkan dalam mode pemeliharaan** untuk diputuskan manusia (forward-fix atau restore terverifikasi);
7. `config:cache`, `route:cache`, `view:cache`;
8. mode pemeliharaan nonaktif, catatan di `storage/logs/releases.log`, lalu `deploy:smoke` bila `SMOKE_URL` diisi.

Variabel lingkungan skrip: `PHP_BIN`, `REMOTE` (bawaan `origin`), `BRANCH` (bawaan `release`), `SMOKE_URL`, `SMOKE_ARGS` (mis. `--allow-insecure`, hanya untuk latihan lewat HTTP). Jangan mengubah berkas yang dilacak di server; skrip menolak menimpa perubahan lokal. Bila memakai worker systemd, jalankan juga `php8.3 artisan queue:restart` dan `sudo systemctl reload php8.3-fpm`.

Sebelum merilis ke produksi, **latih migrasi pada salinan database staging** (gerbang rilis), baca catatan rilis di `CHANGELOG.md`, dan pastikan CI untuk tag tersebut hijau.

Jalur B: ulangi `git fetch && git checkout <tag>`, `composer install --no-dev --optimize-autoloader`, `npm ci --ignore-scripts && npm run build`, lalu `artisan down`, `backup:run`, `deploy:preflight`, `migrate --force`, tiga perintah cache, `artisan up`, dan `deploy:smoke`.

### 4.11 Rollback

```bash
PHP_BIN=/usr/bin/php8.3 SMOKE_URL=https://hotel.contoh.co.id deploy/host-release.sh rollback <commit-atau-tag-sebelumnya>
```

- **Kode:** `rollback` mengembalikan kode terbit yang lebih lama dan membangun ulang cache. Skrip `update` mencetak commit sebelumnya bila smoke test gagal.
- **Database:** migrasi **tidak pernah dibalik** di produksi. Cacat setelah migrasi diperbaiki dengan migrasi *maju* (forward-fix); setiap rilis harus menjaga skema tetap kompatibel dengan kode rilis sebelumnya. Memulihkan backup adalah keputusan *disaster recovery*, bukan jalan pintas rollback, karena data yang ditulis setelah backup hilang.

### 4.12 Backup dan pemulihan

- Backup penuh terenkripsi (dump MySQL + berkas privat, libsodium secretstream, manifes bertanda tangan HMAC) berjalan harian lewat penjadwal; manual: `php8.3 artisan backup:run`. Retensi bawaan 35 set (`BACKUP_KEEP_LAST`).
- `backup:verify` memulihkan set terbaru ke database scratch dan mencatat durasinya (bukti RTO). Kesehatan backup tampak di `health:check` dan `/health/details`.
- **Salin folder `BACKUP_PATH` ke luar server** (rsync/rclone ke penyimpanan lain) dan simpan `BACKUP_ENCRYPTION_KEY`, `APP_KEY`, dan `IDEMPOTENCY_HASH_KEY` secara terpisah di luar server.
- Pemulihan saat insiden (ringkas; prosedur lengkap di [DR-RUNBOOK.md](docs/OPERATIONS/DR-RUNBOOK.md)):

  ```bash
  php8.3 artisan down
  ls "$BACKUP_PATH"
  php8.3 artisan backup:decrypt <set> database /secure/dump.sql
  mysql --default-character-set=utf8mb4 -u innsync -p innsync < /secure/dump.sql
  php8.3 artisan backup:decrypt <set> files /secure/files.tar
  tar -xf /secure/files.tar -C storage/app/private-files   # sesuaikan dengan struktur arsip
  shred -u /secure/dump.sql /secure/files.tar
  php8.3 artisan health:check && php8.3 artisan up
  ```

- Target: RTO 4 jam. **RPO 15 menit belum terpenuhi** oleh backup harian (data hilang sebesar interval backup); memenuhinya butuh binary log/replika dan keputusan pemilik (`TASK-FND-011`).

---

## 5. Deploy ke shared hosting / web hosting

Ini adalah **profil deployment utama** proyek (Niagahoster/Hostinger; [`docs/ARCHITECTURE/15-DEPLOYMENT-SHARED-HOSTING.md`](docs/ARCHITECTURE/15-DEPLOYMENT-SHARED-HOSTING.md) dan [`DEPLOYMENT-RUNBOOK.md`](docs/OPERATIONS/DEPLOYMENT-RUNBOOK.md)). Prinsipnya: **tidak ada proses latar permanen**; semua pekerjaan latar dijalankan oleh cron. Status keputusan pemilik per runbook: deploy lewat **Git** di Niagahoster, **PHP 8.3**, akses **SSH** tersedia, dan document root dapat diatur untuk domain utama maupun subdomain. Sejumlah fakta paket hosting nyata (batas koneksi MySQL, `mysqldump` untuk cron, tujuan backup luar server, staging) masih berstatus **terbuka**; rilis produksi tetap *BLOCKED* sampai pemilik/IT mengonfirmasinya.

### 5.1 Syarat paket hosting

- **PHP 8.3** dapat dipilih per domain di panel, dengan ekstensi pada bagian 2. Cari path biner PHP 8.3 untuk CLI/cron (`which -a php`, `php -v`; shared host sering menyimpan biner berversi di folder terpisah).
- MySQL 8 atau MariaDB 10.5+ dengan pengguna khusus (utf8mb4).
- **Document root dapat diarahkan ke folder `public/` aplikasi.** Bila panel tidak bisa, deployment *BLOCKED*; **tidak ada** fallback "salin semua ke `public_html`" (ADR/`docs/ARCHITECTURE/15`).
- Cron per menit, dan sebaiknya akses SSH.
- `mysqldump`/`exec` aktif untuk backup (bila tidak, health backup menjadi `down`; verifikasi saat latihan).

### 5.2 Siapkan kode (tanpa Composer dan Node di host)

Host **tidak perlu** Composer maupun Node: aset dibangun di luar host (lokal atau CI) dan `vendor/` ikut dalam artefak rilis.

**Jalur utama, Git + branch `release` (SSH tersedia):**

1. Buat deploy key baca-saja di host:

   ```bash
   ssh-keygen -t ed25519 -f ~/.ssh/innsync_deploy -C "innsync-host" -N ""
   cat ~/.ssh/innsync_deploy.pub
   ```

   Pemilik repositori menambahkan kunci publik itu di *Settings → Deploy keys* **tanpa** "Allow write access".
2. Di `~/.ssh/config` host (mode 600):

   ```text
   Host github-innsync
     HostName github.com
     User git
     IdentityFile ~/.ssh/innsync_deploy
     IdentitiesOnly yes
   ```
3. Uji dan klon **di luar web root** (mis. `~/innsync`, bukan di bawah `public_html`):

   ```bash
   ssh -T git@github-innsync
   git clone --branch release --single-branch git@github-innsync:<pemilik>/<repositori>.git ~/innsync
   ```

**Jalur manual (tanpa Git di host): build di lokal lalu upload.** Di mesin lokal Anda:

```bash
scripts/release/build.sh
scripts/release/verify.sh build/release/innsync-<versi>-<commit>.zip
```

Ini menghasilkan `innsync-<versi>-<commit>.zip` (berisi dependensi Composer produksi dan `public/build`, tanpa `.env`, tes, dokumen, `node_modules`) beserta `.sha256`. Unggah zip lewat File Manager/SFTP, ekstrak ke folder di luar web root (mis. `~/innsync`), dan bandingkan checksum. Skrip build hanya membaca commit `HEAD` yang sudah di-commit (working tree harus bersih).

> Cara tanpa branch `release` sama sekali, dengan `npm run build` dan `composer install --no-dev` di lokal lalu menyalin `vendor/` dan `public/build` secara manual, tidak dibuatkan skrip oleh repositori dan tidak diverifikasi di sini. Bila terpaksa, lakukan di luar web root dan tetap lewati `verify` pada artefak resmi bila memungkinkan.

### 5.3 Document root ke `public`

Di panel hosting (domain atau subdomain), setel **document root ke `~/innsync/public`**. Jangan membuat folder aplikasi bisa diakses lewat browser. Untuk instance cadangan (contingency/standby), runbook menyarankan subdomain sendiri (mis. `standby.<domain>`), sehingga bisa dilatih tanpa menyentuh document root instance utama.

### 5.4 Database dan `.env`

1. Buat database MySQL/MariaDB dan pengguna khusus lewat panel.
2. **MariaDB (umum di shared hosting):** MariaDB sebelum 11.4.5 tidak mengenal kolasi bawaan MySQL 8. Set **sebelum `migrate` pertama**:

   ```dotenv
   DB_COLLATION=utf8mb4_unicode_ci
   ```

   Seluruh migrasi dan suite tes sudah dijalankan pada MariaDB 10.11.10 (per runbook, 2026-10-08); versi MariaDB lain belum diuji.
3. Buat `.env` di host dari `.env.example` (**tidak** dari artefak, tidak pernah di-commit), mode 600:

   ```bash
   cd ~/innsync
   cp .env.example .env
   chmod 600 .env
   /path/ke/php8.3 artisan key:generate --show   # salin ke APP_KEY
   /path/ke/php8.3 artisan backup:keygen         # salin ke BACKUP_ENCRYPTION_KEY
   ```

   Setel minimal:

   ```dotenv
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://hotel.contoh.co.id
   APP_KEY=base64:HASIL_KEY_GENERATE

   DB_HOST=127.0.0.1
   DB_DATABASE=NAMA_DB_PANEL
   DB_USERNAME=USER_DB_PANEL
   DB_PASSWORD=GANTI_PASSWORD
   DB_COLLATION=utf8mb4_unicode_ci      # hanya bila MariaDB < 11.4.5

   SESSION_DRIVER=database
   SESSION_ENCRYPT=true
   QUEUE_CONNECTION=database
   CACHE_STORE=database
   INERTIA_SSR_ENABLED=false

   HEALTH_TOKEN=GANTI_TOKEN_PANJANG_ACAK
   IDEMPOTENCY_HASH_KEY=GANTI_RAHASIA_ACAK_TERPISAH
   BACKUP_PATH=/home/USER/backup-innsync       # di luar pohon aplikasi
   BACKUP_ENCRYPTION_KEY=base64:HASIL_BACKUP_KEYGEN
   # BACKUP_RESTORE_TEST_DATABASE=... (database scratch bila paket mengizinkan)
   ```

   `SESSION_SECURE_COOKIE` biarkan kosong atau `true`. Simpan salinan `BACKUP_ENCRYPTION_KEY` dan `APP_KEY` di luar server.

### 5.5 Izin berkas

`storage/` dan `bootstrap/cache/` harus dapat ditulis oleh pengguna web:

```bash
chmod -R ug+rwX storage bootstrap/cache
```

Pada shared hosting pengguna PHP biasanya sama dengan pemilik akun, jadi izin 755/775 sudah cukup; **jangan** memakai 777. Verifikasi bahwa disk privat (`storage/app/private-files`) tidak dapat diakses publik; `deploy:smoke` memeriksa bahwa `storage/logs/` tidak disajikan.

### 5.6 Migrasi, cache, dan administrator pertama

```bash
cd ~/innsync
PHP=/path/ke/php8.3
$PHP artisan deploy:preflight
$PHP artisan migrate --force
$PHP artisan config:cache && $PHP artisan route:cache && $PHP artisan view:cache
$PHP artisan innsync:create-admin --email=admin@hotel.contoh.co.id --name="Nama Admin" --property="Nama Hotel"
$PHP artisan deploy:smoke https://hotel.contoh.co.id
```

Jika `route:cache` bermasalah pada host Anda, runbook menyebut "bila kompatibel"; `deploy/host-release.sh` tetap menjalankannya. Jangan menjalankan `DevelopmentSeeder` di produksi.

### 5.7 Cron: penjadwal dan antrean

Tambahkan **satu** cron per menit di panel (Cron Jobs), dengan path biner PHP 8.3 dari panel:

```cron
* * * * * cd /home/USER/innsync && /path/ke/php8.3 artisan schedule:run >> /dev/null 2>&1
```

Cukup satu entri ini. Penjadwal sudah memuat pengurasan antrean/outbox terbatas (`queue:work --stop-when-empty --max-time`, dilindungi dari tumpang tindih sehingga aman jika proses sebelumnya masih jalan atau dimatikan oleh batas hosting), heartbeat, peringatan, backup harian, tes restore mingguan, dan penghapusan berkas yang retensinya habis. **Tidak** dibutuhkan, dan **tidak diizinkan** dalam profil ini: worker permanen, Supervisor, Horizon, Reverb/WebSocket mandiri, Octane/RoadRunner/Swoole, SSR (butuh proses Node permanen), dan apa pun yang mengasumsikan akses root. Tinggalkan `INERTIA_SSR_ENABLED=false`.

Karena cron berbasis menit, notifikasi dan tugas latar bisa tertunda hingga sekitar satu menit; tindakan inti (folio, kasir, check-out, stok) dijamin selesai dalam transaksi permintaan itu sendiri dan tidak bergantung pada antrean.

Setelah satu menit cron berjalan, cek `/path/ke/php8.3 artisan health:check`. Readiness `down` sampai ada backup terverifikasi dan `degraded` sampai penjadwal berdetak sekali.

### 5.8 Update rilis berikutnya dan rollback

Di host (SSH):

```bash
cd ~/innsync
PHP_BIN=/path/ke/php8.3 SMOKE_URL=https://hotel.contoh.co.id deploy/host-release.sh update
```

Skrip ini sama dengan yang diuraikan pada 4.10 (backup, preflight pada kode baru, migrasi maju, cache, smoke test). Rollback kode:

```bash
PHP_BIN=/path/ke/php8.3 deploy/host-release.sh rollback <commit-atau-tag-sebelumnya>
```

Migrasi tidak dibalik; perbaikan berupa migrasi maju. Jika `deploy/host-release.sh` tidak dapat dipakai, runbook menyediakan jalur manual: `down`, `backup:run`, `git fetch` + `git reset --hard <commit terbit>`, `deploy:preflight`, `migrate --force`, tiga perintah cache, `up`, `deploy:smoke`. Tanpa SSH sama sekali (hanya panel dan File Manager), deploy dilakukan dengan mengunggah artefak zip baru ke folder aplikasi (jangan menimpa `.env` dan `storage/`), lalu menjalankan migrasi lewat terminal panel bila ada; jalur panel-saja ini **tidak ditulis maupun diuji** oleh repositori (D8: paket yang dipilih menyediakan SSH).

### 5.9 Batasan pada shared hosting

- Tidak ada proses permanen; semua lewat cron (resolusi minimum satu menit, tergantung paket).
- Pekerjaan dalam cron dibatasi waktu eksekusi/memori oleh paket; karena itu worker dibatasi (`OUTBOX_WORKER_MAX_TIME_SECONDS=50`, `OUTBOX_JOB_TIMEOUT_SECONDS=45`) dan seluruh job dirancang aman bila terhenti.
- Backup butuh `mysqldump` dan `exec`; **target RPO 15 menit tidak tercapai** oleh backup harian (butuh point-in-time recovery yang mungkin tidak ditawarkan paket). RTO 4 jam adalah target latihan restore.
- Tidak ada WebSocket mandiri: broadcast memakai `log` (`BROADCAST_CONNECTION=log`); pembaruan layar mengandalkan polling/permintaan biasa dan web push (opsional).
- Redis/Memcached tidak diasumsikan; cache, sesi, dan antrean memakai database (Redis hanya setelah verifikasi infrastruktur dan ADR).
- Instance standby (contingency): database dan `.env` sendiri (`APP_KEY` sendiri, tetapi `BACKUP_ENCRYPTION_KEY` dan `IDEMPOTENCY_HASH_KEY` **disalin dari instance utama**), **cron penjadwal dimatikan** dan tanpa lalu lintas sampai diaktifkan oleh keputusan manusia; uji restore minimal triwulanan. Detail: bagian "Contingency instance" di runbook.

---

## 6. Referensi: perintah Artisan, skrip, dan variabel `.env`

### Perintah operasional

| Perintah | Fungsi |
| --- | --- |
| `php artisan deploy:preflight [--strict]` | Periksa kesiapan lingkungan sebelum migrasi (PHP/ekstensi/MySQL, pengaturan produksi, folder tulis, berkas sensitif di web root, migrasi tertunda, backup) |
| `php artisan deploy:smoke <url> [--json] [--allow-insecure]` | Verifikasi situs dari luar: health, tata letak aman, cookie sesi |
| `php artisan health:check` / `health:heartbeat` / `health:alerts` | Pemeriksaan kesehatan, detak penjadwal, evaluasi peringatan |
| `php artisan backup:run` / `backup:verify` / `backup:keygen` / `backup:decrypt` | Backup terenkripsi, tes restore, kunci baru, dekripsi manual untuk DR |
| `php artisan innsync:create-admin` | Buat admin dan properti pertama (`--email`, `--password`, `--name`, `--property`) |
| `php artisan innsync:install-default-roles` / `innsync:install-default-approvals` | Pasang peran dan kebijakan persetujuan awal yang belum ada |
| `php artisan innsync:unlock-account <email>` | Buka kunci akun yang terkunci |
| `php artisan innsync:push-keys` | Buat kunci VAPID untuk web push |
| `php artisan import:room-master <property-id> <user-id> room_types.csv rooms.csv [--dry-run]` | Impor tipe kamar dan kamar dari CSV (lihat [DATA-MIGRATION-RUNBOOK.md](docs/OPERATIONS/DATA-MIGRATION-RUNBOOK.md)) |
| `php artisan outbox:retry <id>` | Antrekan ulang pesan outbox *dead letter* setelah ditinjau |
| `php artisan schedule:list` / `schedule:run` / `schedule:work` | Daftar, jalankan satu putaran, atau jalankan terus (lokal) penjadwal |

### Skrip repositori

| Skrip | Dijalankan di | Fungsi |
| --- | --- | --- |
| `scripts/release/build.sh` | mesin build | Bangun artefak rilis dari commit `HEAD` (zip + sha256) |
| `scripts/release/verify.sh <zip>` | mesin build | Verifikasi checksum, tidak ada rahasia/berkas dev, dan aplikasi boot dalam mode produksi |
| `scripts/release/publish-release-branch.sh <zip>` | CI | Terbitkan artefak terverifikasi ke branch `release` |
| `deploy/host-release.sh update\|rollback` | host | Rilis/rollback aman (lihat 4.10, 4.11, dan 5.8) |
| `tools/export-form-rules.php` | lokal | Ekspor aturan formulir untuk frontend (`npm run forms:rules`) |
| `tools/door-lock-bridge-example.mjs` | opsional | Contoh jembatan kunci pintu ([DOOR-LOCKS.md](docs/OPERATIONS/DOOR-LOCKS.md)) |
| `scripts/browser-audit.mjs`, `load-probe.mjs`, `offline-start-check.mjs` | lokal | Audit browser, probe beban, cek mulai offline |

### Variabel `.env` penting

Semua ada di [`.env.example`](.env.example) lengkap dengan komentar. Yang paling sering disentuh:

| Variabel | Catatan |
| --- | --- |
| `APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_KEY` | Produksi: `production`, `false`, URL HTTPS, kunci unik yang dipertahankan selamanya |
| `DB_*`, `DB_COLLATION` | Koneksi; set `utf8mb4_unicode_ci` pada MariaDB < 11.4.5 sebelum migrasi pertama |
| `SESSION_*`, `QUEUE_CONNECTION`, `CACHE_STORE` | Semua `database` pada profil shared hosting |
| `HEALTH_TOKEN` | Bearer untuk `/health/details`; kosong menonaktifkan endpoint |
| `IDEMPOTENCY_HASH_KEY`, `PRIVACY_BLIND_INDEX_KEY` | Rahasia terpisah, stabil, jangan diganti setelah ada data |
| `BACKUP_PATH`, `BACKUP_ENCRYPTION_KEY`, `BACKUP_RUN_AT`, `BACKUP_KEEP_LAST`, `BACKUP_RESTORE_TEST_DATABASE` | Backup dan tes restore |
| `OUTBOX_*` | Antrean outbox (batch, percobaan, jeda ulang, batas waktu worker) |
| `MAIL_*` | Email (reset kata sandi, persetujuan, tamu) |
| `FRONTDESK_AUTO_REMINDERS*`, `INVENTORY_RESTOCK_*`, `GUEST_MESSAGE*`, `LOYALTY_*`, `GIFT_CARD_VALID_MONTHS` | Otomatisasi dan fitur opsional |
| `VAPID_*` | Web push (buat dengan `innsync:push-keys`) |
| `FACE_MAX_DISTANCE` | Ambang pencocokan wajah absensi (kalibrasi dengan staf sendiri) |
| `INNSYNC_MAX_PROPERTIES` | Batas properti per instalasi sesuai perjanjian (0 = tanpa batas) |

---

## 7. Status implementasi dan dokumentasi

Semua tugas modul telah diimplementasikan dan menunggu penerimaan pada perangkat nyata; produk siap untuk **pilot terawasi**, belum untuk dijual. Apa yang masih kurang, dan siapa yang harus memutuskan atau membuktikannya, ada di [`docs/OPERATIONS/MARKET-READINESS.md`](docs/OPERATIONS/MARKET-READINESS.md). Status per tugas dan bukti penerimaan ada di `docs/TASK/` (mis. `PHASE-0-FOUNDATION.md`). Catatan rilis: [CHANGELOG.md](CHANGELOG.md).

Dokumen operasional yang berguna di `docs/OPERATIONS/`: [DEPLOYMENT-RUNBOOK.md](docs/OPERATIONS/DEPLOYMENT-RUNBOOK.md), [DR-RUNBOOK.md](docs/OPERATIONS/DR-RUNBOOK.md), [FIRST-TIME-SETUP.md](docs/OPERATIONS/FIRST-TIME-SETUP.md), [DATA-MIGRATION-RUNBOOK.md](docs/OPERATIONS/DATA-MIGRATION-RUNBOOK.md), [ACCESS-ADMINISTRATION.md](docs/OPERATIONS/ACCESS-ADMINISTRATION.md), [OFFLINE-OPERATIONS.md](docs/OPERATIONS/OFFLINE-OPERATIONS.md), [PRIVACY-OPERATIONS.md](docs/OPERATIONS/PRIVACY-OPERATIONS.md), [WEB-PUSH.md](docs/OPERATIONS/WEB-PUSH.md), dan [NFR-EVIDENCE.md](docs/OPERATIONS/NFR-EVIDENCE.md).

### Domain dokumentasi

- `docs/PRD`: kebutuhan produk kanonik dan katalog kebutuhan.
- `docs/TASK`: backlog eksekusi, fase rilis, pekerjaan per modul, UAT/migrasi/deployment.
- `docs/ARCHITECTURE`: batas DDD, Clean Architecture, persistensi, integrasi, hosting, keamanan, strategi tes, dan ADR.
- `docs/RULES`: aturan rekayasa dan produk yang tidak boleh dilanggar kode.
- `docs/DESIGN`: kontrak UI/UX dan interaksi frontend.
- `docs/SKILL`: prosedur operasi agen yang dapat diulang, per spesialisasi.

### Tata kelola

Repositori ini diatur oleh [AGENTS.md](AGENTS.md): urutan baca dokumen wajib sebelum mengubah kode, kondisi berhenti (mis. memperkenalkan proses permanen pada profil shared hosting), dan keterlacakan `FR-*`/`NFR-*`/`TASK-*`/ADR pada setiap perubahan. Urutan otoritas: *Change Request disetujui > PRD > RULES > ARCHITECTURE + ADR diterima > DESIGN > TASK > SKILL > kode yang ada*. Dokumen PRD, RULES, dan ADR yang diterima tidak boleh diubah tanpa Change Request yang disetujui.
