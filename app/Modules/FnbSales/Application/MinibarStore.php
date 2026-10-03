<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Where the mini bar items, what each room holds, the checks, and the room service orders are kept. */
interface MinibarStore
{
    /** @param array<string, mixed> $row  Returns false when the code is taken. */
    public function addItem(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> */
    public function items(PropertyId $property, bool $onlyActive): array;

    /** @return array<string, mixed>|null */
    public function item(PropertyId $property, string $id): ?array;

    /** @param array<string, mixed> $fields */
    public function updateItem(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** What each room holds now, by room then item. @param list<string>|null $roomIds @return array<string, array<string, int>> */
    public function stock(PropertyId $property, ?array $roomIds): array;

    public function setStock(PropertyId $property, string $roomId, string $itemId, int $qty, DateTimeImmutable $at): void;

    /** @param array<string, mixed> $check @param list<array<string, mixed>> $lines */
    public function addCheck(PropertyId $property, array $check, array $lines, DateTimeImmutable $at): void;

    /** Newest first, each with its lines. @return list<array<string, mixed>> */
    public function checks(PropertyId $property, ?string $roomId, ?string $staffId, ?string $from, ?string $to, int $limit): array;

    /** @param array<string, mixed> $row */
    public function addOrder(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return array<string, mixed>|null the order with the bill's number and state */
    public function order(PropertyId $property, string $id): ?array;

    /** @return array<string, mixed>|null the room service order of a bill, if it has one */
    public function orderOfBill(PropertyId $property, string $billId): ?array;

    /** @param array<string, mixed> $fields */
    public function updateOrder(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** Orders still to deliver, soonest promise first, then those delivered since `$sinceUtc`. @return list<array<string, mixed>> */
    public function orders(PropertyId $property, string $sinceUtc, int $limit): array;
}
