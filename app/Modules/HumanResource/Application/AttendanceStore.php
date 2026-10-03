<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface AttendanceStore
{
    /** @return array<string, mixed>|null */
    public function settings(PropertyId $property): ?array;

    /** @param array<string, mixed> $values */
    public function saveSettings(PropertyId $property, array $values, ?int $expectedLock, string $by, DateTimeImmutable $at): bool;

    /** @return array<string, mixed>|null */
    public function record(PropertyId $property, string $employeeId, string $date): ?array;

    /** @return array<string, mixed>|null */
    public function recordById(PropertyId $property, string $id): ?array;

    /** @param array<string, mixed> $row */
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $fields */
    public function update(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> the records of the days between two dates, optionally of one employee */
    public function between(PropertyId $property, string $from, string $to, ?string $employeeId): array;
}
