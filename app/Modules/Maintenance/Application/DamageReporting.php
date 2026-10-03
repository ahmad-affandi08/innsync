<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Application;

use App\Shared\Domain\Tenancy\PropertyId;

/**
 * How another department hands a fault to engineering (FR-HK-008, FR-KIT-010): a room attendant who finds a broken lamp, a cook whose oven stops. The report becomes a work order of the
 * room or the place, reported for that person, and the department can follow it. It checks no privilege: the caller authorizes its own use.
 */
interface DamageReporting
{
    /** @return array{id: string, number: string} the work order made */
    public function report(PropertyId $property, string $actorId, string $department, ?string $roomId, ?string $area, string $category, string $title, ?string $detail, bool $urgent, ?string $photo, ?string $photoName): array;

    /** @return list<array{id: string, number: string, title: string, category: string, state: 'open'|'in_progress'|'done'|'cancelled', room: string|null, area: string|null, reported_at: string}> the latest faults this person reported for the department, newest first */
    public function reportedBy(PropertyId $property, string $actorId, string $department, int $limit): array;
}
