<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Modules\InventoryPurchasing\Domain\StockQuantity;
use App\Modules\InventoryPurchasing\Domain\StockValue;
use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The stock ledger (FR-INV-001, -003, -009). A movement is appended and never changed; it keeps the quantity in the unit it was posted in, the
 * factor that unit had then, and the equivalent in the base unit. The balance of an item in a location is the sum of its movements. This
 * first slice posts opening stock: once for an item in a location, before anything else is posted for it. Receipts, issues, transfers,
 * counts and adjustments come with the purchasing and stock-control tasks.
 */
final readonly class StockService
{
    public const VIEW_PERMISSION = 'inventory.stock.view';

    public const POST_PERMISSION = 'inventory.stock.post';

    /** Costs and values are sensitive: seeing them is a separate privilege from seeing quantities. */
    public const VALUATION_PERMISSION = 'inventory.valuation.view';

    public function __construct(
        private InventoryStore $inventory,
        private StockPoster $poster,
        private PermissionChecker $permissions,
        private TransactionRunner $transactions,
        private PropertyContext $property,
        private PropertyCurrencyReader $currency,
        private BusinessDateProvider $businessDate,
    ) {}

    /**
     * @return array{currency: string, reasons: array<string, list<string>>, rows: list<array<string, mixed>>, below_minimum: int, may: array{post: bool, adjust: bool, negative: bool, transfer: bool, limits: bool, valuation: bool}}
     */
    public function position(PropertyId $property, string $actorId, ?string $locationId, ?string $itemId): array
    {
        $this->authorizeView($property, $actorId);
        $items = [];

        foreach ($this->inventory->items($property) as $i) {
            $items[$i['id']] = $i;
        }

        $locations = [];

        foreach ($this->inventory->locations($property) as $l) {
            $locations[$l['id']] = $l;
        }

        $rows = [];
        $valued = $this->may($property, $actorId, self::VALUATION_PERMISSION);

        foreach ($this->inventory->balances($property, $itemId === null || $itemId === '' ? null : strtolower($itemId), $locationId === null || $locationId === '' ? null : strtolower($locationId)) as $b) {
            $item = $items[$b['item_id']] ?? null;
            $location = $locations[$b['location_id']] ?? null;

            if ($item === null || $location === null) {
                continue;
            }

            $rows[] = $this->shape($item, $location, $b, $valued);
        }

        usort($rows, static fn (array $a, array $b): int => [$a['item_code'], $a['location_code']] <=> [$b['item_code'], $b['location_code']]);

        return [
            'currency' => $this->currency->currencyOf($property),
            'reasons' => ['adjust' => StockMovementService::ADJUST_REASONS, 'write_off' => StockMovementService::WRITE_OFF_REASONS, 'departments' => InventoryCatalogService::DEPARTMENTS],
            'rows' => $rows,
            'below_minimum' => count(array_filter($rows, static fn (array $r): bool => $r['status'] === 'below_minimum' && $r['is_active'])),
            'may' => [
                'post' => $this->may($property, $actorId, self::POST_PERMISSION), 'adjust' => $this->may($property, $actorId, StockMovementService::ADJUST_PERMISSION),
                'negative' => $this->may($property, $actorId, StockPoster::NEGATIVE_PERMISSION), 'transfer' => $this->may($property, $actorId, StockTransferService::SEND_PERMISSION),
                'limits' => $this->may($property, $actorId, InventoryCatalogService::MANAGE_PERMISSION), 'valuation' => $valued,
            ],
        ];
    }

    /** Items below their minimum, for the dashboard. @return list<array<string, mixed>> */
    public function belowMinimum(PropertyId $property, string $actorId): array
    {
        return array_values(array_filter($this->position($property, $actorId, null, null)['rows'], static fn (array $r): bool => $r['status'] === 'below_minimum' && $r['is_active']));
    }

    /** @return list<array<string, mixed>> newest first */
    public function movements(PropertyId $property, string $actorId, ?string $itemId, ?string $locationId, int $limit = 100): array
    {
        $this->authorizeView($property, $actorId);
        $valued = $this->may($property, $actorId, self::VALUATION_PERMISSION);
        $items = array_column($this->inventory->items($property), null, 'id');
        $locations = array_column($this->inventory->locations($property), null, 'id');

        return array_map(static fn (array $m): array => [
            'value_minor' => $valued ? (int) $m['value_minor'] : null, 'id' => $m['id'], 'kind' => $m['kind'], 'item_code' => $items[$m['item_id']]['code'] ?? '', 'item_name' => $items[$m['item_id']]['name'] ?? '', 'location_code' => $locations[$m['location_id']]['code'] ?? '',
            'unit' => $m['unit'], 'unit_qty_milli' => (int) $m['unit_qty_milli'], 'factor_milli' => (int) $m['factor_milli'], 'base_qty_milli' => (int) $m['base_qty_milli'],
            'base_unit' => $items[$m['item_id']]['base_unit'] ?? '', 'reason_code' => $m['reason_code'], 'override_reason' => $m['override_reason'], 'reference' => $m['reference'], 'note' => $m['note'], 'business_date' => substr((string) $m['business_date'], 0, 10), 'created_at' => (new DateTimeImmutable((string) $m['created_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
        ], $this->inventory->movements($property, $itemId === null || $itemId === '' ? null : strtolower($itemId), $locationId === null || $locationId === '' ? null : strtolower($locationId), max(1, min(500, $limit))));
    }

    /**
     * Posts the opening stock of an item in a location, counted in `$unit` (the base unit or one the item has a conversion for).
     *
     * @return array<string, mixed> the movement and the new balance
     */
    public function postOpening(PropertyId $property, string $actorId, string $itemId, string $locationId, string $unit, string $quantity, ?string $reference, ?string $note, ?int $unitCostMinor = null, ?array $lot = null): array
    {
        $this->assertProperty($property);

        if (! $this->may($property, $actorId, self::POST_PERMISSION)) {
            throw Refusal::forbidden('This person may not post stock.');
        }

        $qty = StockQuantity::parse($quantity);

        if ($qty === null || $qty < 1 || $qty > StockQuantity::MAX_MILLI) {
            throw Refusal::invalid('Give the quantity as a number above zero, with at most three decimals.', ['quantity']);
        }

        $reference = $reference === null || trim($reference) === '' ? null : trim($reference);
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if (($reference !== null && mb_strlen($reference) > 40) || ($note !== null && mb_strlen($note) > 200)) {
            throw Refusal::invalid('The reference is at most 40 characters and the note at most 200.', ['reference']);
        }

        $item = $this->inventory->item($property, strtolower($itemId)) ?? throw Refusal::notFound('Item not found.');
        $location = $this->inventory->location($property, strtolower($locationId)) ?? throw Refusal::notFound('Location not found.');

        if (! (bool) $item['is_active'] || ! (bool) $location['is_active']) {
            throw Refusal::stateConflict('Stock cannot be posted to an inactive item or location.');
        }

        $unit = strtoupper(trim($unit));

        $movement = $this->transactions->run(function () use ($property, $actorId, $item, $location, $unit, $qty, $reference, $note, $unitCostMinor, $lot): array {
            $this->inventory->lockItem($property, $item['id']);

            if ($this->inventory->movementCount($property, $item['id'], $location['id']) > 0) {
                throw Refusal::stateConflict('Opening stock is posted once, before anything else. Later changes are receipts, issues or adjustments.');
            }

            return $this->poster->post($property, $actorId, $item, $location, 'opening', $unit, $qty, null, $reference, $note, null, null, null, null, false, null, $unitCostMinor, null, false, $lot)['movement'];
        });

        $balance = $this->inventory->balances($property, $item['id'], $location['id'])[0] ?? null;

        return [...$movement, 'balance_milli' => $balance === null ? $movement['base_qty_milli'] : (int) $balance['balance_milli']];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $location
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private function shape(array $item, array $location, array $b, bool $valued): array
    {
        $balance = (int) $b['balance_milli'];
        $min = $b['min_milli'] === null ? null : (int) $b['min_milli'];
        $max = $b['max_milli'] === null ? null : (int) $b['max_milli'];
        $status = $min !== null && $balance < $min ? 'below_minimum' : ($max !== null && $balance > $max ? 'above_maximum' : 'ok');

        return [
            'item_id' => $item['id'], 'item_code' => $item['code'], 'item_name' => $item['name'], 'category_name' => $item['category_name'], 'department' => $item['department'], 'base_unit' => $item['base_unit'], 'is_active' => (bool) $item['is_active'],
            'location_id' => $location['id'], 'location_code' => $location['code'], 'location_name' => $location['name'], 'balance_milli' => $balance, 'value_minor' => $valued ? (int) $b['value_minor'] : null, 'min_milli' => $min, 'max_milli' => $max,
            'limit_lock' => $b['limit_lock'] === null ? null : (int) $b['limit_lock'], 'status' => $status, 'last_at' => $b['last_at'] === null ? null : (new DateTimeImmutable((string) $b['last_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * The value of stock at the end of a business date (FR-INV-007): per item and location the quantity, the value and the average cost of one base unit,
     * and the total. It is a sum over the ledger up to that date, so a past date gives the same figures every time. Needs `inventory.valuation.view`.
     *
     * @return array{currency: string, as_of: string, rows: list<array<string, mixed>>, total_value_minor: int}
     */
    public function valuation(PropertyId $property, string $actorId, ?string $asOf): array
    {
        $this->authorizeView($property, $actorId);

        if (! $this->may($property, $actorId, self::VALUATION_PERMISSION)) {
            throw Refusal::forbidden('This person may not see the value of stock.');
        }

        $date = $asOf === null || $asOf === '' ? $this->businessDate->current($property)->toString() : $asOf;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            throw Refusal::invalid('Give the date as year-month-day.', ['as_of']);
        }

        $items = array_column($this->inventory->items($property), null, 'id');
        $locations = array_column($this->inventory->locations($property), null, 'id');
        $rows = [];
        $total = 0;

        foreach ($this->inventory->valuation($property, $date) as $v) {
            $item = $items[$v['item_id']] ?? null;
            $location = $locations[$v['location_id']] ?? null;

            if ($item === null || $location === null || ($v['qty_milli'] === 0 && $v['value_minor'] === 0)) {
                continue;
            }

            $total += $v['value_minor'];
            $rows[] = [
                'item_id' => $item['id'], 'item_code' => $item['code'], 'item_name' => $item['name'], 'category_name' => $item['category_name'], 'base_unit' => $item['base_unit'],
                'location_id' => $location['id'], 'location_code' => $location['code'], 'location_name' => $location['name'],
                'qty_milli' => $v['qty_milli'], 'value_minor' => $v['value_minor'],
                'average_cost_minor' => $v['qty_milli'] > 0 && $v['value_minor'] > 0 ? StockValue::mulDiv($v['value_minor'], 1000, $v['qty_milli']) : null,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['item_code'], $a['location_code']] <=> [$b['item_code'], $b['location_code']]);

        return ['currency' => $this->currency->currencyOf($property), 'as_of' => $date, 'rows' => $rows, 'total_value_minor' => $total];
    }

    private function authorizeView(PropertyId $property, string $actorId): void
    {
        $this->assertProperty($property);

        foreach ([self::VIEW_PERMISSION, self::POST_PERMISSION, InventoryCatalogService::MANAGE_PERMISSION] as $permission) {
            if ($this->may($property, $actorId, $permission)) {
                return;
            }
        }

        throw Refusal::forbidden('This person may not see the stock.');
    }

    private function may(PropertyId $property, string $actorId, string $permission): bool
    {
        return $this->permissions->allowsInProperty($actorId, $permission, $property);
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
