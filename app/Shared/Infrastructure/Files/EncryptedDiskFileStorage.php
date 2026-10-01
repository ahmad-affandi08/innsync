<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Files;

use App\Shared\Application\Files\PrivateFileStorage;
use App\Shared\Application\Files\StoredFileUnreadable;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;
use Throwable;

final readonly class EncryptedDiskFileStorage implements PrivateFileStorage
{
    public function __construct(
        private Factory $disks,
        private Encrypter $encrypter,
    ) {}

    public function put(string $storageKey, string $contents): void
    {
        $this->disk()->put($this->path($storageKey), $this->encrypter->encryptString($contents));
    }

    public function get(string $storageKey): string
    {
        try {
            $encrypted = $this->disk()->get($this->path($storageKey));

            if (! is_string($encrypted)) {
                throw StoredFileUnreadable::detected();
            }

            return $this->encrypter->decryptString($encrypted);
        } catch (StoredFileUnreadable $exception) {
            throw $exception;
        } catch (Throwable) {
            throw StoredFileUnreadable::detected();
        }
    }

    public function discardUnrecorded(string $storageKey): void
    {
        try {
            $this->disk()->delete($this->path($storageKey));
        } catch (Throwable) {
            // Orphan is unreachable (random key, no record); nothing user-visible to report.
        }
    }

    private function disk(): Filesystem
    {
        return $this->disks->disk((string) config('files.disk'));
    }

    /** Fan-out directories keep any single folder small. Key shape is validated, so no traversal. */
    private function path(string $storageKey): string
    {
        if (preg_match('/^[a-f0-9]{64}$/', $storageKey) !== 1) {
            throw new InvalidArgumentException('Invalid storage key.');
        }

        return substr($storageKey, 0, 2).'/'.substr($storageKey, 2, 2).'/'.$storageKey;
    }
}
