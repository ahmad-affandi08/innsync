<?php

declare(strict_types=1);

namespace App\Shared\Application\Files;

use App\Shared\Domain\Tenancy\PropertyId;

interface StoredFileRepository
{
    /** Lowercase ULID. */
    public function nextIdentity(): string;

    public function add(StoredFile $file): void;

    /** @throws StoredFileNotFound */
    public function find(PropertyId $propertyId, string $fileId): StoredFile;
}
