# Backup, Restore, and Disaster Recovery Runbook

Task: `TASK-FND-011` · NFR-11, NFR-21, NFR-30 · Profile: Niagahoster/Hostinger shared hosting (no root, no daemons).

> Status of targets: **RTO ≤ 4 h** is measured by every restore test. **RPO ≤ 15 min is NOT met by this task.** A scheduled full dump gives an RPO of up to one backup interval (daily by default). Meeting 15 minutes needs point-in-time recovery (binary log shipping or managed MySQL with PITR), which the shared-hosting profile may not offer. That is an owner/infrastructure decision (see "Open decisions"); do not report NFR-11 as satisfied until it is made.

## What is automated

| Item | How |
| --- | --- |
| Full backup | `php artisan backup:run` (scheduled daily at `BACKUP_RUN_AT`): `mysqldump --single-transaction` (routines, triggers, events) plus the private-files directory, each encrypted with libsodium secretstream. The dump is streamed through encryption, never written as plaintext. |
| Integrity | Signed manifest (HMAC-SHA-256 with the backup key) lists artifact SHA-256 values, table list, migrations, and file counts. |
| Restore test | `php artisan backup:verify` (weekly when `BACKUP_RESTORE_TEST_DATABASE` is set): imports the latest set into a scratch database, checks table set, migrations, append-only row-count windows, and file count/bytes. Duration is recorded as RTO evidence in `backup_runs`. |
| Monitoring | Health check `backup` (see `/health/details`, `health:check`): down when no successful backup exists or it is older than `BACKUP_MAX_AGE_HOURS_DOWN`; degraded when overdue, when the last run failed, or when no restore test passed within `BACKUP_RESTORE_TEST_MAX_AGE_DAYS`. Unconfigured backups are `down` in production. |

## One-time setup

1. Pick a destination that is **not** the web server's only disk: another mount, or a folder continuously synced off-box. Set `BACKUP_PATH` to its absolute path. The app refuses paths inside the application tree.
2. `php artisan backup:keygen`, put the value in `BACKUP_ENCRYPTION_KEY`, and **store a second copy offline** (password manager/safe). Without it every backup is unreadable. It is deliberately separate from `APP_KEY`.
3. Create an empty scratch database named `<something>_restore_test` with the same MySQL user privileges, and set `BACKUP_RESTORE_TEST_DATABASE`. Its tables are wiped on each test; never point it at real data.
4. Confirm cron runs `php artisan schedule:run` every minute, then run `backup:run` and `backup:verify` once by hand and check `health:check`.
5. `BACKUP_KEEP_LAST` defaults to 35 daily sets (Indonesia baseline, `docs/OPERATIONS/INDONESIA-COMPLIANCE-BASELINE.md`, to be confirmed by counsel). An empty value keeps every set, in which case watch the `storage_capacity` signal and the destination's free space.

## Restore procedure (real incident)

1. Declare the incident, note the time, and put the app in maintenance mode if it is still serving (`php artisan down`), so no new writes land on a damaged database.
2. Provision a runtime (PHP ≥ 8.3, current release artifact) and an empty MySQL 8 database. Restore `.env` from the secret store, including `BACKUP_PATH` and `BACKUP_ENCRYPTION_KEY`. `APP_KEY` must be the **original** value, or encrypted columns, outbox payloads, stored-file blobs, and idempotency results cannot be read.
3. Choose the newest set whose manifest verifies: `ls $BACKUP_PATH`; `php artisan backup:decrypt <set> database /secure/dump.sql` verifies the signature and checksum before writing anything.
4. Import: `mysql --default-character-set=utf8mb4 -u <user> -p <database> < /secure/dump.sql`. Triggers (append-only audit evidence) are part of the dump.
5. Restore files: `php artisan backup:decrypt <set> files /secure/files.tar`, then extract into `storage/app/private-files` (`tar -xf`). Keep ownership/permissions private.
6. Shred plaintext: `shred -u /secure/dump.sql /secure/files.tar` (it contains PII).
7. Run `php artisan migrate --force` only if the code is newer than the dump; then `php artisan health:check`, smoke-test login, a folio view, and a file download.
8. Bring the app up, record the data-loss window (last restored write time to incident time), and run the reconciliation below.

## Manual fallback while the system is down (NFR-30)

- Front office: paper registration cards and a numbered receipt book per shift; room status by whiteboard; no card numbers written anywhere.
- POS/F&B: pre-numbered paper checks with item, quantity, payment method; payment-gateway "unknown" results stay unconfirmed until the provider statement is checked.
- Housekeeping: printed room list with status/time/initials.
- Each fallback record carries date, time, staff name, and a unique manual reference.

## Reconciliation after recovery

1. Re-key manual records through the normal screens/offline envelope (`TASK-FND-017`), using the manual reference as the idempotency key so a record entered twice cannot post twice; never edit or delete posted history, post corrections.
2. Compare control totals (cash, room revenue, stock movements) of the manual sheets with the system for the downtime window; investigate every difference before night audit.
3. Review outbox dead letters (`outbox:retry` after review) and payment-unknown items against provider settlement reports.
4. Record the incident: timeline, data-loss window, recovery duration vs RTO, root cause, follow-ups.

## Recovery drills (NFR-21)

At least quarterly, a person other than the author performs the restore procedure end-to-end into a fresh environment from the off-server key copy and records: set used, duration, defects. The weekly `backup:verify` covers the database/file mechanics; the drill covers people, credentials, and documentation.

## Open decisions (owners)

| Decision | Why it blocks | Owner |
| --- | --- | --- |
| Off-box backup destination and its protection/access | Required for "protected separately"; path is only a mount point here | Owner / IT |
| PITR/binlog capability to reach RPO ≤ 15 min | Daily dump alone cannot meet it; may need a plan change and an ADR | Owner / IT |
| Backup retention period (PRD Q-15) | 35 daily sets by default, an operational choice under the Indonesia baseline | Owner / IT / counsel to confirm |
| Whether `mysqldump`/`exec` are enabled on the purchased plan | Backup fails visibly (health `down`) if not; verify during deployment rehearsal | IT |
