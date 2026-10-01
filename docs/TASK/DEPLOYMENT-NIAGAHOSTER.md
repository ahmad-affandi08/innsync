# Production Deployment Tasks — Niagahoster/Hostinger

## Owner decisions (2026-10-01)

- Deploy through **Git** on Niagahoster shared hosting, with the **best document-root layout** (application outside the web root, document root on `public/`), **PHP 8.3**, as a **contingency (standby)** instance.
- The plan offers **SSH** and allows changing the document root for the main domain and for subdomains.
- Implemented in `TASK-FND-016`; still open: MySQL limits and client tools, off-server backup destination, and a staging rehearsal. Recommended: the standby on its own subdomain. See `docs/OPERATIONS/DEPLOYMENT-RUNBOOK.md`.

## Checklist

- Verify PHP >= 8.3 and extensions required by Laravel 13.
- Verify MySQL 8 and connection limits appropriate to pilot load.
- Configure domain document root securely to Laravel `public/`.
- Confirm SSH/Git deployment path or artifact upload method.
- Configure `.env`, APP_KEY, secure session/cookie values, production debug off.
- Build frontend assets outside production runtime.
- Configure scheduler cron and bounded queue drain cron.
- Verify writable storage/cache directories and private file policy.
- Configure backup destination and restore test.
- Run production migration rehearsal on staging/copy.
- Run health/smoke/UAT gates and record release evidence.
- Document rollback of code, DB-forward-fix strategy, and user communications.
