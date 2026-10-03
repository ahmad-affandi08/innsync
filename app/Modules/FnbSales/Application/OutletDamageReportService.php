<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Application;

use App\Modules\Maintenance\Application\DamageReporting;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Faults of the equipment of an outlet (FR-FBS-033): a waiter or a cashier says which equipment or place and what is wrong, with an optional photo; it becomes a work order of Maintenance
 * at once, reported for that person with the department `fnb`, and the screen follows its state. The work order is the record.
 */
final readonly class OutletDamageReportService
{
    public const DEPARTMENT = 'fnb';

    public const CATEGORIES = ['electrical', 'plumbing', 'hvac', 'furniture', 'appliance', 'structural', 'it', 'other'];

    public function __construct(private DamageReporting $maintenance, private FnbAccess $access, private AuditTrail $audit) {}

    /** @return array{reports: list<array<string, mixed>>, rooms: list<array{id: string, number: string}>, categories: list<string>} */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->authorize($property, $actorId);

        return ['reports' => $this->maintenance->reportedBy($property, $actorId, self::DEPARTMENT, 50), 'rooms' => [], 'categories' => self::CATEGORIES];
    }

    /** @return array{id: string, number: string} */
    public function report(PropertyId $property, string $actorId, string $area, string $category, string $title, ?string $detail, bool $urgent, ?string $photo, ?string $photoName): array
    {
        $this->authorize($property, $actorId);

        if (trim($area) === '') {
            throw Refusal::invalid('Say which equipment or place.', ['area']);
        }

        $made = $this->maintenance->report($property, $actorId, self::DEPARTMENT, null, $area, $category, $title, $detail, $urgent, $photo, $photoName);
        $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'fnb.damage.reported', 'work_order', $made['id'], null, ['number' => $made['number'], 'urgent' => $urgent]));

        return $made;
    }

    private function authorize(PropertyId $property, string $actorId): void
    {
        $this->access->assertProperty($property);

        foreach ([FnbAccess::POS_OPERATE, FnbAccess::CASHIER_OPERATE, FnbAccess::SETUP_MANAGE] as $permission) {
            if ($this->access->may($property, $actorId, $permission)) {
                return;
            }
        }

        throw Refusal::forbidden('This person may not report faults from an outlet.');
    }
}
