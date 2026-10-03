<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Where the reprimands, warning letters and awards of the staff are kept. */
interface ConductStore
{
    /** @param array<string, mixed> $row */
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return array<string, mixed>|null the record with the number and name of the person */
    public function find(PropertyId $property, string $id): ?array;

    /** @param array<string, mixed> $fields */
    public function update(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** Newest first. @return list<array<string, mixed>> */
    public function list(PropertyId $property, ?string $employeeId, int $limit): array;

    /** @return list<string> the ids of the letters kept for a person, for their retention to start when they leave */
    public function fileIdsOf(PropertyId $property, string $employeeId): array;
}
