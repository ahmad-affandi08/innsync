<?php

declare(strict_types=1);

namespace App\Shared\Application\Deployment;

/**
 * Judges whether an environment can safely receive a release under the
 * shared-hosting profile (ADR-0003, ADR-0008, docs/ARCHITECTURE/15). Pure: it
 * sees only `PreflightInputs`, so every rule is unit tested without a server.
 *
 * Rules that protect production (debug, HTTPS, key, backups) are failures only
 * when the target is production; elsewhere they are warnings, so a developer or
 * staging machine can still be inspected without false alarms.
 */
final class PreflightEvaluator
{
    public const MINIMUM_PHP = '8.3.0';

    public const MINIMUM_MYSQL = '8.0';

    /** Extensions Laravel 13 and this application rely on. */
    public const REQUIRED_EXTENSIONS = [
        'ctype', 'curl', 'dom', 'fileinfo', 'json', 'mbstring', 'openssl',
        'pcre', 'pdo', 'pdo_mysql', 'sodium', 'tokenizer', 'xml', 'intl',
    ];

    public function evaluate(PreflightInputs $in): Findings
    {
        $production = strtolower($in->appEnv) === 'production';
        $findings = [];
        $guard = static fn (string $check, bool $good, string $okMessage, string $badMessage) => $good
            ? Finding::ok($check, $okMessage)
            : ($production ? Finding::failure($check, $badMessage) : Finding::warning($check, $badMessage.' (not blocking outside production)'));

        $findings[] = version_compare($in->phpVersion, self::MINIMUM_PHP, '>=')
            ? Finding::ok('php.version', "PHP {$in->phpVersion}")
            : Finding::failure('php.version', "PHP {$in->phpVersion} is below the required ".self::MINIMUM_PHP.'. Select PHP 8.3+ in the hosting panel before releasing.');

        $loaded = array_map('strtolower', $in->extensions);
        $missing = array_values(array_diff(self::REQUIRED_EXTENSIONS, $loaded));
        $findings[] = $missing === []
            ? Finding::ok('php.extensions', 'All required PHP extensions are loaded.')
            : Finding::failure('php.extensions', 'Missing PHP extensions: '.implode(', ', $missing).'.');

        $findings[] = $in->appEnv === ''
            ? Finding::failure('app.env', 'APP_ENV is not set.')
            : ($production
                ? Finding::ok('app.env', 'APP_ENV is production.')
                : Finding::warning('app.env', "APP_ENV is '{$in->appEnv}', not production; production-only rules are reported as warnings."));

        $findings[] = $guard('app.debug', ! $in->appDebug, 'Debug mode is off.', 'APP_DEBUG must be false; debug pages expose configuration and secrets.');
        $findings[] = $guard('app.key', $in->appKeySet, 'APP_KEY is set.', 'APP_KEY is not set; sessions and encrypted data cannot be trusted.');
        $findings[] = $guard('app.url', str_starts_with(strtolower($in->appUrl), 'https://'), 'APP_URL uses HTTPS.', 'APP_URL must use https://.');
        $findings[] = $guard('session.secure', $in->sessionSecureCookie !== false, 'Session cookies are not forced insecure.', 'SESSION_SECURE_COOKIE must not be false; session cookies must be Secure.');

        $findings[] = $in->sessionEncrypted
            ? Finding::ok('session.encrypt', 'Session payloads are encrypted.')
            : Finding::warning('session.encrypt', 'SESSION_ENCRYPT is off; session payloads are stored unencrypted.');

        foreach (['queue' => $in->queueConnection, 'outbox queue' => $in->outboxQueueConnection] as $name => $connection) {
            $findings[] = $connection === 'database'
                ? Finding::ok('queue.'.str_replace(' ', '_', $name), "The {$name} uses the database driver (cron-drained, ADR-0003).")
                : Finding::warning('queue.'.str_replace(' ', '_', $name), "The {$name} uses '{$connection}'. The shared-hosting profile expects the database driver; other drivers must not require a permanent worker (ADR-0008).");
        }

        $findings[] = $in->inertiaSsrEnabled
            ? Finding::failure('inertia.ssr', 'INERTIA_SSR_ENABLED must be false; server-side rendering needs a permanent Node process (ADR-0008).')
            : Finding::ok('inertia.ssr', 'Server-side rendering is off; no permanent Node process is required.');

        foreach ($in->writable as $directory => $isWritable) {
            $findings[] = $isWritable
                ? Finding::ok('writable.'.$directory, "{$directory} is writable.")
                : Finding::failure('writable.'.$directory, "{$directory} is not writable by the web user.");
        }

        $findings[] = $in->publicSensitiveFiles === []
            ? Finding::ok('public.exposure', 'The public web root holds no environment, Composer or SQL files.')
            : Finding::failure('public.exposure', 'Sensitive files are inside the public web root: '.implode(', ', $in->publicSensitiveFiles).'. Remove them and map the domain to the Laravel public/ directory only.');

        if ($in->databaseError !== null) {
            $findings[] = Finding::failure('database.connection', 'The database is not reachable: '.$in->databaseError);
        } elseif ($in->databaseVersion === null || ! $this->isMysql8($in->databaseVersion)) {
            $findings[] = Finding::failure('database.version', 'MySQL '.self::MINIMUM_MYSQL.'+ is required; the server reports '.($in->databaseVersion ?? 'an unknown version').'.');
        } else {
            $findings[] = Finding::ok('database.version', "MySQL {$in->databaseVersion}");
        }

        $findings[] = match (true) {
            $in->pendingMigrations === null => Finding::warning('database.migrations', 'Pending migrations could not be determined.'),
            $in->pendingMigrations === 0 => Finding::ok('database.migrations', 'No pending migrations.'),
            default => Finding::warning('database.migrations', "{$in->pendingMigrations} migration(s) will run. Take and verify a backup first and read the release notes for rollback compatibility."),
        };

        $findings[] = $guard('backup.configured', $in->backupConfigured, 'Encrypted backups are configured.', 'Backups are not configured (BACKUP_PATH, BACKUP_ENCRYPTION_KEY); a release must not run migrations without a recoverable backup.');

        $findings[] = $in->healthTokenSet
            ? Finding::ok('health.token', 'HEALTH_TOKEN is set for detailed health checks.')
            : Finding::warning('health.token', 'HEALTH_TOKEN is not set; detailed health checks are unavailable after release.');

        $findings[] = $in->idempotencyKeySet
            ? Finding::ok('idempotency.key', 'IDEMPOTENCY_HASH_KEY is set.')
            : Finding::warning('idempotency.key', 'IDEMPOTENCY_HASH_KEY is blank; set a stable dedicated secret so retries stay safe across APP_KEY rotation.');

        return new Findings($findings);
    }

    private function isMysql8(string $version): bool
    {
        // MariaDB reports 10.x/11.x and is not the approved engine.
        return ! stripos($version, 'mariadb') && version_compare($version, self::MINIMUM_MYSQL, '>=');
    }
}
