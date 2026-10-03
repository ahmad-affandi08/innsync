<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Storage of the QR codes of the rooms and tables. Rows are plain arrays. */
interface QrPointStore
{
    /** @return list<array<string, mixed>> the codes of a property, rooms first, by label */
    public function all(PropertyId $property): array;

    /** @return array<string, mixed>|null */
    public function find(PropertyId $property, string $id): ?array;

    /** @return array<string, mixed>|null the code of a room or a table */
    public function byTarget(PropertyId $property, string $kind, string $targetId): ?array;

    /** @return array<string, mixed>|null the code a token opens; the property is not known before it is found, so this alone is not scoped */
    public function byTokenHash(string $hash): ?array;

    /** @param array<string, mixed> $row @return bool false when the room or the table has a code already */
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $fields @return bool false when the code changed meanwhile */
    public function update(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;
}
