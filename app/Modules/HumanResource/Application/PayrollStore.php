<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Where the payroll basis is kept: the kinds of earning, what each person earns of them, their tax profile, and the parameters of the calculation. */
interface PayrollStore
{
    /** @return list<array<string, mixed>> */
    public function components(PropertyId $property, bool $onlyActive): array;

    /** @param array<string, mixed> $row */
    public function addComponent(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @return array<string, mixed>|null */
    public function component(PropertyId $property, string $id): ?array;

    /** @param array<string, mixed> $fields */
    public function updateComponent(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $row  Returns false when the person already has a line of this component from this date. */
    public function addItem(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** Every line of every person, oldest first. @return list<array<string, mixed>> */
    public function items(PropertyId $property, ?string $employeeId): array;

    /** @return array<string, mixed>|null */
    public function profile(PropertyId $property, string $employeeId): ?array;

    /** @return list<array<string, mixed>> */
    public function profiles(PropertyId $property): array;

    /** @param array<string, mixed> $values */
    public function saveProfile(PropertyId $property, string $employeeId, array $values, ?int $expectedLock, string $by, DateTimeImmutable $at): bool;

    /** @return array<string, mixed>|null */
    public function settings(PropertyId $property): ?array;

    /** @param array<string, mixed> $values */
    public function saveSettings(PropertyId $property, array $values, ?int $expectedLock, string $by, DateTimeImmutable $at): bool;
}
