<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface RequisitionStore
{
    /**
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $lines
     */
    public function add(PropertyId $property, array $row, array $lines, DateTimeImmutable $at): bool;

    /** @return array<string, mixed>|null */
    public function find(PropertyId $property, string $id): ?array;

    /** @return list<array<string, mixed>> */
    public function lines(PropertyId $property, string $id): array;

    /** @return list<array<string, mixed>> newest first */
    public function list(PropertyId $property, ?string $status, int $limit): array;

    /** @param array<string, mixed> $fields */
    public function update(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;
}
