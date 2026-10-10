<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** The bank accounts people are paid into. The number is sealed at rest; only `numbers` gives it back, for the transfer file. */
interface PayrollBankStore
{
    /** What is on screen: the bank, the holder and the last four digits, by employee. @return array<string, array{bank_name: string, account_holder: string, account_last4: string, lock_version: int}> */
    public function accounts(PropertyId $property): array;

    /** The numbers in full, by employee, of the given people. @param list<string> $employeeIds @return array<string, array{bank_name: string, account_holder: string, account_number: string}> */
    public function numbers(PropertyId $property, array $employeeIds): array;

    /** Adds the account when `$lock` is null, otherwise changes it when the lock matches. False when the person has an account already, or the lock is stale. */
    public function save(PropertyId $property, string $employeeId, string $bankName, string $holder, string $number, ?int $lock, string $by, DateTimeImmutable $at): bool;
}
