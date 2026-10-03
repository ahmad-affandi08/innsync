<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface EmployeeStore
{
    /** @param array<string, mixed> $row */
    public function addEmployee(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @return array<string, mixed>|null the employee with the name of their supervisor (`supervisor_name`) */
    public function employee(PropertyId $property, string $id): ?array;

    /** @return list<array<string, mixed>> by name, optionally of one status */
    public function employees(PropertyId $property, ?string $status): array;

    public function lockEmployee(PropertyId $property, string $id): void;

    /** @param array<string, mixed> $fields */
    public function updateEmployee(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** @return list<string> ids of the active employees who have this person as their supervisor */
    public function subordinatesOf(PropertyId $property, string $id): array;

    /** Gives the active employees of one supervisor to another. @return int how many */
    public function reassign(PropertyId $property, string $from, string $to, DateTimeImmutable $at): int;

    /** The employee the account belongs to, or null. */
    public function employeeOfUser(PropertyId $property, string $userId): ?string;

    /** @param array<string, mixed> $row */
    public function addDocument(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return list<array<string, mixed>> the papers of an employee, newest first */
    public function documents(PropertyId $property, string $employeeId): array;

    /** @return array<string, mixed>|null */
    public function document(PropertyId $property, string $id): ?array;

    public function retireDocument(PropertyId $property, string $id, DateTimeImmutable $at): void;

    /** @return list<array<string, mixed>> the current papers of active employees that have a date they hold until, with the employee's number and name */
    public function expiringDocuments(PropertyId $property, string $until): array;

    /** @return array<string, list<string>> the kinds of current papers each active employee has */
    public function currentKinds(PropertyId $property): array;

    /** @param array<string, mixed> $row */
    public function addOffboardItem(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return list<array<string, mixed>> */
    public function offboardItems(PropertyId $property, string $employeeId): array;

    /** @return list<string> the file ids of every paper of an employee */
    public function fileIdsOf(PropertyId $property, string $employeeId): array;

    /** @return array<string, mixed>|null */
    public function settings(PropertyId $property): ?array;

    /** @param array<string, mixed> $values */
    public function saveSettings(PropertyId $property, array $values, ?int $expectedLock, string $by, DateTimeImmutable $at): bool;
}
