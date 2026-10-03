<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Storage of the work orders, their history and the service levels. Rows are plain arrays; every query is scoped to the property. */
interface WorkOrderStore
{
    /** @param array<string, mixed> $row */
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return array<string, mixed>|null */
    public function find(PropertyId $property, string $id): ?array;

    /** Locks a work order for the rest of the transaction. */
    public function lock(PropertyId $property, string $id): void;

    /** @param array<string, mixed> $fields @return bool false when the work order changed meanwhile */
    public function update(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /**
     * @param  list<string>|null  $statuses
     * @param  string|null  $involving  only the work orders this person reported or was given
     * @return list<array<string, mixed>> newest first
     */
    public function list(PropertyId $property, ?array $statuses, ?string $involving, int $limit): array;

    /** @param array<string, mixed> $row */
    public function addEvent(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return list<array<string, mixed>> oldest first */
    public function events(PropertyId $property, string $id): array;

    /** @return array{urgent: int, high: int, normal: int, low: int, lock_version: int}|null the minutes each priority may take */
    public function settings(PropertyId $property): ?array;

    /** @param array{urgent: int, high: int, normal: int, low: int} $minutes @return bool false when the setting changed meanwhile */
    public function saveSettings(PropertyId $property, array $minutes, ?int $expectedLock, string $by, DateTimeImmutable $at): bool;
}
