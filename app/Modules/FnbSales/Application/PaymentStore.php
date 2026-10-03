<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Storage of the cashier shifts and of the payments taken for bills. Rows are plain arrays; every query is scoped to the property. */
interface PaymentStore
{
    /** @param array<string, mixed> $row @return bool false when the cashier has an open shift already */
    public function addShift(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @return array<string, mixed>|null */
    public function shift(PropertyId $property, string $id): ?array;

    /** @return array<string, mixed>|null the open shift of a cashier */
    public function openShiftOf(PropertyId $property, string $cashierId): ?array;

    /** Locks a shift for the rest of the transaction. */
    public function lockShift(PropertyId $property, string $id): void;

    /** @param array<string, mixed> $fields @return bool false when the shift changed meanwhile */
    public function closeShift(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> shifts, newest first */
    public function shifts(PropertyId $property, ?string $status, int $limit): array;

    /** @return list<array{method: string, status: string, count: int, amount_minor: int, change_minor: int}> what a shift took, by method and status */
    public function shiftTotals(PropertyId $property, string $shiftId): array;

    /** Payments of the shift that are not decided yet (initiated or pending). */
    public function unresolvedCount(PropertyId $property, string $shiftId): int;

    /** @param array<string, mixed> $row */
    public function addPayment(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return array<string, mixed>|null */
    public function payment(PropertyId $property, string $id): ?array;

    /** @return list<array<string, mixed>> the payments of a bill, oldest first */
    public function paymentsOf(PropertyId $property, string $billId): array;

    /** @param array<string, mixed> $fields */
    public function updatePayment(PropertyId $property, string $id, array $fields, DateTimeImmutable $at): void;

    /** Settles a bill: its status and the totals it came to. @param array<string, mixed> $fields */
    public function settleBill(PropertyId $property, string $billId, array $fields, DateTimeImmutable $at): void;

    /**
     * A refund of a whole bill and the payments it gives back.
     *
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $payments
     */
    public function addRefund(PropertyId $property, array $row, array $payments, DateTimeImmutable $at): void;

    /** @return array<string, mixed>|null the refund of a bill with its `payments` */
    public function refundOf(PropertyId $property, string $billId): ?array;

    /** @return array<string, int> what the refunds made in a shift paid back, by method */
    public function shiftRefunds(PropertyId $property, string $shiftId): array;
}
