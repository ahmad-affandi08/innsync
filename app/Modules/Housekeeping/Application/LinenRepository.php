<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface LinenRepository
{
    /** @return bool false when the code is taken */
    public function addItem(PropertyId $property, string $id, string $code, string $name, string $kind, string $unit, DateTimeImmutable $at): bool;

    public function setItemActive(PropertyId $property, string $id, bool $active, int $expectedLockVersion, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> */
    public function items(PropertyId $property): array;

    /** @return array<string, mixed>|null */
    public function findItem(PropertyId $property, string $id): ?array;

    /** Takes the item's row lock so that transfers of one item are decided one at a time. Call inside a transaction. */
    public function lockItem(PropertyId $property, string $id): void;

    /** @param array<string, mixed> $row */
    public function addTransfer(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return array<string, mixed>|null */
    public function findTransfer(PropertyId $property, string $id): ?array;

    /** @return array<string, mixed>|null */
    public function findTransferByKey(PropertyId $property, string $clientKey): ?array;

    /** @return list<array<string, mixed>> newest first */
    public function transfers(PropertyId $property, ?string $status, int $limit): array;

    /** @return bool false when the transfer changed or is no longer pending */
    public function confirm(PropertyId $property, string $id, int $expectedLockVersion, int $received, ?string $varianceKind, ?string $varianceNote, string $actorId, DateTimeImmutable $at): bool;

    public function cancel(PropertyId $property, string $id, int $expectedLockVersion, DateTimeImmutable $at): bool;

    /**
     * Where each item is: counted in each location, on its way, and written off as lost or damaged.
     *
     * @return array<string, array{locations: array<string, int>, in_transit: int, lost: int, damaged: int}> by item id
     */
    public function balances(PropertyId $property): array;

    /** How many of the item are in a location now (confirmed arrivals minus everything sent out, pending included). */
    public function balanceAt(PropertyId $property, string $itemId, string $location): int;

    public function addUsage(PropertyId $property, string $id, string $roomId, string $itemId, int $quantity, string $date, ?string $note, string $actorId, DateTimeImmutable $at): void;

    /** @return list<array{room: string, item: string, item_name: string, usage_date: string, quantity: int}> */
    public function usage(PropertyId $property, string $from, string $to, ?string $roomId): array;
}
