<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Application;

use App\Modules\InventoryPurchasing\Application\DepartmentSupplyUse;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The cleaning supplies and amenities housekeeping uses up (FR-INV-004, FR-HK-009). Housekeeping says which item of its own, from which store and how much; the stock card of that store takes
 * it out at the average cost as housekeeping consumption, and never below zero. Housekeeping sees what it used lately. The stock itself, its minimum and its counts stay in Inventory.
 */
final readonly class SupplyUseService
{
    public const USE_PERMISSION = 'housekeeping.supplies.use';

    public const DEPARTMENT = 'housekeeping';

    public function __construct(private DepartmentSupplyUse $supplies, private PermissionChecker $permissions, private AuditTrail $audit, private PropertyContext $property) {}

    /** @return array{items: list<array<string, mixed>>, locations: list<array<string, mixed>>, recent: list<array<string, mixed>>, may: array{use: bool}} */
    public function overview(PropertyId $property, string $actorId): array
    {
        $may = $this->authorize($property, $actorId);

        return [
            'items' => $may ? $this->supplies->items($property, self::DEPARTMENT) : [], 'locations' => $may ? $this->supplies->locations($property) : [],
            'recent' => $this->supplies->recent($property, self::DEPARTMENT, 100), 'may' => ['use' => $may],
        ];
    }

    /** @return array{id: string, balance_milli: int} */
    public function use(PropertyId $property, string $actorId, string $itemId, string $locationId, string $unit, string $quantity, ?string $note): array
    {
        if (! $this->authorize($property, $actorId)) {
            throw Refusal::forbidden('This person may not record the supplies housekeeping uses.');
        }

        $made = $this->supplies->use($property, $actorId, self::DEPARTMENT, $itemId, $locationId, $unit, $quantity, $note);
        $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'housekeeping.supplies.used', 'stock_movement', $made['id'], null, ['unit' => strtoupper($unit), 'quantity' => $quantity]));

        return $made;
    }

    /** Whether the person may record use; anyone who works housekeeping may see it. */
    private function authorize(PropertyId $property, string $actorId): bool
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }

        $use = $this->permissions->allowsInProperty($actorId, self::USE_PERMISSION, $property);

        if (! $use && ! $this->permissions->allowsInProperty($actorId, HousekeepingService::PERFORM_PERMISSION, $property) && ! $this->permissions->allowsInProperty($actorId, HousekeepingService::MANAGE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not see the supplies housekeeping uses.');
        }

        return $use;
    }
}
