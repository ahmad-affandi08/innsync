<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface AttendanceStore
{
    /** @return array<string, mixed>|null */
    public function settings(PropertyId $property): ?array;

    /** @param array<string, mixed> $values */
    public function saveSettings(PropertyId $property, array $values, ?int $expectedLock, string $by, DateTimeImmutable $at): bool;

    /** @return array<string, mixed>|null */
    /** Sets what a clock-in does with the face in the selfie (off, flag, require) on the existing settings. */
    public function saveFaceMode(PropertyId $property, string $mode, string $by, DateTimeImmutable $at): void;

    public function record(PropertyId $property, string $employeeId, string $date): ?array;

    /** @return array<string, mixed>|null */
    public function recordById(PropertyId $property, string $id): ?array;

    /** @param array<string, mixed> $row */
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $fields */
    public function update(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> the records of the days between two dates, optionally of one employee */
    public function between(PropertyId $property, string $from, string $to, ?string $employeeId): array;

    /**
     * Which clock-ins and clock-outs already have a supervisor's decision.
     *
     * @param  list<string>  $attendanceIds
     * @return array<string, array{decision: string, reviewed_by: string, reviewed_at: string, note: ?string}> keyed `"<attendance id>:in"` or `":out"`
     */
    public function reviews(PropertyId $property, array $attendanceIds): array;

    /** @param list<string> $flags @return bool false when this clock-in or clock-out already has a decision */
    public function addReview(PropertyId $property, string $id, string $attendanceId, string $side, array $flags, string $decision, ?string $note, string $by, DateTimeImmutable $at): bool;
}
