<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The batches that hold stock and when they expire (FR-INV-008, FR-KIT-009). A batch is made when stock comes in with a batch number or an expiry date (a goods receipt line with an expiry
 * date, a receipt, an opening or an adjustment in); an outflow takes from the batch that expires first. This lists them, earliest expiry first, and says which are expired or expire within
 * the days of notice (14 to start with), so someone acts before the stock is spoiled.
 */
final readonly class StockLotService
{
    /** Days before an expiry date that a batch is flagged (a baseline the owner can change in code until it is a setting). */
    public const WARN_DAYS = 14;

    public function __construct(private InventoryStore $inventory, private BusinessDateProvider $businessDate, private PermissionChecker $permissions, private PropertyContext $property) {}

    /** @return array{lots: list<array<string, mixed>>, counts: array{expired: int, expiring: int}, warn_days: int, business_date: string, department: string|null} */
    public function overview(PropertyId $property, string $actorId, ?string $department, ?string $status): array
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }

        if (! $this->permissions->allowsInProperty($actorId, StockService::VIEW_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not see stock.');
        }

        $department = $department === '' ? null : $department;

        if ($department !== null && ! in_array($department, InventoryCatalogService::DEPARTMENTS, true)) {
            throw Refusal::invalid('Choose a department from the list.', ['department']);
        }

        if ($status !== null && $status !== '' && ! in_array($status, ['expired', 'expiring', 'ok', 'no_expiry'], true)) {
            throw Refusal::invalid('Choose expired, expiring, ok or no expiry.', ['status']);
        }

        $today = $this->businessDate->current($property)->toString();
        $lots = [];
        $counts = ['expired' => 0, 'expiring' => 0];

        foreach ($this->inventory->lots($property, null, null, $department) as $l) {
            $expires = $l['expires_on'] === null ? null : substr((string) $l['expires_on'], 0, 10);
            $days = $expires === null ? null : (int) round((strtotime($expires) - strtotime($today)) / 86400);
            $state = $expires === null ? 'no_expiry' : ($days < 0 ? 'expired' : ($days <= self::WARN_DAYS ? 'expiring' : 'ok'));
            $counts['expired'] += $state === 'expired' ? 1 : 0;
            $counts['expiring'] += $state === 'expiring' ? 1 : 0;

            if ($status !== null && $status !== '' && $state !== $status) {
                continue;
            }

            $lots[] = [
                'id' => $l['id'], 'item' => ['code' => $l['item_code'], 'name' => $l['item_name'], 'unit' => $l['base_unit'], 'department' => $l['department']], 'location' => ['code' => $l['location_code'], 'name' => $l['location_name']],
                'lot_number' => $l['lot_number'], 'expires_on' => $expires, 'days_left' => $days, 'status' => $state, 'remaining_milli' => (int) $l['remaining_milli'], 'received_milli' => (int) $l['received_milli'],
            ];
        }

        return ['lots' => $lots, 'counts' => $counts, 'warn_days' => self::WARN_DAYS, 'business_date' => $today, 'department' => $department];
    }
}
