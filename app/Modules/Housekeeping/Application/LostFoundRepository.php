<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface LostFoundRepository
{
    /** @param array<string, mixed> $row */
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return array<string, mixed>|null */
    public function find(PropertyId $property, string $id): ?array;

    /** @return list<array<string, mixed>> newest first */
    public function list(PropertyId $property, ?string $status, int $limit): array;

    public function close(PropertyId $property, string $id, int $expectedLockVersion, string $status, ?string $returnedTo, ?string $note, string $actorId, DateTimeImmutable $at): bool;
}
