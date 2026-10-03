<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface AttendanceCorrectionStore
{
    /** @param array<string, mixed> $row */
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return array<string, mixed>|null */
    public function find(PropertyId $property, string $id): ?array;

    /** @param array<string, mixed> $fields */
    public function update(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** @return array<string, mixed>|null a correction of the person for the day that waits for its approval */
    public function pendingFor(PropertyId $property, string $employeeId, string $date): ?array;

    /** @return list<array<string, mixed>> the latest corrections with the number and name of the person, newest first */
    public function latest(PropertyId $property, int $limit): array;
}
