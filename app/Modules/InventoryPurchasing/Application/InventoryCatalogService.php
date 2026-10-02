<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Modules\InventoryPurchasing\Domain\StockQuantity;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The inventory catalog (FR-INV-001, -002, -003, -009): item master data, storage locations, categories, versioned unit conversions and the
 * minimum and maximum stock of an item in a location. The base unit and the code of an item never change, because every quantity in the stock
 * ledger is kept in the base unit. A unit conversion is never edited: a new factor is a new version, and a movement keeps the version it was
 * posted with. Departments are a fixed baseline list; the owner may ask for more through a change request.
 */
final readonly class InventoryCatalogService
{
    public const MANAGE_PERMISSION = 'inventory.catalog.manage';

    public const VIEW_PERMISSION = 'inventory.catalog.view';

    /** The departments that can own an item. */
    public const DEPARTMENTS = ['front_office', 'housekeeping', 'laundry', 'fnb', 'kitchen', 'maintenance', 'hr', 'finance', 'purchasing', 'general'];

    public const LOCATION_KINDS = ['main', 'bar', 'kitchen', 'housekeeping', 'engineering', 'galley', 'other'];

    public function __construct(
        private InventoryStore $inventory,
        private PermissionChecker $permissions,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    /**
     * @return array{categories: list<array<string, mixed>>, locations: list<array<string, mixed>>, items: list<array<string, mixed>>, departments: list<string>, kinds: list<string>, may: array{manage: bool, stock: bool}}
     */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->assertProperty($property);
        $manage = $this->may($property, $actorId, self::MANAGE_PERMISSION);

        if (! $manage && ! $this->may($property, $actorId, self::VIEW_PERMISSION) && ! $this->may($property, $actorId, StockService::VIEW_PERMISSION) && ! $this->may($property, $actorId, StockService::POST_PERMISSION)) {
            throw Refusal::forbidden('This person may not see the inventory catalog.');
        }

        $versions = [];

        foreach ($this->inventory->unitVersions($property) as $v) {
            $versions[$v['item_id']][$v['unit']][] = ['id' => $v['id'], 'version' => (int) $v['version'], 'factor_milli' => (int) $v['factor_milli'], 'reason' => $v['reason'], 'created_at' => (new DateTimeImmutable((string) $v['created_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z')];
        }

        $items = array_map(static function (array $i) use ($versions): array {
            $units = [];

            foreach ($versions[$i['id']] ?? [] as $unit => $list) {
                $current = end($list);
                $units[] = ['unit' => $unit, 'factor_milli' => $current['factor_milli'], 'version' => $current['version'], 'history' => $list];
            }

            return [
                'id' => $i['id'], 'code' => $i['code'], 'name' => $i['name'], 'category_id' => $i['category_id'], 'category_name' => $i['category_name'], 'department' => $i['department'],
                'base_unit' => $i['base_unit'], 'is_active' => (bool) $i['is_active'], 'lock_version' => (int) $i['lock_version'], 'units' => $units,
            ];
        }, $this->inventory->items($property));

        return [
            'categories' => array_map(static fn (array $c): array => ['id' => $c['id'], 'code' => $c['code'], 'name' => $c['name'], 'is_active' => (bool) $c['is_active'], 'negative_blocked' => (bool) $c['negative_blocked'], 'lock_version' => (int) $c['lock_version']], $this->inventory->categories($property)),
            'locations' => array_map(static fn (array $l): array => ['id' => $l['id'], 'code' => $l['code'], 'name' => $l['name'], 'kind' => $l['kind'], 'is_active' => (bool) $l['is_active'], 'negative_blocked' => (bool) $l['negative_blocked'], 'lock_version' => (int) $l['lock_version']], $this->inventory->locations($property)),
            'items' => $items,
            'departments' => self::DEPARTMENTS,
            'kinds' => self::LOCATION_KINDS,
            'may' => ['manage' => $manage, 'stock' => $this->may($property, $actorId, StockService::POST_PERMISSION)],
        ];
    }

    /** @return array<string, mixed> */
    public function createCategory(PropertyId $property, string $actorId, string $code, string $name, bool $negativeBlocked = false): array
    {
        $this->authorize($property, $actorId);
        $code = $this->code($code, 12, 'code');
        $name = $this->name($name, 80);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actorId, $id, $code, $name, $negativeBlocked): void {
            if (! $this->inventory->addCategory($property, ['id' => $id, 'code' => $code, 'name' => $name, 'negative_blocked' => $negativeBlocked], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('A category with this code already exists.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'inventory_category.created', 'inventory_category', $id, null, ['code' => $code, 'name' => $name, 'negative_blocked' => $negativeBlocked]));
        });

        return $this->shape($this->inventory->category($property, $id) ?? throw Refusal::notFound('Category not found.'));
    }

    /** @return array<string, mixed> */
    public function updateCategory(PropertyId $property, string $actorId, string $id, string $name, bool $active, int $lock, ?bool $negativeBlocked = null): array
    {
        $this->authorize($property, $actorId);
        $name = $this->name($name, 80);
        $before = $this->inventory->category($property, strtolower($id)) ?? throw Refusal::notFound('Category not found.');

        $blocked = $negativeBlocked ?? (bool) $before['negative_blocked'];

        $this->transactions->run(function () use ($property, $actorId, $before, $name, $active, $lock, $blocked): void {
            if (! $this->inventory->updateCategory($property, $before['id'], $lock, $name, $active, $blocked, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This category changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'inventory_category.updated', 'inventory_category', $before['id'], ['name' => $before['name'], 'is_active' => (bool) $before['is_active'], 'negative_blocked' => (bool) $before['negative_blocked']], ['name' => $name, 'is_active' => $active, 'negative_blocked' => $blocked]));
        });

        return $this->shape($this->inventory->category($property, $before['id']) ?? throw Refusal::notFound('Category not found.'));
    }

    /** @return array<string, mixed> */
    public function createLocation(PropertyId $property, string $actorId, string $code, string $name, string $kind, bool $negativeBlocked = false): array
    {
        $this->authorize($property, $actorId);
        $code = $this->code($code, 12, 'code');
        $name = $this->name($name, 80);
        $this->kind($kind);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actorId, $id, $code, $name, $kind, $negativeBlocked): void {
            if (! $this->inventory->addLocation($property, ['id' => $id, 'code' => $code, 'name' => $name, 'kind' => $kind, 'negative_blocked' => $negativeBlocked], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('A location with this code already exists.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'inventory_location.created', 'inventory_location', $id, null, ['code' => $code, 'name' => $name, 'kind' => $kind, 'negative_blocked' => $negativeBlocked]));
        });

        return $this->shape($this->inventory->location($property, $id) ?? throw Refusal::notFound('Location not found.'));
    }

    /** @return array<string, mixed> */
    public function updateLocation(PropertyId $property, string $actorId, string $id, string $name, string $kind, bool $active, int $lock, ?bool $negativeBlocked = null): array
    {
        $this->authorize($property, $actorId);
        $name = $this->name($name, 80);
        $this->kind($kind);
        $before = $this->inventory->location($property, strtolower($id)) ?? throw Refusal::notFound('Location not found.');

        $blocked = $negativeBlocked ?? (bool) $before['negative_blocked'];

        $this->transactions->run(function () use ($property, $actorId, $before, $name, $kind, $active, $lock, $blocked): void {
            if (! $this->inventory->updateLocation($property, $before['id'], $lock, $name, $kind, $active, $blocked, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This location changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'inventory_location.updated', 'inventory_location', $before['id'], ['name' => $before['name'], 'kind' => $before['kind'], 'is_active' => (bool) $before['is_active'], 'negative_blocked' => (bool) $before['negative_blocked']], ['name' => $name, 'kind' => $kind, 'is_active' => $active, 'negative_blocked' => $blocked]));
        });

        return $this->shape($this->inventory->location($property, $before['id']) ?? throw Refusal::notFound('Location not found.'));
    }

    /** @return array<string, mixed> */
    public function createItem(PropertyId $property, string $actorId, string $code, string $name, string $categoryId, string $department, string $baseUnit): array
    {
        $this->authorize($property, $actorId);
        $code = $this->code($code, 20, 'code');
        $name = $this->name($name, 120);
        $unit = $this->unit($baseUnit, 'base_unit');
        $this->department($department);
        $category = $this->inventory->category($property, strtolower($categoryId));

        if ($category === null || ! (bool) $category['is_active']) {
            throw Refusal::invalid('Choose an active category.', ['category_id']);
        }

        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actorId, $id, $code, $name, $category, $department, $unit): void {
            if (! $this->inventory->addItem($property, ['id' => $id, 'code' => $code, 'name' => $name, 'category_id' => $category['id'], 'department' => $department, 'base_unit' => $unit], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('An item with this code already exists.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'inventory_item.created', 'inventory_item', $id, null, ['code' => $code, 'name' => $name, 'category' => $category['code'], 'department' => $department, 'base_unit' => $unit]));
        });

        return $this->shape($this->inventory->item($property, $id) ?? throw Refusal::notFound('Item not found.'));
    }

    /** @return array<string, mixed> */
    public function updateItem(PropertyId $property, string $actorId, string $id, string $name, string $categoryId, string $department, bool $active, int $lock): array
    {
        $this->authorize($property, $actorId);
        $name = $this->name($name, 120);
        $this->department($department);
        $before = $this->inventory->item($property, strtolower($id)) ?? throw Refusal::notFound('Item not found.');
        $category = $this->inventory->category($property, strtolower($categoryId));

        if ($category === null || (! (bool) $category['is_active'] && $category['id'] !== $before['category_id'])) {
            throw Refusal::invalid('Choose an active category.', ['category_id']);
        }

        $this->transactions->run(function () use ($property, $actorId, $before, $name, $category, $department, $active, $lock): void {
            if (! $this->inventory->updateItem($property, $before['id'], $lock, $name, $category['id'], $department, $active, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This item changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'inventory_item.updated', 'inventory_item', $before['id'], ['name' => $before['name'], 'category_id' => $before['category_id'], 'department' => $before['department'], 'is_active' => (bool) $before['is_active']], ['name' => $name, 'category_id' => $category['id'], 'department' => $department, 'is_active' => $active]));
        });

        return $this->shape($this->inventory->item($property, $before['id']) ?? throw Refusal::notFound('Item not found.'));
    }

    /**
     * Adds a unit an item can be counted or bought in, or a new version of the factor of one it already has. The factor is how many base units
     * one of the unit holds (for example 24 for a carton of 24 bottles; 0.001 for a gram when the base unit is the kilogram).
     *
     * @return array{unit: string, version: int, factor_milli: int}
     */
    public function addConversion(PropertyId $property, string $actorId, string $itemId, string $unit, string $factor, string $reason): array
    {
        $this->authorize($property, $actorId);
        $unit = $this->unit($unit, 'unit');
        $milli = StockQuantity::parse($factor);

        if ($milli === null || $milli < 1 || $milli > StockQuantity::MAX_FACTOR_MILLI) {
            throw Refusal::invalid('Give how many base units one of this unit holds, from 0.001 to 1,000,000, with at most three decimals.', ['factor']);
        }

        if (trim($reason) === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('A reason of at most 200 characters is required.', ['reason']);
        }

        $item = $this->inventory->item($property, strtolower($itemId)) ?? throw Refusal::notFound('Item not found.');

        if ($unit === $item['base_unit']) {
            throw Refusal::invalid('This is the base unit of the item; it always holds exactly one.', ['unit']);
        }

        $result = $this->transactions->run(function () use ($property, $actorId, $item, $unit, $milli, $reason): array {
            $this->inventory->lockItem($property, $item['id']);
            $current = $this->inventory->currentUnit($property, $item['id'], $unit);

            if ($current !== null && (int) $current['factor_milli'] === $milli) {
                throw Refusal::stateConflict('The unit already has this factor.');
            }

            $version = $current === null ? 1 : (int) $current['version'] + 1;
            $this->inventory->addUnitVersion($property, ['id' => $this->ids->next(), 'item_id' => $item['id'], 'unit' => $unit, 'version' => $version, 'factor_milli' => $milli, 'reason' => trim($reason), 'created_by' => strtolower($actorId)], $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'inventory_item.conversion_set', 'inventory_item', $item['id'], $current === null ? null : ['unit' => $unit, 'version' => (int) $current['version'], 'factor_milli' => (int) $current['factor_milli']], ['unit' => $unit, 'version' => $version, 'factor_milli' => $milli], trim($reason)));

            return ['unit' => $unit, 'version' => $version, 'factor_milli' => $milli];
        });

        return $result;
    }

    /** @return array<string, mixed> the limits row */
    public function setLimits(PropertyId $property, string $actorId, string $itemId, string $locationId, string $min, ?string $max, ?int $lock): array
    {
        $this->authorize($property, $actorId);
        $minMilli = StockQuantity::parse($min);
        $maxMilli = $max === null || trim($max) === '' ? null : StockQuantity::parse($max);

        if ($minMilli === null) {
            throw Refusal::invalid('Give the minimum stock as a number, with at most three decimals.', ['min']);
        }

        if ($max !== null && trim($max) !== '' && $maxMilli === null) {
            throw Refusal::invalid('Give the maximum stock as a number, with at most three decimals, or leave it empty.', ['max']);
        }

        if ($maxMilli !== null && $maxMilli < $minMilli) {
            throw Refusal::invalid('The maximum cannot be below the minimum.', ['max']);
        }

        $item = $this->inventory->item($property, strtolower($itemId)) ?? throw Refusal::notFound('Item not found.');
        $location = $this->inventory->location($property, strtolower($locationId)) ?? throw Refusal::notFound('Location not found.');
        $before = $this->inventory->limit($property, $item['id'], $location['id']);

        $this->transactions->run(function () use ($property, $actorId, $item, $location, $minMilli, $maxMilli, $lock, $before): void {
            if (($before === null) !== ($lock === null) || ! $this->inventory->saveLimit($property, $this->ids->next(), $item['id'], $location['id'], $minMilli, $maxMilli, $lock, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('These limits changed after you opened them.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'inventory_stock_limits.set', 'inventory_item', $item['id'], $before === null ? null : ['min_milli' => (int) $before['min_milli'], 'max_milli' => $before['max_milli'] === null ? null : (int) $before['max_milli']], ['location' => $location['code'], 'min_milli' => $minMilli, 'max_milli' => $maxMilli]));
        });

        return $this->shape($this->inventory->limit($property, $item['id'], $location['id']) ?? throw Refusal::notFound('Limits not found.'));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function shape(array $row): array
    {
        foreach (['is_active', 'negative_blocked'] as $flag) {
            if (array_key_exists($flag, $row)) {
                $row[$flag] = (bool) $row[$flag];
            }
        }

        foreach (['lock_version', 'min_milli', 'max_milli'] as $number) {
            if (array_key_exists($number, $row) && $row[$number] !== null) {
                $row[$number] = (int) $row[$number];
            }
        }

        return $row;
    }

    private function authorize(PropertyId $property, string $actorId): void
    {
        $this->assertProperty($property);

        if (! $this->may($property, $actorId, self::MANAGE_PERMISSION)) {
            throw Refusal::forbidden('This person may not change the inventory catalog.');
        }
    }

    private function code(string $code, int $max, string $field): string
    {
        $code = strtoupper(trim($code));

        if (preg_match('/^[A-Z0-9][A-Z0-9._-]*$/', $code) !== 1 || strlen($code) > $max) {
            throw Refusal::invalid("Use letters, digits, dots, dashes or underscores, at most {$max} characters.", [$field]);
        }

        return $code;
    }

    private function name(string $name, int $max): string
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > $max) {
            throw Refusal::invalid("Give a name of at most {$max} characters.", ['name']);
        }

        return $name;
    }

    private function unit(string $unit, string $field): string
    {
        $unit = strtoupper(trim($unit));

        if (preg_match('/^[A-Z0-9]{1,8}$/', $unit) !== 1) {
            throw Refusal::invalid('Use a unit code of one to eight letters or digits, for example BTL, DUS, KG or GR.', [$field]);
        }

        return $unit;
    }

    private function department(string $department): void
    {
        if (! in_array($department, self::DEPARTMENTS, true)) {
            throw Refusal::invalid('Choose the department that owns the item.', ['department']);
        }
    }

    private function kind(string $kind): void
    {
        if (! in_array($kind, self::LOCATION_KINDS, true)) {
            throw Refusal::invalid('Choose the kind of location.', ['kind']);
        }
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
