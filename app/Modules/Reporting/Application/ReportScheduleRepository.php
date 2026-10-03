<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Storage of the scheduled reports, their recipients and what each run did. Rows are plain arrays; every query is scoped to the property. */
interface ReportScheduleRepository
{
    /** @return list<array<string, mixed>> every schedule, those in use first, with its `recipients` (user ids) */
    public function all(PropertyId $property): array;

    /** @return array<string, mixed>|null the schedule with its `recipients` */
    public function find(PropertyId $property, string $id): ?array;

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $recipients
     */
    public function add(PropertyId $property, array $row, array $recipients, DateTimeImmutable $at): void;

    /**
     * Changes a schedule at the version the person saw; the recipients are replaced when given.
     *
     * @param  array<string, mixed>  $fields
     * @param  list<string>|null  $recipients
     * @return bool false when the version is not the current one
     */
    public function update(PropertyId $property, string $id, int $lock, array $fields, ?array $recipients, DateTimeImmutable $at): bool;

    /** Locks a schedule for the rest of the transaction. */
    public function lock(PropertyId $property, string $id): void;

    /** @return list<array<string, mixed>> the schedules in use whose time has come, oldest first, with their `recipients` */
    public function due(PropertyId $property, DateTimeImmutable $now, int $limit): array;

    /** Records that a schedule ran and when it runs next. */
    public function ran(PropertyId $property, string $id, DateTimeImmutable $ranAt, DateTimeImmutable $next): void;

    /** @param array<string, mixed> $row */
    public function addRun(PropertyId $property, array $row): void;

    /** @return list<array<string, mixed>> the latest runs of each schedule, newest first */
    public function runs(PropertyId $property, int $perSchedule): array;
}
