<?php

declare(strict_types=1);

namespace App\Shared\Application\Backup;

use RuntimeException;

/** Messages are fixed, operator-safe strings: no credentials, paths with secrets, or tool output. */
final class BackupFailed extends RuntimeException
{
    public static function notConfigured(string $what): self
    {
        return new self(sprintf('Backup is not configured: %s.', $what));
    }

    public static function unsafeDestination(): self
    {
        return new self('The backup destination must be an existing absolute directory outside the application tree.');
    }

    public static function toolFailed(string $tool, int $exitCode): self
    {
        return new self(sprintf('%s failed with exit code %d.', $tool, $exitCode));
    }

    public static function integrity(string $what): self
    {
        return new self(sprintf('Backup integrity check failed: %s.', $what));
    }

    public static function noBackupSet(): self
    {
        return new self('No backup set is available.');
    }

    public static function unsafeRestoreTarget(): self
    {
        return new self('The restore-test database must be a dedicated scratch database ending in _restore_test and differ from the live database.');
    }
}
