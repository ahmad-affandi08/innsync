<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Backup;

use App\Shared\Application\Backup\BackupFailed;

final readonly class BackupSettings
{
    public function __construct(
        public string $path,
        public BackupCipher $cipher,
    ) {}

    public static function fromConfig(): self
    {
        $path = config('backup.path');

        if (! is_string($path) || $path === '') {
            throw BackupFailed::notConfigured('BACKUP_PATH');
        }

        $real = realpath($path);
        $app = realpath(base_path());

        if ($real === false || ! is_dir($real) || ! is_writable($real)
            || ! str_starts_with($path, '/') || $app === false
            || $real === $app || str_starts_with($real.'/', $app.'/')) {
            throw BackupFailed::unsafeDestination();
        }

        return new self($real, BackupCipher::fromConfig());
    }

    public static function isConfigured(): bool
    {
        try {
            self::fromConfig();

            return true;
        } catch (BackupFailed) {
            return false;
        }
    }
}
