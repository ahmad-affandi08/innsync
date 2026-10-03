<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface DutyStore
{
    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $steps  the text of each step, in order
     */
    public function addDuty(PropertyId $property, array $row, array $steps, DateTimeImmutable $at): void;

    /** @return list<array<string, mixed>> the duties with their steps (`steps`: list of text), by title */
    public function duties(PropertyId $property, bool $onlyActive): array;

    /** @return array<string, mixed>|null a duty with its steps */
    public function duty(PropertyId $property, string $id): ?array;

    /**
     * @param  array<string, mixed>  $fields
     * @param  list<string>|null  $steps  the new steps, or null to keep them
     */
    public function updateDuty(PropertyId $property, string $id, int $lock, array $fields, ?array $steps, DateTimeImmutable $at): bool;

    /** The latest business day a run of the duty was made for, or null. */
    public function lastDue(PropertyId $property, string $dutyId): ?string;

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $steps
     * @return bool false when the duty has a run for that day already
     */
    public function addRun(PropertyId $property, array $row, array $steps, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> runs with counts of their steps (`step_count`, `pending_count`, `issue_count`) */
    public function runs(PropertyId $property, ?string $status, ?string $from, ?string $to, int $limit): array;

    /** @return array<string, mixed>|null a run with its steps */
    public function run(PropertyId $property, string $id): ?array;

    public function lockRun(PropertyId $property, string $id): void;

    /** @param array<string, mixed> $fields */
    public function updateRun(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $fields */
    public function updateStep(PropertyId $property, string $runId, string $stepId, array $fields): bool;

    /** Marks the runs still open before the business day as missed. @return int how many */
    public function markMissed(PropertyId $property, string $before, DateTimeImmutable $at): int;

    /** @return list<string> the properties with an active duty */
    public function propertiesWithDuties(): array;
}
