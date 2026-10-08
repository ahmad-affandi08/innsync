# Deployment Runbook — Shared Hosting Profile

Traceability: `TASK-FND-016`, `ADR-0003`, `ADR-0008`, `NFR-03`, `NFR-11`, `NFR-14`, `NFR-23`, `NFR-30`; `docs/ARCHITECTURE/15-DEPLOYMENT-SHARED-HOSTING.md`; `docs/TASK/DEPLOYMENT-NIAGAHOSTER.md`. Restore and disaster recovery are in [DR-RUNBOOK.md](DR-RUNBOOK.md).

This is a **draft for owner and operations review**. It describes what the repository can prove. Facts about the real hosting plan that are still unconfirmed are listed under "Open decisions"; a production release stays **BLOCKED** until they are confirmed.

## Decisions and open items

Decided by the owner on 2026-10-01:

| # | Decision | Consequence in this repository |
| --- | --- | --- |
| D1 | Deploy to **Niagahoster shared hosting using Git**. | The host is a Git clone of a generated `release` branch and updates with `deploy/host-release.sh`. See "Git deployment". |
| D2 | **Best document-root layout**: the framework lives outside the web root and the domain's document root points to the application's `public/`. | First-time setup step 3. `deploy:smoke` proves it. If the plan cannot do it, deployment is BLOCKED; there is no "copy everything into `public_html`" fallback (`docs/ARCHITECTURE/15`). |
| D3 | **PHP 8.3**. | Selected per domain in the hosting panel; the PHP binary path for cron and `PHP_BIN` is still to be read from the panel. `deploy:preflight` fails fast otherwise. |
| D7 | The purpose of this deployment is **contingency** ("jaga-jaga"): a standby that can take over if the primary fails. | See "Contingency instance": it is kept ready but must not run as a second live system against the same data. |
| D8 | The plan offers **SSH**. | `deploy/host-release.sh` runs over SSH; no panel-only variant is needed or written. |
| D9 | The plan supports changing the document root for **both the main domain and subdomains**. | D2 is achievable. Recommendation: put the contingency instance on its own subdomain (for example `standby.<domain>`), so it can be rehearsed and activated without touching the primary's document root. |

Still open (owner / IT):

| # | Decision | Why it matters |
| --- | --- | --- |
| D4 | MySQL 8 connection limits, and whether `mysqldump`/`mysql` are available to cron. | Needed by `backup:run` and the restore test. |
| D5 | Off-server backup destination and credential custody (`BACKUP_PATH`, `BACKUP_ENCRYPTION_KEY`). | A contingency instance is only as good as the backup it restores from. Unresolved in `DR-RUNBOOK.md`; `NFR-11` RPO is not yet met. |
| D6 | Staging or database copy for the migration rehearsal. | A rehearsal is a release gate. |

## The release artifact

`scripts/release/build.sh` builds from the committed files of `HEAD` only (`git archive`): Composer **production** dependencies, prebuilt Vite assets in `public/build`, no `.env`, no tests, no docs, no `node_modules`, empty `storage` and `bootstrap/cache`. It writes `innsync-<version>-<commit>.zip`, its `.sha256`, and an internal `SHA256SUMS` plus `RELEASE-MANIFEST.json`. `scripts/release/verify.sh` proves the checksums, that no secret or development file ships, that required runtime files exist, and that the application **boots in production mode without development dependencies**.

The `Release artifact` workflow runs both on a `v*` tag, uploads the result, and then publishes it to the `release` branch (below). It never touches the host. Only tag a commit whose CI run is green, and the tag must equal `APP_VERSION` with a dated `CHANGELOG.md` section.

## Git deployment

The host has no Composer or Node (ADR-0008), and `vendor/` and `public/build` are not in the source branch. So the release pipeline publishes the **verified artifact tree** to a generated branch:

```
v* tag → CI builds + verifies → scripts/release/publish-release-branch.sh → branch `release` (one commit per release, tag release-<version>-<commit>)
host:  git clone -b release → deploy/host-release.sh update
```

`release` is generated; never commit to it by hand and never edit files on the host (the script refuses to overwrite modified tracked files). The branch carries its own `.gitignore`: `.env`, keys and runtime directories are never tracked. Each release adds the changed files only, so repository growth is modest, but `vendor/` makes the branch large; clone it once with the host's Git, not per release.

**Host access to the repository.** Use a read-only credential: a deploy key (SSH) or a fine-grained read-only token, created by the repository owner. Never put the credential in the repository or in `.env`.

`deploy/host-release.sh update [--ref <commit-or-tag>]` does, in order, and stops on the first failure:

1. verifies a clean clone with a `.env`, fetches `release`, and refuses any commit that is not published release history;
2. maintenance mode on (with the running code);
3. **backup** before changing anything (if the backup fails, nothing was changed and the site is re-opened);
4. switches the code;
5. **preflight on the new code**; on failure the previous code is restored and nothing is migrated;
6. `migrate --force`; on failure the site is **left in maintenance mode** and a person chooses between a forward-fix and a verified restore;
7. `config:cache`, `route:cache`, `view:cache`;
8. maintenance mode off, a line in `storage/logs/releases.log`, and `deploy:smoke` when `SMOKE_URL` is set.

`deploy/host-release.sh rollback <commit-or-tag>` restores older published code and rebuilds caches. It never reverses the database. The behavior above is covered by `tests/Deploy/host-release-test.sh` (run in CI), and was rehearsed with real PHP and MySQL (see the task evidence).

## First-time host setup

### SSH access to the repository (once per host)

Use a **read-only deploy key**, never a personal credential, and never store it in the repository or `.env`.

1. On the host, over SSH: `ssh-keygen -t ed25519 -f ~/.ssh/innsync_deploy -C "innsync-host" -N ""` and show the public half with `cat ~/.ssh/innsync_deploy.pub`.
2. The repository owner adds that public key under the repository's *Settings → Deploy keys* **without** "Allow write access".
3. On the host, in `~/.ssh/config`, route the repository through that key (mode 600 for the file):

   ```
   Host github-innsync
     HostName github.com
     User git
     IdentityFile ~/.ssh/innsync_deploy
     IdentitiesOnly yes
   ```

4. Test with `ssh -T git@github-innsync`, then clone with `git clone --branch release --single-branch git@github-innsync:<owner>/<repository>.git ~/innsync`.
5. Find the PHP 8.3 binary the hosting panel selects for CLI use (`which -a php`, `php -v`; shared hosts often keep versioned binaries in a separate directory, so confirm with `-v`) and use that path both for `PHP_BIN` and in the cron line.

### Application setup


1. Select PHP 8.3+ for the domain; confirm the extensions listed in `PreflightEvaluator::REQUIRED_EXTENSIONS`.
2. Create the MySQL 8 or MariaDB 10.5+ database and a dedicated user (utf8mb4).
   - **MariaDB first install**: set `DB_COLLATION=utf8mb4_unicode_ci` in `.env` before the first `php artisan migrate`; Laravel creates its own `migrations` table with that setting, and MariaDB before 11.4.5 does not know the MySQL 8 default (`Unknown collation: utf8mb4_0900_ai_ci`). All 131 migrations and the whole automated suite (1,441 tests) were run on MariaDB 10.11.10 on 2026-10-08; other MariaDB versions are untested.
   - **MariaDB Compatibility**: Shared hosts (such as Niagahoster/Hostinger) often run MariaDB. All migrations must remain strictly compatible with both MySQL 8 and MariaDB 10.5+:
     - Use `ALTER TABLE ... DROP CONSTRAINT <name>` rather than `DROP CHECK <name>` when dropping check constraints (MariaDB throws SQL syntax error 1064 on `DROP CHECK`).
     - Use standard SQL `CASE WHEN ... THEN ... ELSE ... END` expressions rather than MySQL-specific `IF()` functions.
     - For conditional unique constraints, use database triggers or standard composite keys rather than dialect-dependent generated columns.
3. With the clone from the SSH step **outside** the web root (for example `~/innsync`, not under `public_html`), set the domain's (or subdomain's) **document root to `~/innsync/public`** in the hosting panel's domain settings. The panel supports this for both the main domain and subdomains (D9). Do not make the application root browsable. If the panel cannot set a document root, stop: deployment is BLOCKED (D2).
4. Create the production `.env` on the host from `.env.example` (never from the artifact, never committed). Set at least: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`, a fresh `APP_KEY` (`php artisan key:generate --show`), database credentials, `SESSION_SECURE_COOKIE` unset or `true`, `SESSION_ENCRYPT=true`, `QUEUE_CONNECTION=database`, `INERTIA_SSR_ENABLED=false`, `HEALTH_TOKEN`, a dedicated `IDEMPOTENCY_HASH_KEY`, `BACKUP_PATH`, `BACKUP_ENCRYPTION_KEY` (`php artisan backup:keygen`; keep a copy off-server). Restrict the file to the web user (mode 600).
5. Make `storage` and `bootstrap/cache` writable by the web user.
6. Add **one** cron entry, every minute, using the PHP path from D3:

   ```
   * * * * * cd /path/to/app && /path/to/php8.3 artisan schedule:run >> /dev/null 2>&1
   ```

   Also in the first-time sequence, with the host's PHP 8.3 binary: `php artisan deploy:preflight`, `php artisan migrate --force`, `php artisan config:cache && php artisan route:cache && php artisan view:cache`, then `php artisan deploy:smoke https://your-domain`.

   The schedule already contains the bounded queue drain (`queue:work --stop-when-empty --max-time`), the outbox drain, the heartbeat, alerts, the daily backup, and the weekly restore test, each guarded against overlap. No permanent worker, Supervisor, Horizon, Reverb, Octane, or Node process is needed or allowed.

## Release steps

Every release, in order. Stop on any failure.

1. **Green build.** The tagged commit's CI run is green; the `Release artifact` workflow published `release-<version>-<commit>`.
2. **Rehearse the migrations** on a staging database copy (D6) and read the release notes for rollback compatibility.
3. **Update the host** (SSH): `PHP_BIN=/path/to/php8.3 SMOKE_URL=https://your-domain deploy/host-release.sh update`. It performs the backup, preflight, migration, caches, maintenance mode and smoke test described in "Git deployment".
4. **Health.** `php artisan health:check`, and `GET /health/details` with the bearer secret. Confirm the scheduler heartbeat and no dead letters. Readiness is `down` until a verified backup exists and `degraded` until the scheduler has ticked once; run `php artisan health:heartbeat` or wait one cron minute.
5. **Smoke from outside** (if `SMOKE_URL` was not set): `php artisan deploy:smoke https://your-domain --json` from any machine with the code. Add `--allow-insecure` only for a rehearsal over plain HTTP. It checks liveness, readiness, that detailed health is hidden, that repository files (`.env`, `composer.json`, `vendor/`, `storage/logs/`, `.git/`, …) are **not** served, that the session cookie is `HttpOnly`, `SameSite` and `Secure`, and that responses carry a correlation ID. A failure of an `exposure/…` check means the layout is not secure: take the site offline and fix the document root. (Rehearsed: with the document root on the application folder the check reports `/.env`, `composer.json`, `artisan` and `vendor/` as served; with `public/` as the root it passes.)
6. **Monitor** logs, error rate and the critical flows for the agreed period, and record the release evidence (version, commit, preflight, smoke, who, when; `storage/logs/releases.log` holds the first three).

Manual fallback if the script cannot be used: put the site in maintenance mode, run `backup:run`, `git fetch` and `git reset --hard <published commit>`, then `deploy:preflight`, `migrate --force`, the three cache commands, `up`, and `deploy:smoke`, in that order.

## Rollback

- **Code:** `deploy/host-release.sh rollback <previous commit or tag>` (it prints the previous commit when a smoke test fails), then `deploy:smoke`.
- **Database:** migrations are not reversed in production. A defect found after migrating is repaired with a **forward** migration. Each release must therefore keep the schema compatible with the previous code for one release (add first, remove later). Restoring a backup is a disaster-recovery decision, not a rollback shortcut: it discards data written since the backup (see `DR-RUNBOOK.md`).
- **Communication:** tell users before maintenance mode and after recovery; record the incident and the corrective migration in `CHANGELOG.md`.

## Contingency instance (D7)

The purpose of this deployment is to stand ready if the primary fails (`NFR-30`). Keep it as a **warm standby**, never as a second live system:

- Own database and own `.env` (own `APP_KEY`; copy `BACKUP_ENCRYPTION_KEY` and `IDEMPOTENCY_HASH_KEY` **from the primary**, because the backups and idempotency records it will restore depend on them; store these off-server too).
- Same release branch, updated with the same script after every primary release, so code and schema stay close to the primary's.
- **Scheduler cron disabled** and no traffic until it is activated. A standby that runs the scheduler against a restored copy would drain the outbox and send integration traffic twice.
- Test it: restore the latest backup into the standby database (`php artisan backup:decrypt` then import, as in `DR-RUNBOOK.md`) at least quarterly (`NFR-21`), run `deploy:preflight` and `deploy:smoke` against it, and record the time taken (RTO target 4 hours, `NFR-11`).
- **Activation** is a human decision by the owner or manager on duty: restore the newest backup, re-check `APP_URL`, enable the cron entry, switch DNS or the published address, run `deploy:smoke`, and reconcile any fallback transactions recorded during the outage (`TASK-FND-017`). Switch back only through the same documented restore; never merge two diverged databases by hand.

Because the standby is only as current as its last backup, its **data loss window equals the backup interval** (daily today). Meeting the 15-minute RPO of `NFR-11` needs the point-in-time decision still open in `TASK-FND-011`; the standby does not solve that by itself.

## What the repository checks for you

| Command | Run where | Proves |
| --- | --- | --- |
| `scripts/release/verify.sh` | build machine | the artifact is complete, clean of secrets and dev files, and boots in production mode |
| `deploy/host-release.sh` | target host | the release is applied in a safe order: backup, preflight on the new code, forward-only migration, caches, optional smoke; failures restore code or leave maintenance mode |
| `php artisan deploy:preflight` | target host | PHP/extension/MySQL versions, production safety settings, writable directories, no sensitive files in the public root, no permanent-process dependency, pending migrations, backup configured |
| `php artisan deploy:smoke <url>` | anywhere | the released site is healthy and the document root exposes only `public/` |

`deploy:preflight --strict` also fails on warnings. Failures that only matter in production (debug, HTTPS, key, backups) are warnings on a non-production target.

## Unsupported in this profile

Permanent Supervisor workers, Horizon, self-hosted Reverb/WebSocket, Octane/RoadRunner/Swoole, server-side rendering (needs a permanent Node process), and anything that assumes root access (`ADR-0003`, `ADR-0008`).
