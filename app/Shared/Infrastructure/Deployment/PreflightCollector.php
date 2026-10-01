<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Deployment;

use App\Shared\Application\Deployment\PreflightInputs;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Reads the live environment into `PreflightInputs`. Contains no judgement. */
final class PreflightCollector
{
    /** File names that must never sit in a publicly served directory. */
    private const SENSITIVE = ['.env', '.env.example', 'composer.json', 'composer.lock', 'artisan', 'package.json', 'phpunit.xml'];

    public function __construct(private readonly Migrator $migrator) {}

    public function collect(): PreflightInputs
    {
        [$version, $error] = $this->database();

        return new PreflightInputs(
            phpVersion: PHP_VERSION,
            extensions: get_loaded_extensions(),
            appEnv: (string) config('app.env'),
            appDebug: (bool) config('app.debug'),
            appKeySet: (string) config('app.key') !== '',
            appUrl: (string) config('app.url'),
            sessionSecureCookie: config('session.secure') === null ? null : (bool) config('session.secure'),
            sessionEncrypted: (bool) config('session.encrypt'),
            queueConnection: (string) config('queue.default'),
            outboxQueueConnection: (string) config('outbox.queue_connection'),
            inertiaSsrEnabled: (bool) config('inertia.ssr.enabled'),
            writable: $this->writable(),
            publicSensitiveFiles: $this->publicSensitiveFiles(),
            databaseVersion: $version,
            databaseError: $error,
            pendingMigrations: $error === null ? $this->pendingMigrations() : null,
            healthTokenSet: (string) config('observability.health_token') !== '',
            idempotencyKeySet: (string) config('idempotency.hash_key') !== ''
                && config('idempotency.hash_key') !== config('app.key'),
            backupConfigured: (string) config('backup.path') !== '' && (string) config('backup.encryption_key') !== '',
        );
    }

    /** @return array<string, bool> */
    private function writable(): array
    {
        $directories = [
            'storage' => storage_path(),
            'storage/logs' => storage_path('logs'),
            'storage/framework' => storage_path('framework'),
            'bootstrap/cache' => base_path('bootstrap/cache'),
        ];

        return array_map(static fn (string $path): bool => is_dir($path) && is_writable($path), $directories);
    }

    /** @return list<string> */
    private function publicSensitiveFiles(): array
    {
        $found = [];

        foreach (self::SENSITIVE as $file) {
            if (file_exists(public_path($file))) {
                $found[] = $file;
            }
        }

        foreach (glob(public_path('*.sql')) ?: [] as $dump) {
            $found[] = basename($dump);
        }

        return $found;
    }

    /** @return array{0: ?string, 1: ?string} version, error (never includes credentials) */
    private function database(): array
    {
        try {
            $version = DB::selectOne('select version() as version');

            return [$version === null ? null : (string) $version->version, null];
        } catch (Throwable $exception) {
            // Class only: driver messages can echo the host and user name.
            return [null, $exception::class];
        }
    }

    private function pendingMigrations(): ?int
    {
        try {
            $repository = $this->migrator->getRepository();

            if (! $repository->repositoryExists()) {
                return count($this->migrator->getMigrationFiles([database_path('migrations')]));
            }

            return count(array_diff(
                array_keys($this->migrator->getMigrationFiles([database_path('migrations')])),
                $repository->getRan(),
            ));
        } catch (Throwable) {
            return null;
        }
    }
}
