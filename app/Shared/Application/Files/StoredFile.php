<?php

declare(strict_types=1);

namespace App\Shared\Application\Files;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

final readonly class StoredFile
{
    public function __construct(
        public string $id,
        public PropertyId $propertyId,
        public string $purpose,
        public string $ownerType,
        public string $ownerId,
        public FileSensitivity $sensitivity,
        public string $storageKey,
        public string $mimeType,
        public int $sizeBytes,
        public string $checksumSha256,
        public ?string $displayName,
        public ?DateTimeImmutable $expiresAt,
        public string $uploadedBy,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $erasedAt = null,
    ) {}

    /** Retention ended and the content was erased; only the metadata remains as evidence. */
    public function isErased(): bool
    {
        return $this->erasedAt !== null;
    }

    public function isExpiredAt(DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }
}
