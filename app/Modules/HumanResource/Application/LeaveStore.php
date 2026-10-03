<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface LeaveStore
{
    /** @param array<string, mixed> $row */
    public function addType(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> */
    public function types(PropertyId $property, bool $onlyActive): array;

    /** @return array<string, mixed>|null */
    public function type(PropertyId $property, string $id): ?array;

    /** @param array<string, mixed> $fields */
    public function updateType(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $row */
    public function addRequest(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return array<string, mixed>|null the request with the number and name of the person */
    public function request(PropertyId $property, string $id): ?array;

    /** @param array<string, mixed> $fields */
    public function updateRequest(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> requests that are waiting or approved and touch the days between two dates, for one person */
    public function openBetween(PropertyId $property, string $employeeId, string $from, string $to): array;

    /** @return list<array<string, mixed>> the latest requests, newest first, optionally of one person or one status */
    public function requests(PropertyId $property, ?string $employeeId, ?string $status, int $limit): array;

    /** @return list<array<string, mixed>> requests of a person that are waiting, or approved and ending after a day */
    public function openEndingAfter(PropertyId $property, string $employeeId, string $day): array;

    /** @return list<string> the files kept as evidence for the leave of a person */
    public function evidenceFiles(PropertyId $property, string $employeeId): array;

    /** @param array<string, mixed> $row */
    public function addDay(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return list<array<string, mixed>> the leave days of the people between two dates; every person when none is given */
    public function daysBetween(PropertyId $property, string $from, string $to, ?array $employeeIds): array;

    /** @return list<array<string, mixed>> the days of one leave, earliest first */
    public function daysOf(PropertyId $property, string $leaveId): array;

    public function removeDays(PropertyId $property, string $leaveId, string $from): int;

    /** @param array<string, mixed> $row */
    public function addAdjustment(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return list<array<string, mixed>> the adjustments of a year, optionally of one person, newest first */
    public function adjustments(PropertyId $property, int $year, ?string $employeeId): array;

    /** @return list<array<string, mixed>> days asked for in a year by person, type and status, for the requests that start in it */
    public function daysTaken(PropertyId $property, int $year, ?string $employeeId): array;

    /** The days of leave that are not paid, by person, between two dates. @return array<string, int> */
    public function unpaidDaysBetween(PropertyId $property, string $from, string $to): array;
}
