# Phase 0 — Engineering Foundation

These tasks exist before business modules can safely scale.

| Task | Scope | References | Status |
| --- | --- | --- | --- |
| TASK-FND-001 | Bootstrap Laravel 13 + Inertia + React TS + Tailwind 4 + shadcn | ADR-0001 | DONE |
| TASK-FND-002 | Enforce module/layer namespaces and architecture tests | NFR-14 | DONE |
| TASK-FND-003 | MySQL 8 baseline, property scope, ULID, migrations | NFR-16, NFR-19, ADR-0002/0005 | TODO |
| TASK-FND-004 | Auth, session security, RBAC and scoped policies | NFR-05/06/22 | TODO |
| TASK-FND-005 | Audit trail + security log + correlation IDs | NFR-10/20/29 | TODO |
| TASK-FND-006 | Idempotency middleware/application service + table | NFR-18 | TODO |
| TASK-FND-007 | Transactional outbox + database queue + cron drain | NFR-17/25, ADR-0007 | TODO |
| TASK-FND-008 | Private file storage and authorized download | NFR-07/08/24 | TODO |
| TASK-FND-009 | Error envelope, validation, conflict semantics | NFR-19 | TODO |
| TASK-FND-010 | Observability, health endpoint, critical alerts | NFR-20 | TODO |
| TASK-FND-011 | Backup/restore and DR runbook | NFR-11/21/30 | TODO |
| TASK-FND-012 | Frontend query/table conventions and shared UI primitives | NFR-01/12/27 | TODO |
| TASK-FND-013 | i18n framework ID/EN | NFR-12 | TODO |
| TASK-FND-014 | business date/timezone primitives | NFR-26 | TODO |
| TASK-FND-015 | CI quality gates: Pint, static analysis, test, TS typecheck, build | NFR-14 | TODO |
| TASK-FND-016 | deployment pipeline/profile for shared hosting | ADR-0003/0008 | TODO |
| TASK-FND-017 | offline operation envelope for POS/HK | NFR-04/18/19 | TODO |
| TASK-FND-018 | approval/maker-checker engine | NFR-06 | TODO |
| TASK-FND-019 | retention/privacy/export policy mechanisms | NFR-07/08/24/29 | TODO |
| TASK-FND-020 | external integration adapter conventions | NFR-25/28 | TODO |

## TASK-FND-001 acceptance evidence

- Completed: 2026-09-30.
- Traceability: `TASK-FND-001`, `ADR-0001`; no business `FR-*` or `BR-*` behavior is implemented by this bootstrap task.
- Runtime: Laravel 13 / PHP 8.3 baseline with Inertia middleware and an Inertia-rendered smoke page.
- Frontend: React + strict TypeScript, Tailwind CSS 4 semantic tokens, a locally owned shadcn/ui-compatible button primitive, TanStack Query provider, and TanStack Table dependency.
- Deployment constraint: client-side Inertia rendering is the default; SSR is disabled so the shared-hosting profile does not require a permanent Node process (`ADR-0003`, `ADR-0008`).
- Security/scope: no authentication, property-owned data, PII, financial behavior, mutation endpoint, or external integration was introduced.
- Database: MySQL is the application default; schema/property scope/ULID work remains explicitly owned by `TASK-FND-003`.
- Automated evidence: `composer validate --strict`, `vendor/bin/pint --test`, `php artisan test`, `npm run typecheck`, and `npm run build` pass.
- Rollback: remove the scaffold/runtime files and restore the documentation-only repository; no database or external state was changed.

## TASK-FND-002 acceptance evidence

- Completed: 2026-10-01.
- Traceability: `TASK-FND-002`, `NFR-14`, `ADR-0001`; no business `FR-*` or `BR-*` behavior is changed.
- Boundaries: approved bounded-context names and `Domain`, `Application`, `Infrastructure`, and `Presentation` module layers are enforced against filesystem namespaces.
- Dependency direction: Domain and Application are framework-independent; outward-layer dependencies, invalid Shared dependencies, and cross-context Infrastructure access are rejected.
- Structure: the stock Laravel user persistence record now resides in `IdentityAccess/Infrastructure`; the generic root `app/Models` location is no longer used.
- Security/scope: authentication behavior, permissions, property scope, PII, and session policy remain owned by `TASK-FND-004`; this task changes placement only.
- Transaction/idempotency/concurrency: no mutation workflow or transaction boundary was introduced.
- Database/migration: no schema or migration changed; ULID and property-scope migrations remain owned by `TASK-FND-003`.
- Automated evidence: the Architecture suite contains positive and negative fixtures for namespace, layer, framework, cross-context, strict-types, grouped-import, and fully-qualified-reference enforcement. Full PHPUnit, Pint, Composer validation, TypeScript typecheck, and production build pass.
- Rollback: restore the prior user record namespace and remove the architecture suite/script; no database or external state was changed.

## NFR coverage

| NFR | Category | Requirement |
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
