<?php

declare(strict_types=1);

namespace App\Shared\Application\Files;

interface PrivateFileStorage
{
    /** Encrypts and writes the bytes under a server-generated key. */
    public function put(string $storageKey, string $contents): void;

    /** @throws StoredFileUnreadable */
    public function get(string $storageKey): string;

    /** Removes a blob this process wrote but never recorded. Not a retention mechanism. */
    public function discardUnrecorded(string $storageKey): void;
}
