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

    /** @return array{urgent: int, high: int, normal: int, low: int, warn: int, escalate: int, night_from: int, night_to: int, lock_version: int}|null the minutes each priority may take and the escalation settings */
    public function settings(PropertyId $property): ?array;

    /** @param array{urgent: int, high: int, normal: int, low: int, warn: int, escalate: int, night_from: int, night_to: int} $values @return bool false when the setting changed meanwhile */
    public function saveSettings(PropertyId $property, array $values, ?int $expectedLock, string $by, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $row */
    public function addRoomBlock(PropertyId $property, array $row): void;

    /** Notes that a room went back on sale on a date. */
    public function releaseRoomBlock(PropertyId $property, string $blockId, string $date): void;

    /** @return list<array<string, mixed>> the blocks that touch the dates */
    public function roomBlocksBetween(PropertyId $property, string $from, string $to): array;

    /** @return list<array<string, mixed>> the work orders reported on or between the dates */
    public function reportedBetween(PropertyId $property, string $from, string $to): array;

    /** @return list<array<string, mixed>> the work orders done on or between the dates */
    public function doneBetween(PropertyId $property, string $from, string $to): array;

    /** @param array<string, mixed> $row @return bool false when the level was raised already */
    public function addEscalation(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> the escalations nobody acknowledged yet, of work orders that are still open, oldest first, with the work order's number, title and priority */
    public function openEscalations(PropertyId $property): array;

    /** @return array<string, mixed>|null */
    public function escalation(PropertyId $property, string $id): ?array;

    /** @return bool false when it was acknowledged already */
    public function acknowledgeEscalation(PropertyId $property, string $id, string $by, ?string $note, DateTimeImmutable $at): bool;

    /** @return array<string, mixed>|null the work order of a preventive plan that is still open */
    public function openOfPlan(PropertyId $property, string $planId): ?array;

    /** Whether the cycle of a preventive plan, named by its marker, has a work order already (open or not). */
    public function cycleTaken(PropertyId $property, string $planId, string $marker): bool;

    /** @return list<string> the properties that have work orders still open */
    public function propertiesWithOpenWork(): array;
}
