<?php

declare(strict_types=1);

namespace App\Shared\Application\Retention;

use App\Shared\Application\Files\StoredFile;
use DateTimeImmutable;

/** Blocks erasure while a dispute, audit or official request needs the data. Scope: whole property, one purpose, or one owner. */
final readonly class LegalHold
{
    public const PROPERTY = 'property';

    public const PURPOSE = 'purpose';

    public const OWNER = 'owner';

    public function __construct(
        public string $id,
        public string $scopeType,
        public ?string $purpose,
        public ?string $ownerType,
        public ?string $ownerId,
        public string $reason,
        public string $placedBy,
        public DateTimeImmutable $placedAt,
        public ?DateTimeImmutable $releasedAt,
    ) {}

    public function isActive(): bool
    {
        return $this->releasedAt === null;
    }

    public function covers(StoredFile $file): bool
    {
        return $this->isActive() && match ($this->scopeType) {
            self::PROPERTY => true,
            self::PURPOSE => $this->purpose === $file->purpose,
            self::OWNER => $this->ownerType === $file->ownerType && $this->ownerId === $file->ownerId,
            default => false,
        };
    }
}
