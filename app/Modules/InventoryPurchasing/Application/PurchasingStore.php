<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Storage of suppliers and purchasing documents. Rows are plain arrays; every query is scoped to the property. */
interface PurchasingStore
{
    /** @return list<array<string, mixed>> each with the average rating and the number of ratings */
    public function suppliers(PropertyId $property): array;

    /** @return array<string, mixed>|null */
    public function supplier(PropertyId $property, string $id): ?array;

    /** @param array<string, mixed> $row @return bool false when the code is taken */
    public function addSupplier(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $fields @return bool false when the supplier changed meanwhile */
    public function updateSupplier(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $row */
    public function addSupplierPrice(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** Every price version of a supplier, newest first. @return list<array<string, mixed>> */
    public function supplierPrices(PropertyId $property, string $supplierId): array;

    /** The price in force on a date for an item in a unit, or null. @return array<string, mixed>|null */
    public function priceOn(PropertyId $property, string $supplierId, string $itemId, string $unit, string $date): ?array;

    /** @param array<string, mixed> $row */
    public function addSupplierRating(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return list<array<string, mixed>> newest first */
    public function supplierRatings(PropertyId $property, string $supplierId, int $limit): array;

    /** The purchasing settings of the property, created with their baselines on first use. @return array<string, mixed> */
    public function settings(PropertyId $property, DateTimeImmutable $at): array;

    /** @param array<string, mixed> $fields @return bool false when the settings changed meanwhile */
    public function updateSettings(PropertyId $property, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> the budgets of a period, or of all periods when null */
    public function budgets(PropertyId $property, ?string $period): array;

    /** @return array<string, mixed>|null */
    public function budget(PropertyId $property, string $department, string $period): ?array;

    /** Sets the budget of a department for a month; a changed budget needs the version it was read at. @return bool false when it changed meanwhile */
    public function setBudget(PropertyId $property, string $id, string $department, string $period, int $amountMinor, ?int $lock, string $actorId, DateTimeImmutable $at): bool;

    /** What purchase orders (not draft, not cancelled) have committed for a department in a month, before tax, optionally leaving one order out. */
    public function committed(PropertyId $property, string $department, string $period, ?string $exceptOrderId): int;

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $lines
     */
    public function addRequest(PropertyId $property, array $row, array $lines, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> newest first, each with the number of lines */
    public function requests(PropertyId $property, ?string $status, int $limit): array;

    /** @return array<string, mixed>|null with its lines */
    public function request(PropertyId $property, string $id): ?array;

    /** @param array<string, mixed> $fields @return bool false when the request changed meanwhile */
    public function updateRequest(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** @param list<array<string, mixed>> $lines */
    public function replaceRequestLines(string $requestId, array $lines): void;

    public function linkRequestLine(string $lineId, ?string $orderId, ?string $orderLineId): void;

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $lines
     */
    public function addOrder(PropertyId $property, array $row, array $lines, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> newest first */
    public function orders(PropertyId $property, ?string $status, ?string $supplierId, int $limit): array;

    /** @return array<string, mixed>|null with its lines */
    public function order(PropertyId $property, string $id): ?array;

    /** @param array<string, mixed> $fields @return bool false when the order changed meanwhile */
    public function updateOrder(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** Replaces the lines of a draft order. @param list<array<string, mixed>> $lines */
    public function replaceOrderLines(string $orderId, array $lines): void;

    /** @param array<string, mixed> $fields */
    public function updateOrderLine(string $lineId, array $fields): void;

    /** @param array<string, mixed> $line */
    public function addOrderLine(string $orderId, array $line): void;

    /** @param array<string, mixed> $row */
    public function addOrderRevision(array $row, DateTimeImmutable $at): void;

    /** @return list<array<string, mixed>> oldest first */
    public function orderRevisions(string $orderId): array;

    /** Every price version that has started by a date, newest first, for working out the price in force per supplier, item and unit. @return list<array<string, mixed>> */
    public function startedPrices(PropertyId $property, string $date): array;
}
