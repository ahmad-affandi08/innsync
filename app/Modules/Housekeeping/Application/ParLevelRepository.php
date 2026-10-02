<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface ParLevelRepository
{
    /** @return list<array<string, mixed>> every par level with the item's code, name and kind */
    public function all(PropertyId $property): array;

    /** @return array<string, mixed>|null */
    public function find(PropertyId $property, string $itemId, string $scopeKind, string $scopeRef): ?array;

    /**
     * Creates the par level, or changes it when it exists.
     *
     * @return bool false when it changed after the caller read it
     */
    public function save(PropertyId $property, string $id, string $itemId, string $scopeKind, string $scopeRef, int $par, int $use, ?int $expectedLockVersion, string $actorId, DateTimeImmutable $at): bool;

    /** @return list<string> the areas that already have a par level */
    public function areas(PropertyId $property): array;

    /** @return array<string, int> finished room services by room type between the two instants */
    public function servicedRooms(PropertyId $property, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): array;

    /** @return array<string, array<string, int>> what was used, by item then room type, between the two instants */
    public function usedIn(PropertyId $property, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): array;
}
