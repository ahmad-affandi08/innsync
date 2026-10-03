<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface PerformanceStore
{
    /** Notes how far a run got; a run told again with more done moves forward, never back. */
    public function noteRun(PropertyId $property, string $id, string $source, string $runId, string $checklist, string $frequency, string $periodKey, string $periodStart, int $total, int $completed, DateTimeImmutable $at): void;

    /** @return bool false when this person was credited for this item already */
    public function credit(PropertyId $property, string $id, string $source, string $runId, string $itemKey, string $userId, int $weight, string $day, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> the runs whose period falls between two dates */
    public function runs(PropertyId $property, string $from, string $to): array;

    /** @return list<array{user_id: string, source: string, credits: int}> the weight credited to each person in each source between two days */
    public function creditsBetween(PropertyId $property, string $from, string $to): array;
}
