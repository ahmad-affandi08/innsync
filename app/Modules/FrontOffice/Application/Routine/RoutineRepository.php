<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Routine;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface RoutineRepository
{
    // ---- checklist templates ----

    /** @param list<array{id: string, text: string}> $items */
    public function addTemplate(PropertyId $property, string $id, string $name, int $version, string $frequency, array $items, bool $active, string $actorId, DateTimeImmutable $at): void;

    /** The latest version of every checklist, with whether it is active. @return list<array<string, mixed>> */
    public function latestTemplates(PropertyId $property): array;

    /** @return array<string, mixed>|null the latest version of the checklist with that name */
    public function latestByName(PropertyId $property, string $name): ?array;

    /** @return array<string, mixed>|null */
    public function template(PropertyId $property, string $id): ?array;

    // ---- runs and completions ----

    /** Creates the run for the period unless it exists; returns the run either way. @param list<array{id: string, text: string}> $items @return array<string, mixed> */
    public function ensureRun(PropertyId $property, string $id, string $templateId, string $name, string $periodKey, string $start, string $end, array $items, DateTimeImmutable $at): array;

    /** @return array<string, mixed>|null */
    public function findRun(PropertyId $property, string $name, string $periodKey): ?array;

    /** @return 'added'|'already' */
    public function complete(PropertyId $property, string $id, string $runId, string $itemId, ?string $note, string $actorId, DateTimeImmutable $at, string $businessDate): string;

    /** @return list<array{item_id: string, note: ?string, completed_by: string, completed_at: string}> */
    public function completions(PropertyId $property, string $runId): array;

    /**
     * Items completed per person and per run between two business dates (for the performance figure).
     *
     * @return list<array{run_id: string, template: string, frequency: string, period_key: string, items: int, completed: int, by: array<string, int>}>
     */
    public function performance(PropertyId $property, string $from, string $to): array;

    // ---- shift log ----

    public function addEntry(PropertyId $property, string $id, string $businessDate, string $shift, string $priority, string $body, string $actorId, DateTimeImmutable $at): void;

    /** @return list<array<string, mixed>> newest first, with whether `$readerId` has read each */
    public function entries(PropertyId $property, string $readerId, string $sinceUtc, int $limit): array;

    /** Marks entries as read by this person, once each. @param list<string> $entryIds @return int how many were newly marked */
    public function markRead(PropertyId $property, string $readerId, array $entryIds, DateTimeImmutable $at): int;
}
