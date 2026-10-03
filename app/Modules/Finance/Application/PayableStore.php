<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Storage of expense accounts, payables, supplier credits and payments. Rows are plain arrays; every query is scoped to the property. */
interface PayableStore
{
    /** @return list<array<string, mixed>> */
    public function accounts(PropertyId $property): array;

    /** @return array<string, mixed>|null */
    public function account(PropertyId $property, string $id): ?array;

    /** @param array<string, mixed> $row @return bool false when the code is taken */
    public function addAccount(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $fields @return bool false when the account changed meanwhile */
    public function updateAccount(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $row @return bool false when this source already made a payable */
    public function addPayable(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $row @return bool false when this source already made a credit */
    public function addCredit(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /**
     * Every payable of the property with what was paid (status paid), what waits for approval, and what credits were applied to it.
     *
     * @return list<array<string, mixed>>
     */
    public function payables(PropertyId $property, ?string $supplierId): array;

    /** @return array<string, mixed>|null with `paid_minor`, `pending_minor`, `credit_minor`, its payments (with proofs) and the credits applied */
    public function payable(PropertyId $property, string $id): ?array;

    /** Locks a payable for the rest of the transaction, so payments and credits against it run one after the other. */
    public function lockPayable(PropertyId $property, string $id): void;

    public function classify(PropertyId $property, string $id, ?string $accountId): void;

    /** @return list<array<string, mixed>> credits with what is still unapplied, newest first */
    public function credits(PropertyId $property, ?string $supplierId): array;

    /** @return array<string, mixed>|null */
    public function credit(PropertyId $property, string $id): ?array;

    /** Locks a credit for the rest of the transaction. */
    public function lockCredit(PropertyId $property, string $id): void;

    /** @param array<string, mixed> $row */
    public function addCreditApplication(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /**
     * @param  array<string, mixed>  $row
     * @return bool false when the payment number is taken
     */
    public function addPayment(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> newest first */
    public function payments(PropertyId $property, ?string $status, int $limit): array;

    /** @return array<string, mixed>|null with its proofs */
    public function payment(PropertyId $property, string $id): ?array;

    /** @param array<string, mixed> $fields @return bool false when the payment changed meanwhile */
    public function updatePayment(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $row */
    public function addProof(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /**
     * What was still owed on each payable at the end of a date: paid payments up to it and credits applied up to it are taken off.
     *
     * @return list<array<string, mixed>> only payables issued on or before the date with something outstanding
     */
    public function outstandingAsOf(PropertyId $property, string $asOf): array;
}
