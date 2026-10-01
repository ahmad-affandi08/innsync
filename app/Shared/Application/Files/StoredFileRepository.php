<?php

declare(strict_types=1);

namespace App\Shared\Application\Files;

use App\Shared\Domain\Tenancy\PropertyId;

interface StoredFileRepository
{
    /** Lowercase ULID. */
    public function nextIdentity(): string;

    public function add(StoredFile $file): void;

    /**
     * Gives a file its expiry when it has none yet; an expiry already set is never changed.
     *
     * @return bool false when the file already had an expiry, was erased, or does not exist
     */
    public function setExpiryOnce(PropertyId $propertyId, string $fileId, \DateTimeImmutable $expiresAt): bool;

    /** @throws StoredFileNotFound */
    public function find(PropertyId $propertyId, string $fileId): StoredFile;
}
