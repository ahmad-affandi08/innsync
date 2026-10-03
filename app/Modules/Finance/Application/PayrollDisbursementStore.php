<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface PayrollDisbursementStore
{
    /** @return array<string, mixed>|null */
    public function byRun(PropertyId $property, string $runId): ?array;

    /** @return array<string, mixed>|null */
    public function find(PropertyId $property, string $id): ?array;

    /** @return list<array<string, mixed>> */
    public function all(PropertyId $property): array;

    /** @param array<string, mixed> $row */
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @param array<string, mixed> $fields */
    public function update(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;
}
