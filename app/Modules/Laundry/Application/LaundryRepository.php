<?php

declare(strict_types=1);

namespace App\Modules\Laundry\Application;

use App\Modules\Laundry\Domain\LaundryOrder;
use App\Modules\Laundry\Domain\LaundryStatus;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface LaundryRepository
{
    public const CREATED = 'created';

    /** The bag tag is already on an order that is still active. */
    public const BAG_BUSY = 'bag_busy';

    /** @return list<array{id: string, code: string, name: string, unit_price_minor: int, is_active: bool, lock_version: int}> */
    public function priceItems(PropertyId $property, bool $activeOnly): array;

    /** @return array{id: string, code: string, name: string, unit_price_minor: int, is_active: bool, lock_version: int}|null */
    public function findPriceItem(PropertyId $property, string $id): ?array;

    /** @return bool false when the code is already used */
    public function addPriceItem(PropertyId $property, string $id, string $code, string $name, int $unitPriceMinor, DateTimeImmutable $at): bool;

    /** @return bool false when someone changed the item first */
    public function updatePriceItem(PropertyId $property, string $id, string $name, int $unitPriceMinor, bool $active, int $expectedLockVersion, DateTimeImmutable $at): bool;

    /** @return 'created'|'bag_busy' */
    public function addOrder(PropertyId $property, LaundryOrder $order, string $createdBy, DateTimeImmutable $at): string;

    public function findOrder(PropertyId $property, string $id): ?LaundryOrder;

    /**
     * Writes the order's status, flags and counted quantities if the lock version still matches.
     *
     * @param  array<string, mixed>  $extra  columns set together with the change (who processed, delivery receipt, cancel reason, currency)
     * @return bool false when someone changed the order first
     */
    public function saveOrder(PropertyId $property, LaundryOrder $order, int $expectedLockVersion, array $extra, DateTimeImmutable $at): bool;

    public function logStatus(PropertyId $property, string $orderId, ?LaundryStatus $from, LaundryStatus $to, string $actorId, DateTimeImmutable $at): void;

    /**
     * @param  list<LaundryStatus>  $statuses
     * @return list<LaundryOrder> orders in these statuses: express first, then the earliest promise
     */
    public function orders(PropertyId $property, array $statuses, int $limit): array;

    /** @return list<array{from: ?string, to: string, actor_id: string, occurred_at: string}> oldest first */
    public function history(PropertyId $property, string $orderId): array;

    public function activeOrdersOfStay(PropertyId $property, string $stayId): int;
}
