<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Storage of the bills, their lines and the sends to the stations. Rows are plain arrays; every query is scoped to the property. */
interface BillStore
{
    /** @param array<string, mixed> $row @return bool false when the table has an open bill already */
    public function addBill(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @return array<string, mixed>|null the bill with its `lines` (oldest first) */
    public function bill(PropertyId $property, string $id): ?array;

    /** Locks a bill for the rest of the transaction, so two devices change it one after the other. */
    public function lockBill(PropertyId $property, string $id): void;

    /** Moves the bill to its next version; false when its version is not the one the caller saw. */
    public function touchBill(PropertyId $property, string $id, int $lock, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> open bills of an outlet with what is on them, newest first */
    public function openBills(PropertyId $property, string $outletId): array;

    /** @param array<string, mixed> $row */
    public function addLine(PropertyId $property, string $billId, array $row, DateTimeImmutable $at): void;

    /** @param array<string, mixed> $fields */
    public function updateLine(PropertyId $property, string $billId, string $lineId, array $fields, DateTimeImmutable $at): void;

    /** Sends the pending lines of a bill: a new batch takes them. @return int the lines sent */
    public function sendPending(PropertyId $property, string $billId, string $batchId, string $by, DateTimeImmutable $at): int;

    /** @return int number the next send of the bill carries */
    public function nextBatchNumber(PropertyId $property, string $billId): int;

    /** @param array<string, mixed> $fields */
    public function updateBill(PropertyId $property, string $billId, array $fields, DateTimeImmutable $at): void;

    /** Payments of a bill that count or may still count: every one that is not failed or expired. */
    public function paymentCount(PropertyId $property, string $billId): int;
}
