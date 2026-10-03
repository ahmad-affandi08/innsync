<?php

declare(strict_types=1);

namespace App\Modules\Routines\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface RoutineStore
{
    /** @param list<array{id: string, text: string}> $items */
    public function addTemplate(PropertyId $property, string $department, string $id, string $name, int $version, string $frequency, array $items, bool $active, string $actorId, DateTimeImmutable $at): void;

    /** The latest version of every checklist of the department. @return list<array<string, mixed>> */
    public function latestTemplates(PropertyId $property, string $department): array;

    /** @return array<string, mixed>|null */
    public function latestByName(PropertyId $property, string $department, string $name): ?array;

    /** @return array<string, mixed>|null */
    public function template(PropertyId $property, string $department, string $id): ?array;

    /**
     * @param  list<array{id: string, text: string}>  $items
     * @return array<string, mixed>
     */
    public function ensureRun(PropertyId $property, string $department, string $id, string $templateId, string $name, string $periodKey, string $start, string $end, array $items, DateTimeImmutable $at): array;

    /** @return array<string, mixed>|null */
    public function findRun(PropertyId $property, string $department, string $name, string $periodKey): ?array;

    /** @return 'added'|'already' */
    public function complete(PropertyId $property, string $id, string $runId, string $itemId, ?string $note, string $actorId, DateTimeImmutable $at, string $businessDate): string;

    /** @return list<array{item_id: string, note: string|null, completed_by: string, completed_at: string}> */
    public function completions(PropertyId $property, string $runId): array;

    /** @return list<array{run_id: string, template: string, frequency: string, period_key: string, items: int, completed: int, by: array<string, int>}> */
    public function performance(PropertyId $property, string $department, string $from, string $to): array;

    /** @param array<string, mixed> $row */
    public function addPoint(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> */
    public function points(PropertyId $property, string $department): array;

    /** @return array<string, mixed>|null */
    public function point(PropertyId $property, string $department, string $id): ?array;

    /** @param array<string, mixed> $fields */
    public function updatePoint(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $row */
    public function addReading(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return list<array<string, mixed>> the readings between two business dates, newest first, with the name of the point */
    public function readings(PropertyId $property, string $department, string $from, string $to, ?string $pointId, int $limit): array;
}
