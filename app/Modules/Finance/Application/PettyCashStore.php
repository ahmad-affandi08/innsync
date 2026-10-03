<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Storage of petty cash funds, their ledger, vouchers, proofs and settlements. Rows are plain arrays; every query is scoped to the property. */
interface PettyCashStore
{
    /** @return list<array<string, mixed>> funds by code, each with `balance_minor`, `unsettled_count`, `unsettled_minor` and `pending_settlement_id`; limited to a custodian when given */
    public function funds(PropertyId $property, ?string $custodianId): array;

    /** @return array<string, mixed>|null the fund with the same figures as `funds` */
    public function fund(PropertyId $property, string $id): ?array;

    /** Locks a fund for the rest of the transaction, so vouchers and settlements against it run one after the other. */
    public function lockFund(PropertyId $property, string $id): void;

    /** @param array<string, mixed> $row @return bool false when the code is taken */
    public function addFund(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $fields @return bool false when the fund changed meanwhile */
    public function updateFund(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $row the entry without its sequence number, which is the next of the fund */
    public function addEntry(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return list<array<string, mixed>> newest first */
    public function entries(PropertyId $property, string $fundId, int $limit): array;

    /** @return list<array<string, mixed>> the active expense accounts by code */
    public function activeAccounts(PropertyId $property): array;

    /** @return array<string, mixed>|null the expense account when it belongs to the property */
    public function account(PropertyId $property, string $id): ?array;

    /** @param array<string, mixed> $row @return bool false when the number is taken */
    public function addVoucher(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> newest first, with `proof_count`, `voided` and the settlement it is in (`settlement_number`, `settlement_status`) */
    public function vouchers(PropertyId $property, string $fundId, int $limit): array;

    /** @return array<string, mixed>|null the voucher with the same figures and its `proofs` */
    public function voucher(PropertyId $property, string $id): ?array;

    /** @return list<array<string, mixed>> the vouchers not voided and not in a settlement that waits or was approved, with `proof_count`, oldest first */
    public function unsettledVouchers(PropertyId $property, string $fundId): array;

    /** @param array<string, mixed> $row */
    public function addProof(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @param array<string, mixed> $row */
    public function addVoid(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $voucherIds
     * @return bool false when the number is taken or the fund already has a settlement waiting
     */
    public function addSettlement(PropertyId $property, array $row, array $voucherIds, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> newest first */
    public function settlements(PropertyId $property, string $fundId): array;

    /** @return array<string, mixed>|null with its fund fields and its `vouchers` */
    public function settlement(PropertyId $property, string $id): ?array;

    /** @return bool false when it was decided meanwhile */
    public function decideSettlement(PropertyId $property, string $id, int $lock, string $status, string $by, ?string $note, DateTimeImmutable $at): bool;
}
