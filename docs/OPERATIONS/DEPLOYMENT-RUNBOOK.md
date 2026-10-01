# Deployment Runbook — Shared Hosting Profile

Traceability: `TASK-FND-016`, `ADR-0003`, `ADR-0008`, `NFR-03`, `NFR-11`, `NFR-14`, `NFR-23`, `NFR-30`; `docs/ARCHITECTURE/15-DEPLOYMENT-SHARED-HOSTING.md`; `docs/TASK/DEPLOYMENT-NIAGAHOSTER.md`. Restore and disaster recovery are in [DR-RUNBOOK.md](DR-RUNBOOK.md).

This is a **draft for owner and operations review**. It describes what the repository can prove. The facts below it depends on about the real hosting plan are listed under "Open decisions"; until they are confirmed, a production release is **BLOCKED**.

## Open decisions (owner / IT)

| # | Decision | Why it blocks |
| --- | --- | --- |
| D1 | How code reaches the host: SSH + Git, SSH + upload, or file-manager/FTP upload of the artifact. | The release step is written for an uploaded artifact; no automatic deploy exists, on purpose. |
| D2 | Can the domain document root be mapped to the application's `public/` directory? | If not, deployment is BLOCKED; never copy the framework into a browsable directory (`docs/ARCHITECTURE/15`). `deploy:smoke` proves the result. |
| D3 | PHP binary and version selectable per domain (8.3+), and the cron command's PHP path. | Laravel 13 needs PHP 8.3+. `deploy:preflight` fails fast otherwise. |
| D4 | MySQL 8 connection limits and whether `mysqldump`/`mysql` are available to cron. | Needed by `backup:run` and by the restore test. |
| D5 | Off-server backup destination and credential custody (`BACKUP_PATH`, `BACKUP_ENCRYPTION_KEY`). | Unresolved in `DR-RUNBOOK.md`; `NFR-11` RPO is not yet met. |
| D6 | Staging or database copy for the migration rehearsal. | A rehearsal is a release gate (below). |

## The release artifact

`scripts/release/build.sh` builds from the committed files of `HEAD` only (`git archive`): Composer **production** dependencies, prebuilt Vite assets in `public/build`, no `.env`, no tests, no docs, no `node_modules`, empty `storage` and `bootstrap/cache`. It writes `innsync-<version>-<commit>.zip`, its `.sha256`, and an internal `SHA256SUMS` plus `RELEASE-MANIFEST.json`. `scripts/release/verify.sh` proves the checksums, that no secret or development file ships, that required runtime files exist, and that the application **boots in production mode without development dependencies**.

The `Release artifact` workflow runs both on a `v*` tag and uploads the result. It does not deploy. Only tag a commit whose CI run is green, and the tag must equal `APP_VERSION` with a dated `CHANGELOG.md` section.

## First-time host setup

1. Select PHP 8.3+ for the domain; confirm the extensions listed in `PreflightEvaluator::REQUIRED_EXTENSIONS`.
2. Create the MySQL 8 database and a dedicated user (utf8mb4).
3. Place the application **outside** the public directory and map the domain document root to `<app>/public`. Do not make the application root browsable.
4. Create the production `.env` on the host from `.env.example` (never from the artifact, never committed). Set at least: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`, a fresh `APP_KEY` (`php artisan key:generate --show`), database credentials, `SESSION_SECURE_COOKIE` unset or `true`, `SESSION_ENCRYPT=true`, `QUEUE_CONNECTION=database`, `INERTIA_SSR_ENABLED=false`, `HEALTH_TOKEN`, a dedicated `IDEMPOTENCY_HASH_KEY`, `BACKUP_PATH`, `BACKUP_ENCRYPTION_KEY` (`php artisan backup:keygen`; keep a copy off-server). Restrict the file to the web user (mode 600).
5. Make `storage` and `bootstrap/cache` writable by the web user.
6. Add **one** cron entry, every minute, using the PHP path from D3:

   ```
   * * * * * cd /path/to/app && /path/to/php8.3 artisan schedule:run >> /dev/null 2>&1
   ```

   The schedule already contains the bounded queue drain (`queue:work --stop-when-empty --max-time`), the outbox drain, the heartbeat, alerts, the daily backup, and the weekly restore test, each guarded against overlap. No permanent worker, Supervisor, Horizon, Reverb, Octane, or Node process is needed or allowed.

## Release steps

Every release, in order. Stop and do not proceed on any failure.

1. **Artifact.** Download the artifact of the tagged green build. Run `scripts/release/verify.sh <zip>` and check `sha256sum -c`.
2. **Preflight on the host.** With the new code uploaded to a staging directory or the live directory's sibling, run `php artisan deploy:preflight --json`. Any `failure` blocks the release. Read every `warning`; pending migrations list their count.
3. **Rehearse the migrations** on a staging database copy (D6) and read the release notes for rollback compatibility.
4. **Backup.** `php artisan backup:run`, then confirm the new set verifies (`php artisan backup:verify` or the weekly result). Do not run migrations without a backup you could restore.
5. **Maintenance mode** only if the migration risk requires it: `php artisan down --retry=60`.
6. **Switch code.** Replace the application files with the artifact (keep `.env` and `storage`). Keep the previous artifact until the release is accepted.
7. **Migrate.** `php artisan migrate --force`.
8. **Caches.** `php artisan config:cache && php artisan route:cache && php artisan view:cache`.
9. **Back online.** `php artisan up` if step 5 was used.
10. **Smoke from outside.** Readiness is `down` until a verified backup exists (step 4) and `degraded` until the scheduler has ticked once; run `php artisan health:heartbeat` or wait one cron minute first. `php artisan deploy:smoke https://your-domain --json` (run from any machine with the code, not necessarily the host). Add `--allow-insecure` only for a rehearsal over plain HTTP. It checks liveness, readiness, that detailed health is hidden, that repository files (`.env`, `composer.json`, `vendor/`, `storage/logs/`, `.git/`, …) are **not** served, that the session cookie is `HttpOnly`, `SameSite` and `Secure`, and that responses carry a correlation ID. A failure of an `exposure/…` check means the layout is not secure: take the site offline and fix the document root. (Rehearsed: with the document root on the application folder the check reports `/.env`, `composer.json`, `artisan` and `vendor/` as served; with `public/` as the root it passes.)
11. **Health.** `php artisan health:check`, and `GET /health/details` with the bearer secret. Confirm the scheduler heartbeat and no dead letters.
12. **Monitor** logs, error rate, and the critical flows for the agreed period; record the release evidence (version, commit, preflight, smoke, who, when).

## Rollback

- **Code:** switch back to the previous artifact (step 6 in reverse), re-run `config:cache`/`route:cache`, and run `deploy:smoke`.
- **Database:** migrations are not reversed in production. A defect found after migrating is repaired with a **forward** migration. Each release must therefore keep the schema compatible with the previous code for one release (add first, remove later). Restoring a backup is a disaster-recovery decision, not a rollback shortcut: it discards data written since the backup (see `DR-RUNBOOK.md`).
- **Communication:** tell users before maintenance mode and after recovery; record the incident and the corrective migration in `CHANGELOG.md`.

## What the repository checks for you

| Command | Run where | Proves |
| --- | --- | --- |
| `scripts/release/verify.sh` | build machine | the artifact is complete, clean of secrets and dev files, and boots in production mode |
| `php artisan deploy:preflight` | target host | PHP/extension/MySQL versions, production safety settings, writable directories, no sensitive files in the public root, no permanent-process dependency, pending migrations, backup configured |
| `php artisan deploy:smoke <url>` | anywhere | the released site is healthy and the document root exposes only `public/` |

`deploy:preflight --strict` also fails on warnings. Failures that only matter in production (debug, HTTPS, key, backups) are warnings on a non-production target.

## Unsupported in this profile

Permanent Supervisor workers, Horizon, self-hosted Reverb/WebSocket, Octane/RoadRunner/Swoole, server-side rendering (needs a permanent Node process), and anything that assumes root access (`ADR-0003`, `ADR-0008`).
