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

    public function updateCategory(PropertyId $property, string $id, int $lock, string $name, bool $active, bool $negativeBlocked, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> */
    public function locations(PropertyId $property): array;

    /** @return array<string, mixed>|null */
    public function location(PropertyId $property, string $id): ?array;

    /** @param array<string, mixed> $row */
    public function addLocation(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    public function updateLocation(PropertyId $property, string $id, int $lock, string $name, string $kind, bool $active, bool $negativeBlocked, DateTimeImmutable $at): bool;

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

    /** The balance in base units of an item in a location (the sum of its movements). */
    public function balanceOf(PropertyId $property, string $itemId, string $locationId): int;

    /**
     * The whole stock of an item in the property, over every location: quantity in base thousandths and its value in minor units.
     *
     * @return array{qty_milli: int, value_minor: int}
     */
    public function pool(PropertyId $property, string $itemId): array;

    /**
     * The most recent receipt, opening or adjustment-in of an item that carried a value: its value and base quantity, for costing an outflow when no stock is left to average.
     *
     * @return array{value_minor: int, base_qty_milli: int}|null
     */
    public function lastInflowCost(PropertyId $property, string $itemId): ?array;

    /**
     * Quantity and value per item and location of everything posted up to and including a business date.
     *
     * @return list<array{item_id: string, location_id: string, qty_milli: int, value_minor: int}>
     */
    public function valuation(PropertyId $property, string $asOf): array;

    /** @return array<string, mixed>|null the movement a source document already posted for an item and a location */
    public function movementBySource(PropertyId $property, string $sourceType, string $sourceRef, string $itemId, string $locationId): ?array;

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $lines
     */
    public function addTransfer(PropertyId $property, array $row, array $lines, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> newest first, each with its lines */
    public function transfers(PropertyId $property, ?string $status, int $limit): array;

    /** @return array<string, mixed>|null with its lines */
    public function transfer(PropertyId $property, string $id): ?array;

    /** @return bool false when the transfer changed or was already decided */
    public function decideTransfer(PropertyId $property, string $id, int $lock, string $status, string $actorId, ?string $note, DateTimeImmutable $at): bool;

    /** Base quantity of an item already sent back by return transfers of a transfer (not cancelled or rejected). */
    public function returnedByTransfer(PropertyId $property, string $transferId, string $itemId): int;

    /** @param array<string, mixed> $row */
    public function addLot(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /**
     * Takes `$baseQty` thousandths out of the lots of an item in a location, the batch that expires first, then the one with no expiry; stock that is in no lot is left alone.
     *
     * @return int how much was taken out of lots
     */
    public function consumeLots(PropertyId $property, string $itemId, string $locationId, int $baseQty): int;

    /** @return list<array<string, mixed>> the lots that still hold stock, with the item and the location, earliest expiry first */
    public function lots(PropertyId $property, ?string $itemId, ?string $locationId, ?string $department): array;

    /** @return list<array<string, mixed>> the issues a department made, newest first, with the item and the location */
    public function issuesOfDepartment(PropertyId $property, string $department, int $limit): array;
}
