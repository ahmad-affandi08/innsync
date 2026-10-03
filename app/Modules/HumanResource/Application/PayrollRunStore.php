<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Where payroll runs, their lines, the adjustments and the revisions are kept. */
interface PayrollRunStore
{
    /** @param array<string, mixed> $row  Returns false when the property has a run for the period already. */
    public function addRun(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @return array<string, mixed>|null */
    public function run(PropertyId $property, string $id): ?array;

    /** Newest period first. @return list<array<string, mixed>> */
    public function runs(PropertyId $property): array;

    /** @param array<string, mixed> $fields */
    public function updateRun(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    public function deleteDraft(PropertyId $property, string $id): void;

    /** @param list<array<string, mixed>> $lines */
    public function replaceLines(PropertyId $property, string $runId, array $lines, DateTimeImmutable $at): void;

    /** @return list<array<string, mixed>> */
    public function lines(PropertyId $property, string $runId): array;

    /** @param array<string, mixed> $row */
    public function addAdjustment(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return array<string, mixed>|null */
    public function adjustment(PropertyId $property, string $id): ?array;

    /** @param array<string, mixed> $fields */
    public function updateAdjustment(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** The adjustments that are open, or applied to the given run, newest first; with the period of the run each one corrects. @return list<array<string, mixed>> */
    public function adjustments(PropertyId $property, ?string $appliedRunId): array;

    /** Gives back every adjustment the run took in, so a new calculation takes them in again. */
    public function releaseAdjustments(PropertyId $property, string $runId, DateTimeImmutable $at): void;

    /** @param array<string, mixed> $row */
    public function addRevision(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return array<string, mixed>|null */
    public function lineOf(PropertyId $property, string $runId, string $employeeId): ?array;

    /** The runs a person was paid in, newest first, with their line, among the runs in the given statuses. @param list<string> $statuses @return list<array<string, mixed>> */
    public function linesOfEmployee(PropertyId $property, string $employeeId, array $statuses): array;
}
