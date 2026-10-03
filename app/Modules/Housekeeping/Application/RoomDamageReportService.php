<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Application;

use App\Modules\Maintenance\Application\DamageReporting;
use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Faults found by housekeeping (FR-HK-008). A room attendant (or anyone who works the rooms) picks the room or writes the place, says what is wrong and may attach a photo; the report becomes a
 * work order of Maintenance at once, reported for that person, and the screen follows its state. Nothing else is kept here: the work order is the record.
 */
final readonly class RoomDamageReportService
{
    public const DEPARTMENT = 'housekeeping';

    public const CATEGORIES = ['electrical', 'plumbing', 'hvac', 'furniture', 'appliance', 'structural', 'it', 'other'];

    public function __construct(
        private DamageReporting $maintenance,
        private RoomCatalogReader $rooms,
        private PermissionChecker $permissions,
        private AuditTrail $audit,
        private PropertyContext $property,
    ) {}

    /** @return array{reports: list<array<string, mixed>>, rooms: list<array{id: string, number: string}>, categories: list<string>} */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->authorize($property, $actorId);
        $rooms = [];

        foreach ($this->rooms->activeRooms($property) as $room) {
            $rooms[] = ['id' => $room->id, 'number' => $room->number];
        }

        usort($rooms, static fn (array $a, array $b): int => strnatcmp($a['number'], $b['number']));

        return ['reports' => $this->maintenance->reportedBy($property, $actorId, self::DEPARTMENT, 50), 'rooms' => $rooms, 'categories' => self::CATEGORIES];
    }

    /** @return array{id: string, number: string} */
    public function report(PropertyId $property, string $actorId, ?string $roomId, ?string $area, string $category, string $title, ?string $detail, bool $urgent, ?string $photo, ?string $photoName): array
    {
        $this->authorize($property, $actorId);

        if (($roomId === null || $roomId === '') && ($area === null || trim($area) === '')) {
            throw Refusal::invalid('Choose the room or write the place.', ['room_id', 'area']);
        }

        $made = $this->maintenance->report($property, $actorId, self::DEPARTMENT, $roomId, $area, $category, $title, $detail, $urgent, $photo, $photoName);
        $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'housekeeping.damage.reported', 'work_order', $made['id'], null, ['number' => $made['number'], 'urgent' => $urgent]));

        return $made;
    }

    private function authorize(PropertyId $property, string $actorId): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }

        foreach ([HousekeepingService::PERFORM_PERMISSION, HousekeepingService::MANAGE_PERMISSION, HousekeepingService::INSPECT_PERMISSION] as $permission) {
            if ($this->permissions->allowsInProperty($actorId, $permission, $property)) {
                return;
            }
        }

        throw Refusal::forbidden('This person may not report faults from housekeeping.');
    }
}
