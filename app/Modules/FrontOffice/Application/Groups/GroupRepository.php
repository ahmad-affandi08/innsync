<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Groups;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface GroupRepository
{
    /** @param array<string, mixed> $row */
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): void;

    public function addMember(PropertyId $property, string $groupId, string $reservationId, int $line, string $actorId, DateTimeImmutable $at): void;

    public function markMasterFolio(PropertyId $property, string $groupId, string $folioId, DateTimeImmutable $at): void;

    /** @return array<string, mixed>|null */
    public function find(PropertyId $property, string $id): ?array;

    /** @return list<array<string, mixed>> newest first, with the number of rooms and the master folio's balance */
    public function search(PropertyId $property, ?string $query, int $limit): array;

    /** @return list<array<string, mixed>> the rooms of a group in the order they were added */
    public function members(PropertyId $property, string $groupId): array;

    /** @return array<string, mixed>|null the group a reservation belongs to */
    public function groupOf(PropertyId $property, string $reservationId): ?array;

    public function masterFolioId(PropertyId $property, string $groupId): ?string;

    public function isMasterFolio(PropertyId $property, string $folioId): bool;

    public function lastLine(PropertyId $property, string $groupId): int;
}
