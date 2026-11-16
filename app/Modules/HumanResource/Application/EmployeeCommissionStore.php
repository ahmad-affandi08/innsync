<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface EmployeeCommissionStore
{
    /** @param array<string, mixed> $row */
    public function addType(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> */
    public function types(PropertyId $property): array;

    /** @return array<string, mixed>|null */
    public function type(PropertyId $property, string $id): ?array;

    /** @return array<string, mixed>|null */
    public function typeByCode(PropertyId $property, string $code): ?array;

    public function updateType(PropertyId $property, string $id, int $lock, string $name, bool $active, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $row */
    public function addEntry(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @return array<string, mixed>|null */
    public function entry(PropertyId $property, string $id): ?array;

    /** One entry with the person and the kind. @return array<string, mixed>|null */
    public function entryView(PropertyId $property, string $id): ?array;

    /** The entries, newest first, with the person and the kind; for one employee or for all. @return list<array<string, mixed>> */
    public function entries(PropertyId $property, ?string $employeeId, int $limit): array;

    /** The sum of everything one person earned, by kind, since the beginning. @return list<array{type_id: string, code: string, name: string, total_minor: int}> */
    public function totalsOf(PropertyId $property, string $employeeId): array;
}
