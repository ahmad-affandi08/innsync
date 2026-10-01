<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Cashier;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface CashierRepository
{
    /** @return array{require_open_shift: bool, lock_version: int} */
    public function settings(PropertyId $property): array;

    public function saveSettings(PropertyId $property, bool $requireOpenShift, int $expectedLockVersion, string $actorId, DateTimeImmutable $now): bool;

    /** @return bool false when the person already has an open shift */
    public function open(PropertyId $property, string $id, string $number, string $cashierId, string $currency, DateTimeImmutable $at, string $businessDate, int $floatMinor): bool;

    /** @return array<string, mixed>|null */
    public function find(PropertyId $property, string $id): ?array;

    /** @return array<string, mixed>|null */
    public function openOf(PropertyId $property, string $cashierId): ?array;

    /** @return list<array<string, mixed>> newest first */
    public function search(PropertyId $property, ?string $status, ?string $cashierId, int $limit): array;

    /** @return list<array{id: string, number: string, cashier_id: string}> */
    public function openShifts(PropertyId $property, int $limit): array;

    /** @return 'added'|'duplicate' */
    public function addDrop(PropertyId $property, string $shiftId, string $id, int $amountMinor, ?string $reference, ?string $note, string $actorId, DateTimeImmutable $at): string;

    /** @return array<string, mixed>|null */
    public function dropByReference(PropertyId $property, string $shiftId, string $reference): ?array;

    /** @return list<array<string, mixed>> oldest first */
    public function drops(PropertyId $property, string $shiftId): array;

    public function attribute(PropertyId $property, string $shiftId, string $postingId, DateTimeImmutable $at): void;

    /**
     * Money through the shift per payment method: received (payments), paid back (refunds and reversals of payments).
     *
     * @return list<array{method: string, received_minor: int, paid_back_minor: int, count: int}>
     */
    public function receipts(PropertyId $property, string $shiftId): array;

    /** @param array<string, mixed> $totals */
    public function close(PropertyId $property, string $shiftId, int $expectedLockVersion, string $closedBy, DateTimeImmutable $at, string $businessDate, int $expectedCash, int $countedCash, ?string $reason, array $totals): bool;
}
