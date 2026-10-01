<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface ChecklistRepository
{
    /**
     * @param  list<string>  $areas
     * @param  list<array{id: string, text: string}>  $items
     */
    public function addTemplate(PropertyId $property, string $id, string $name, int $version, string $frequency, string $scope, array $areas, array $items, bool $active, string $actorId, DateTimeImmutable $at): void;

    /** The latest version of every checklist. @return list<array<string, mixed>> */
    public function latestTemplates(PropertyId $property): array;

    /** @return array<string, mixed>|null */
    public function latestByName(PropertyId $property, string $name): ?array;

    /** @return array<string, mixed>|null */
    public function template(PropertyId $property, string $id): ?array;

    /**
     * Creates the run for the checklist, period and target unless it exists; returns the run either way.
     *
     * @param  list<array{id: string, text: string}>  $items
     * @return array<string, mixed>
     */
    public function ensureRun(PropertyId $property, string $id, string $templateId, string $name, string $periodKey, string $start, string $end, string $targetRef, string $targetLabel, array $items, DateTimeImmutable $at): array;

    /** @return array<string, mixed>|null */
    public function findRun(PropertyId $property, string $name, string $periodKey, string $targetRef): ?array;

    /**
     * How many items are ticked per target for a checklist and period.
     *
     * @return array<string, array{total: int, completed: int}> by target
     */
    public function progress(PropertyId $property, string $name, string $periodKey): array;

    /** @return 'added'|'already' */
    public function complete(PropertyId $property, string $id, string $runId, string $itemId, ?string $note, string $actorId, DateTimeImmutable $at, string $businessDate): string;

    /** @return list<array{item_id: string, note: ?string, completed_by: string, completed_at: string}> */
    public function completions(PropertyId $property, string $runId): array;

    /**
     * Runs touching the dates with how many items were ticked and by whom.
     *
     * @return list<array{run_id: string, template: string, frequency: string, period_key: string, target: string, items: int, completed: int, by: array<string, int>}>
     */
    public function performance(PropertyId $property, string $from, string $to): array;
}
