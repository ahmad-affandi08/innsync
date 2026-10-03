<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface ShiftSwapStore
{
    /** @param array<string, mixed> $row */
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return array<string, mixed>|null the swap with the names of both people */
    public function find(PropertyId $property, string $id): ?array;

    /** @param array<string, mixed> $fields */
    public function update(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** Newest day first. @return list<array<string, mixed>> */
    public function forPerson(PropertyId $property, string $employeeId, int $limit): array;

    /** @return list<array<string, mixed>> the swaps a supervisor still has to decide, oldest day first */
    public function awaitingSupervisor(PropertyId $property, int $limit): array;

    /** Whether the person is in a swap that is still open on the day. */
    public function openOn(PropertyId $property, string $employeeId, string $date): bool;
}
