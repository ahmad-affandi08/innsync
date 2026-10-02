<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Storage of the inventory catalog and the stock ledger. Rows are plain arrays; every query is scoped to the property. */
interface InventoryStore
{
    /** @return list<array<string, mixed>> */
    public function categories(PropertyId $property): array;

    /** @return array<string, mixed>|null */
    public function category(PropertyId $property, string $id): ?array;

    /** @param array<string, mixed> $row */
    public function addCategory(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    public function updateCategory(PropertyId $property, string $id, int $lock, string $name, bool $active, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> */
    public function locations(PropertyId $property): array;

    /** @return array<string, mixed>|null */
    public function location(PropertyId $property, string $id): ?array;

    /** @param array<string, mixed> $row */
    public function addLocation(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    public function updateLocation(PropertyId $property, string $id, int $lock, string $name, string $kind, bool $active, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> with the category name */
    public function items(PropertyId $property): array;

    /** @return array<string, mixed>|null */
    public function item(PropertyId $property, string $id): ?array;

    /** @param array<string, mixed> $row */
    public function addItem(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    public function updateItem(PropertyId $property, string $id, int $lock, string $name, string $categoryId, string $department, bool $active, DateTimeImmutable $at): bool;

    /** Locks the item row until the transaction ends, so two postings for one item run one after the other. */
    public function lockItem(PropertyId $property, string $id): void;

    /** @return list<array<string, mixed>> every version of every unit of every item, oldest first */
    public function unitVersions(PropertyId $property): array;

    /** @return array<string, mixed>|null the newest version of one unit of an item */
    public function currentUnit(PropertyId $property, string $itemId, string $unit): ?array;

    /** @param array<string, mixed> $row */
    public function addUnitVersion(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return list<array<string, mixed>> */
    public function limits(PropertyId $property): array;

    /** @return array<string, mixed>|null */
    public function limit(PropertyId $property, string $itemId, string $locationId): ?array;

    /** @return bool false when the limits changed after the caller read them */
    public function saveLimit(PropertyId $property, string $id, string $itemId, string $locationId, int $minMilli, ?int $maxMilli, ?int $expectedLock, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $row */
    public function addMovement(PropertyId $property, array $row, DateTimeImmutable $at): void;

    public function movementCount(PropertyId $property, string $itemId, string $locationId): int;

    /** @return list<array<string, mixed>> balance in base units per item and location that has a movement or a limit */
    public function balances(PropertyId $property, ?string $itemId, ?string $locationId): array;

    /** @return list<array<string, mixed>> newest first */
    public function movements(PropertyId $property, ?string $itemId, ?string $locationId, int $limit): array;
}
