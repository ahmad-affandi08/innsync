<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Storage of customers, receivables, receipts and collection notes. Rows are plain arrays; every query is scoped to the property. */
interface ReceivableStore
{
    /** @return list<array<string, mixed>> customers by name */
    public function customers(PropertyId $property): array;

    /** @return array<string, mixed>|null */
    public function customer(PropertyId $property, string $id): ?array;

    /** @return array<string, mixed>|null the customer made for a front office company */
    public function customerOfCompany(PropertyId $property, string $companyId): ?array;

    /** @param array<string, mixed> $row @return bool false when the code or the company is taken */
    public function addCustomer(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $fields @return bool false when the customer changed meanwhile */
    public function updateCustomer(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    public function hasReceivable(PropertyId $property, string $sourceType, string $sourceId): bool;

    /** @param array<string, mixed> $row @return bool false when this source already made a receivable */
    public function addReceivable(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> every receivable with what was received, its customer and its latest promise; oldest due first */
    public function receivables(PropertyId $property, ?string $customerId): array;

    /** @return array<string, mixed>|null with `received_minor`, its `receipts` and its `notes` (newest first) */
    public function receivable(PropertyId $property, string $id): ?array;

    /** Locks a receivable for the rest of the transaction, so receipts against it run one after the other. */
    public function lockReceivable(PropertyId $property, string $id): void;

    /** @return array<string, mixed>|null a receipt row (of any kind) with its receivable's `source_type`, `number` as `receivable_number`, `customer_code` and, when it was reversed, `reversal_number` */
    public function receipt(PropertyId $property, string $id): ?array;

    /** @param array<string, mixed> $row @return bool false when the receipt number is taken or the receipt was reversed already */
    public function addReceipt(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $row */
    public function addNote(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return list<array<string, mixed>> receivables issued by the date that still had something owed at its end, with what was received by then */
    public function outstandingAsOf(PropertyId $property, string $asOf): array;
}
