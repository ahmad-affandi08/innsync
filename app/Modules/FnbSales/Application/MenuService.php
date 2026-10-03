<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Application;

use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The menu of an outlet (FR-FBS-002, FR-FBS-011): categories that say which station prepares what is in them, items with a price, sizes or kinds (variants) that
 * carry their own price, and groups of add-ons or choices (doneness, extra cheese) that an item takes, so one dish is one item however it is ordered.
 * An item that is sold out is marked unavailable and cannot be ordered; whoever takes orders may mark that, only the menu's owner changes the rest. Nothing is
 * removed: a category, item, variant, group or choice that is not wanted any more is deactivated, because bills point at them.
 */
final readonly class MenuService
{
    public const STATIONS = ['kitchen', 'bar', 'none'];

    public const MAX_PRICE_MINOR = 9_000_000_000_000;

    public function __construct(
        private SetupStore $store,
        private FnbAccess $access,
        private PropertyCurrencyReader $currencies,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function menu(PropertyId $property, string $actorId, ?string $outletId): array
    {
        $this->access->requireView($property, $actorId);
        $outlets = $this->store->outlets($property);
        $selected = null;

        foreach ($outlets as $o) {
            if ($outletId !== null ? $o['id'] === strtolower($outletId) : (bool) $o['is_active']) {
                $selected = $o;

                break;
            }
        }

        if ($outletId !== null && $selected === null) {
            throw Refusal::notFound('Outlet not found.');
        }

        $categories = $selected === null ? [] : $this->store->categories($property, $selected['id']);
        $items = $selected === null ? [] : $this->store->items($property, $selected['id']);

        return [
            'currency' => $this->currencies->currencyOf($property),
            'outlets' => array_map(static fn (array $o): array => ['id' => $o['id'], 'code' => $o['code'], 'name' => $o['name'], 'is_active' => (bool) $o['is_active']], $outlets),
            'outlet' => $selected === null ? null : ['id' => $selected['id'], 'code' => $selected['code'], 'name' => $selected['name'], 'prices_include_charges' => (bool) $selected['prices_include_charges']],
            'categories' => array_map($this->shapeCategory(...), $categories),
            'items' => array_map($this->shapeItem(...), $items),
            'groups' => array_map($this->shapeGroup(...), $this->store->groups($property)),
            'stations' => self::STATIONS,
            'may' => ['manage' => $this->access->may($property, $actorId, FnbAccess::SETUP_MANAGE), 'availability' => $this->access->may($property, $actorId, FnbAccess::POS_OPERATE) || $this->access->may($property, $actorId, FnbAccess::SETUP_MANAGE)],
        ];
    }

    // ---- categories ----

    /** @return array<string, mixed> */
    public function addCategory(PropertyId $property, string $actorId, string $outletId, string $code, string $name, string $station, int $sortOrder): array
    {
        $this->access->require($property, $actorId, FnbAccess::SETUP_MANAGE, 'This person may not change the menu.');
        $outlet = $this->store->outlet($property, strtolower($outletId)) ?? throw Refusal::notFound('Outlet not found.');
        $code = $this->code($code, 12);
        $name = $this->name($name, 80);
        $this->station($station, false);
        $sortOrder = $this->sort($sortOrder);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actorId, $outlet, $id, $code, $name, $station, $sortOrder): void {
            if (! $this->store->addCategory($property, ['id' => $id, 'outlet_id' => $outlet['id'], 'code' => $code, 'name' => $name, 'station' => $station, 'sort_order' => $sortOrder, 'is_active' => true], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This outlet has a category with this code already.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'fnb_category.created', 'fnb_category', $id, null, ['outlet' => $outlet['code'], 'code' => $code, 'name' => $name, 'station' => $station]));
        });

        return $this->shapeCategory($this->store->category($property, $id) ?? throw Refusal::notFound('Category not found.'));
    }

    /** @return array<string, mixed> */
    public function updateCategory(PropertyId $property, string $actorId, string $id, string $name, string $station, int $sortOrder, bool $active, int $lock): array
    {
        $this->access->require($property, $actorId, FnbAccess::SETUP_MANAGE, 'This person may not change the menu.');
        $before = $this->store->category($property, strtolower($id)) ?? throw Refusal::notFound('Category not found.');
        $name = $this->name($name, 80);
        $this->station($station, false);
        $sortOrder = $this->sort($sortOrder);

        $this->transactions->run(function () use ($property, $actorId, $before, $name, $station, $sortOrder, $active, $lock): void {
            if (! $this->store->updateCategory($property, $before['id'], $lock, ['name' => $name, 'station' => $station, 'sort_order' => $sortOrder, 'is_active' => $active], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This category changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'fnb_category.updated', 'fnb_category', $before['id'],
                ['name' => $before['name'], 'station' => $before['station'], 'sort_order' => (int) $before['sort_order'], 'is_active' => (bool) $before['is_active']], ['name' => $name, 'station' => $station, 'sort_order' => $sortOrder, 'is_active' => $active]));
        });

        return $this->shapeCategory($this->store->category($property, $before['id']) ?? throw Refusal::notFound('Category not found.'));
    }

    // ---- items ----

    /**
     * @param  list<array<string, mixed>>  $variants  each `{name, price_minor}`
     * @param  list<string>  $groupIds
     * @return array<string, mixed>
     */
    public function addItem(PropertyId $property, string $actorId, string $categoryId, string $code, string $name, ?string $description, int $priceMinor, ?string $station, array $variants, array $groupIds, int $sortOrder): array
    {
        $this->access->require($property, $actorId, FnbAccess::SETUP_MANAGE, 'This person may not change the menu.');
        $category = $this->store->category($property, strtolower($categoryId)) ?? throw Refusal::invalid('Choose a category of the menu.', ['category_id']);
        $code = $this->code($code, 16);
        [$name, $description, $variants, $groupIds] = $this->cleanItem($property, $name, $description, $priceMinor, $station, $variants, $groupIds);
        $sortOrder = $this->sort($sortOrder);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actorId, $category, $id, $code, $name, $description, $priceMinor, $station, $variants, $groupIds, $sortOrder): void {
            $row = ['id' => $id, 'category_id' => $category['id'], 'code' => $code, 'name' => $name, 'description' => $description, 'price_minor' => $priceMinor, 'station' => $station, 'is_available' => true, 'is_active' => true, 'sort_order' => $sortOrder];

            if (! $this->store->addItem($property, $row, $variants, $groupIds, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('A menu item with this code already exists.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'fnb_item.created', 'fnb_item', $id, null, ['code' => $code, 'name' => $name, 'category' => $category['code'], 'price_minor' => $priceMinor, 'variants' => $variants, 'groups' => $groupIds]));
        });

        return $this->shapeItem($this->store->item($property, $id) ?? throw Refusal::notFound('Item not found.'));
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     * @param  list<string>  $groupIds
     * @return array<string, mixed>
     */
    public function updateItem(PropertyId $property, string $actorId, string $id, string $categoryId, string $name, ?string $description, int $priceMinor, ?string $station, array $variants, array $groupIds, int $sortOrder, bool $active, int $lock): array
    {
        $this->access->require($property, $actorId, FnbAccess::SETUP_MANAGE, 'This person may not change the menu.');
        $before = $this->store->item($property, strtolower($id)) ?? throw Refusal::notFound('Item not found.');
        $category = $this->store->category($property, strtolower($categoryId)) ?? throw Refusal::invalid('Choose a category of the menu.', ['category_id']);
        [$name, $description, $variants, $groupIds] = $this->cleanItem($property, $name, $description, $priceMinor, $station, $variants, $groupIds);
        $sortOrder = $this->sort($sortOrder);

        $this->transactions->run(function () use ($property, $actorId, $before, $category, $name, $description, $priceMinor, $station, $variants, $groupIds, $sortOrder, $active, $lock): void {
            $fields = ['category_id' => $category['id'], 'name' => $name, 'description' => $description, 'price_minor' => $priceMinor, 'station' => $station, 'sort_order' => $sortOrder, 'is_active' => $active];

            if (! $this->store->updateItem($property, $before['id'], $lock, $fields, $variants, $groupIds, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This item changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'fnb_item.updated', 'fnb_item', $before['id'],
                ['name' => $before['name'], 'price_minor' => (int) $before['price_minor'], 'station' => $before['station'], 'is_active' => (bool) $before['is_active'], 'variants' => array_map(static fn (array $v): array => ['name' => $v['name'], 'price_minor' => (int) $v['price_minor'], 'is_active' => (bool) $v['is_active']], $before['variants']), 'groups' => $before['group_ids']],
                ['name' => $name, 'price_minor' => $priceMinor, 'station' => $station, 'is_active' => $active, 'variants' => $variants, 'groups' => $groupIds]));
        });

        return $this->shapeItem($this->store->item($property, $before['id']) ?? throw Refusal::notFound('Item not found.'));
    }

    /** Marks an item sold out or on sale again; anybody who takes orders may. @return array<string, mixed> */
    public function setAvailability(PropertyId $property, string $actorId, string $id, bool $available, int $lock): array
    {
        $this->access->assertProperty($property);

        if (! $this->access->may($property, $actorId, FnbAccess::POS_OPERATE) && ! $this->access->may($property, $actorId, FnbAccess::SETUP_MANAGE)) {
            throw Refusal::forbidden('This person may not mark items sold out.');
        }

        $before = $this->store->item($property, strtolower($id)) ?? throw Refusal::notFound('Item not found.');

        $this->transactions->run(function () use ($property, $actorId, $before, $available, $lock): void {
            if (! $this->store->updateItem($property, $before['id'], $lock, ['is_available' => $available], $this->variantRows($before['variants']), $before['group_ids'], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This item changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), $available ? 'fnb_item.on_sale' : 'fnb_item.sold_out', 'fnb_item', $before['id'], ['is_available' => (bool) $before['is_available']], ['is_available' => $available]));
        });

        return $this->shapeItem($this->store->item($property, $before['id']) ?? throw Refusal::notFound('Item not found.'));
    }

    // ---- modifier groups ----

    /**
     * @param  list<array<string, mixed>>  $modifiers  each `{name, price_delta_minor}`
     * @return array<string, mixed>
     */
    public function addGroup(PropertyId $property, string $actorId, string $code, string $name, int $min, int $max, array $modifiers): array
    {
        $this->access->require($property, $actorId, FnbAccess::SETUP_MANAGE, 'This person may not change the menu.');
        $code = $this->code($code, 12);
        $name = $this->name($name, 60);
        $modifiers = $this->cleanGroup($min, $max, $modifiers);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actorId, $id, $code, $name, $min, $max, $modifiers): void {
            if (! $this->store->addGroup($property, ['id' => $id, 'code' => $code, 'name' => $name, 'min_select' => $min, 'max_select' => $max, 'is_active' => true], $modifiers, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('A group of choices with this code already exists.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'fnb_modifier_group.created', 'fnb_modifier_group', $id, null, ['code' => $code, 'name' => $name, 'min_select' => $min, 'max_select' => $max, 'modifiers' => $modifiers]));
        });

        return $this->shapeGroup($this->store->group($property, $id) ?? throw Refusal::notFound('Group not found.'));
    }

    /**
     * @param  list<array<string, mixed>>  $modifiers
     * @return array<string, mixed>
     */
    public function updateGroup(PropertyId $property, string $actorId, string $id, string $name, int $min, int $max, array $modifiers, bool $active, int $lock): array
    {
        $this->access->require($property, $actorId, FnbAccess::SETUP_MANAGE, 'This person may not change the menu.');
        $before = $this->store->group($property, strtolower($id)) ?? throw Refusal::notFound('Group not found.');
        $name = $this->name($name, 60);
        $modifiers = $this->cleanGroup($min, $max, $modifiers);

        $this->transactions->run(function () use ($property, $actorId, $before, $name, $min, $max, $modifiers, $active, $lock): void {
            if (! $this->store->updateGroup($property, $before['id'], $lock, ['name' => $name, 'min_select' => $min, 'max_select' => $max, 'is_active' => $active], $modifiers, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This group changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'fnb_modifier_group.updated', 'fnb_modifier_group', $before['id'],
                ['name' => $before['name'], 'min_select' => (int) $before['min_select'], 'max_select' => (int) $before['max_select'], 'is_active' => (bool) $before['is_active'], 'modifiers' => array_map(static fn (array $m): array => ['name' => $m['name'], 'price_delta_minor' => (int) $m['price_delta_minor'], 'is_active' => (bool) $m['is_active']], $before['modifiers'])],
                ['name' => $name, 'min_select' => $min, 'max_select' => $max, 'is_active' => $active, 'modifiers' => $modifiers]));
        });

        return $this->shapeGroup($this->store->group($property, $before['id']) ?? throw Refusal::notFound('Group not found.'));
    }

    // ---- validation ----

    private function code(string $code, int $max): string
    {
        $code = strtoupper(trim($code));

        if (preg_match('/^[A-Z0-9][A-Z0-9._-]{0,'.($max - 1).'}$/', $code) !== 1) {
            throw Refusal::invalid("The code is 1 to {$max} letters, digits, dot, dash or underscore.", ['code']);
        }

        return $code;
    }

    private function name(string $name, int $max): string
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > $max) {
            throw Refusal::invalid("Give the name, at most {$max} characters.", ['name']);
        }

        return $name;
    }

    private function station(?string $station, bool $nullable): void
    {
        if (($station === null && $nullable) || in_array($station, self::STATIONS, true)) {
            return;
        }

        throw Refusal::invalid('Choose the station that prepares it: kitchen, bar or none.', ['station']);
    }

    private function sort(int $sortOrder): int
    {
        if ($sortOrder < 0 || $sortOrder > 9999) {
            throw Refusal::invalid('The order is a number from 0 to 9999.', ['sort_order']);
        }

        return $sortOrder;
    }

    private function price(int $minor, string $field): void
    {
        if ($minor < 0 || $minor > self::MAX_PRICE_MINOR) {
            throw Refusal::invalid('Give a price of zero or more, at most nine trillion.', [$field]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     * @param  list<string>  $groupIds
     * @return array{0: string, 1: string|null, 2: list<array<string, mixed>>, 3: list<string>}
     */
    private function cleanItem(PropertyId $property, string $name, ?string $description, int $priceMinor, ?string $station, array $variants, array $groupIds): array
    {
        $name = $this->name($name, 80);
        $description = $description === null ? null : trim($description);
        $description = $description === '' ? null : $description;

        if ($description !== null && mb_strlen($description) > 200) {
            throw Refusal::invalid('The description is at most 200 characters.', ['description']);
        }

        $this->price($priceMinor, 'price_minor');
        $this->station($station, true);

        if (count($variants) > 12) {
            throw Refusal::invalid('An item has at most 12 variants.', ['variants']);
        }

        $clean = [];
        $seen = [];

        foreach ($variants as $v) {
            $variantName = $this->choiceName($v['name'] ?? '', 40, 'variants');
            $this->price((int) ($v['price_minor'] ?? -1), 'variants');

            if (isset($seen[mb_strtolower($variantName)])) {
                throw Refusal::invalid('Two variants have the same name.', ['variants']);
            }

            $seen[mb_strtolower($variantName)] = true;
            $clean[] = ['id' => isset($v['id']) && is_string($v['id']) ? strtolower($v['id']) : null, 'name' => $variantName, 'price_minor' => (int) $v['price_minor']];
        }

        $groupIds = array_values(array_unique(array_map('strtolower', $groupIds)));

        foreach ($groupIds as $groupId) {
            $group = $this->store->group($property, $groupId);

            if ($group === null || ! (bool) $group['is_active']) {
                throw Refusal::invalid('Choose groups of choices that exist and are in use.', ['group_ids']);
            }
        }

        return [$name, $description, $clean, $groupIds];
    }

    /**
     * @param  list<array<string, mixed>>  $modifiers
     * @return list<array<string, mixed>>
     */
    private function cleanGroup(int $min, int $max, array $modifiers): array
    {
        if ($min < 0 || $max < 1 || $max > 10 || $min > $max) {
            throw Refusal::invalid('Choose how many the guest picks: at least from 0, at most from 1 to 10, and not fewer than the least.', ['max_select']);
        }

        if (count($modifiers) < 1 || count($modifiers) > 30) {
            throw Refusal::invalid('A group offers 1 to 30 choices.', ['modifiers']);
        }

        $clean = [];
        $seen = [];

        foreach ($modifiers as $m) {
            $name = $this->choiceName($m['name'] ?? '', 40, 'modifiers');
            $this->price((int) ($m['price_delta_minor'] ?? -1), 'modifiers');

            if (isset($seen[mb_strtolower($name)])) {
                throw Refusal::invalid('Two choices have the same name.', ['modifiers']);
            }

            $seen[mb_strtolower($name)] = true;
            $clean[] = ['id' => isset($m['id']) && is_string($m['id']) ? strtolower($m['id']) : null, 'name' => $name, 'price_delta_minor' => (int) $m['price_delta_minor']];
        }

        if ($min > count($clean)) {
            throw Refusal::invalid('The guest cannot be asked to pick more than the group offers.', ['min_select']);
        }

        return $clean;
    }

    private function choiceName(mixed $name, int $max, string $field): string
    {
        $name = is_string($name) ? trim($name) : '';

        if ($name === '' || mb_strlen($name) > $max) {
            throw Refusal::invalid("Every choice has a name of at most {$max} characters.", [$field]);
        }

        return $name;
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     * @return list<array<string, mixed>>
     */
    private function variantRows(array $variants): array
    {
        return array_values(array_map(static fn (array $v): array => ['id' => $v['id'], 'name' => $v['name'], 'price_minor' => (int) $v['price_minor']], array_filter($variants, static fn (array $v): bool => (bool) $v['is_active'])));
    }

    // ---- shapes ----

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    private function shapeCategory(array $c): array
    {
        return ['id' => $c['id'], 'outlet_id' => $c['outlet_id'], 'code' => $c['code'], 'name' => $c['name'], 'station' => $c['station'], 'sort_order' => (int) $c['sort_order'], 'is_active' => (bool) $c['is_active'], 'lock_version' => (int) $c['lock_version']];
    }

    /**
     * @param  array<string, mixed>  $i
     * @return array<string, mixed>
     */
    private function shapeItem(array $i): array
    {
        return [
            'id' => $i['id'], 'category_id' => $i['category_id'], 'outlet_id' => $i['outlet_id'], 'code' => $i['code'], 'name' => $i['name'], 'description' => $i['description'], 'price_minor' => (int) $i['price_minor'],
            'station' => $i['station'], 'effective_station' => $i['station'] ?? $i['category_station'], 'is_available' => (bool) $i['is_available'], 'is_active' => (bool) $i['is_active'], 'sort_order' => (int) $i['sort_order'], 'lock_version' => (int) $i['lock_version'],
            'variants' => array_map(static fn (array $v): array => ['id' => $v['id'], 'name' => $v['name'], 'price_minor' => (int) $v['price_minor'], 'is_active' => (bool) $v['is_active']], $i['variants']), 'group_ids' => $i['group_ids'],
        ];
    }

    /**
     * @param  array<string, mixed>  $g
     * @return array<string, mixed>
     */
    private function shapeGroup(array $g): array
    {
        return [
            'id' => $g['id'], 'code' => $g['code'], 'name' => $g['name'], 'min_select' => (int) $g['min_select'], 'max_select' => (int) $g['max_select'], 'is_active' => (bool) $g['is_active'], 'lock_version' => (int) $g['lock_version'],
            'modifiers' => array_map(static fn (array $m): array => ['id' => $m['id'], 'name' => $m['name'], 'price_delta_minor' => (int) $m['price_delta_minor'], 'is_active' => (bool) $m['is_active']], $g['modifiers']),
        ];
    }
}
