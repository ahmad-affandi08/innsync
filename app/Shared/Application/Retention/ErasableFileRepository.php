<?php

declare(strict_types=1);

namespace App\Shared\Application\Retention;

use App\Shared\Application\Files\StoredFile;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface ErasableFileRepository
{
    /** Files whose retention ended and whose blob is still present, oldest first. @return list<StoredFile> */
    public function due(PropertyId $property, DateTimeImmutable $now, int $limit): array;

    /** @return bool false when the file was already tombstoned */
    public function tombstone(PropertyId $property, string $fileId, string $reason, DateTimeImmutable $at): bool;
}
