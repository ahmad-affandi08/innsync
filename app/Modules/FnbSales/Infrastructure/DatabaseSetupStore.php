<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Infrastructure;

use App\Modules\FnbSales\Application\SetupStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class DatabaseSetupStore implements SetupStore
{
    public function outlets(PropertyId $property): array
    {
        return $this->rows(DB::table('fnb_outlets')->where('property_id', $property->toString())->orderBy('name')->orderBy('code')->get());
    }

    public function outlet(PropertyId $property, string $id): ?array
    {
        return $this->row(DB::table('fnb_outlets')->where('property_id', $property->toString())->where('id', $id)->first());
    }

    public function addOutlet(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        return $this->insert('fnb_outlets', [...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
    }

    public function updateOutlet(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return $this->update('fnb_outlets', $property, $id, $lock, $fields, $at);
    }

    public function tables(PropertyId $property, string $outletId): array
    {
        return $this->rows(DB::table('fnb_tables')->where('property_id', $property->toString())->where('outlet_id', $outletId)->orderBy('area')->orderBy('code')->get());
    }

    public function table(PropertyId $property, string $id): ?array
    {
        return $this->row(DB::table('fnb_tables')->where('property_id', $property->toString())->where('id', $id)->first());
    }

    public function addTable(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        return $this->insert('fnb_tables', [...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
    }

    public function updateTable(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return $this->update('fnb_tables', $property, $id, $lock, $fields, $at);
    }

    public function categories(PropertyId $property, string $outletId): array
    {
        return $this->rows(DB::table('fnb_menu_categories')->where('property_id', $property->toString())->where('outlet_id', $outletId)->orderBy('sort_order')->orderBy('name')->get());
    }

    public function category(PropertyId $property, string $id): ?array
    {
        return $this->row(DB::table('fnb_menu_categories')->where('property_id', $property->toString())->where('id', $id)->first());
    }

    public function addCategory(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        return $this->insert('fnb_menu_categories', [...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
    }

    public function updateCategory(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return $this->update('fnb_menu_categories', $property, $id, $lock, $fields, $at);
    }

    public function items(PropertyId $property, string $outletId): array
    {
        $rows = $this->rows(DB::table('fnb_menu_items as i')->join('fnb_menu_categories as c', 'c.id', '=', 'i.category_id')->where('i.property_id', $property->toString())->where('c.outlet_id', $outletId)
            ->orderBy('c.sort_order')->orderBy('c.name')->orderBy('i.sort_order')->orderBy('i.name')->get(['i.*', 'c.outlet_id', 'c.station as category_station']));

        return $this->withChildren($rows);
    }

    public function item(PropertyId $property, string $id): ?array
    {
        $row = $this->row(DB::table('fnb_menu_items as i')->join('fnb_menu_categories as c', 'c.id', '=', 'i.category_id')->where('i.property_id', $property->toString())->where('i.id', $id)->first(['i.*', 'c.outlet_id', 'c.station as category_station']));

        return $row === null ? null : $this->withChildren([$row])[0];
    }

    public function addItem(PropertyId $property, array $row, array $variants, array $groupIds, DateTimeImmutable $at): bool
    {
        if (! $this->insert('fnb_menu_items', [...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at])) {
            return false;
        }

        $this->syncVariants((string) $row['id'], $variants, $at);
        $this->syncGroups((string) $row['id'], $groupIds);

        return true;
    }

    public function updateItem(PropertyId $property, string $id, int $lock, array $fields, array $variants, array $groupIds, DateTimeImmutable $at): bool
    {
        if (! $this->update('fnb_menu_items', $property, $id, $lock, $fields, $at)) {
            return false;
        }

        $this->syncVariants($id, $variants, $at);
        $this->syncGroups($id, $groupIds);

        return true;
    }

    public function groups(PropertyId $property): array
    {
        $groups = $this->rows(DB::table('fnb_modifier_groups')->where('property_id', $property->toString())->orderBy('name')->orderBy('code')->get());

        return $this->withModifiers($groups);
    }

    public function group(PropertyId $property, string $id): ?array
    {
        $row = $this->row(DB::table('fnb_modifier_groups')->where('property_id', $property->toString())->where('id', $id)->first());

        return $row === null ? null : $this->withModifiers([$row])[0];
    }

    public function addGroup(PropertyId $property, array $row, array $modifiers, DateTimeImmutable $at): bool
    {
        if (! $this->insert('fnb_modifier_groups', [...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at])) {
            return false;
        }

        $this->syncModifiers((string) $row['id'], $modifiers, $at);

        return true;
    }

    public function updateGroup(PropertyId $property, string $id, int $lock, array $fields, array $modifiers, DateTimeImmutable $at): bool
    {
        if (! $this->update('fnb_modifier_groups', $property, $id, $lock, $fields, $at)) {
            return false;
        }

        $this->syncModifiers($id, $modifiers, $at);

        return true;
    }

    /** @param list<array<string, mixed>> $variants */
    private function syncVariants(string $itemId, array $variants, DateTimeImmutable $at): void
    {
        $this->syncChildren('fnb_item_variants', 'item_id', $itemId, $variants, ['name', 'price_minor'], $at);
    }

    /** @param list<array<string, mixed>> $modifiers */
    private function syncModifiers(string $groupId, array $modifiers, DateTimeImmutable $at): void
    {
        $this->syncChildren('fnb_modifiers', 'group_id', $groupId, $modifiers, ['name', 'price_delta_minor'], $at);
    }

    /**
     * @param  list<array<string, mixed>>  $children
     * @param  list<string>  $columns
     */
    private function syncChildren(string $table, string $parentColumn, string $parentId, array $children, array $columns, DateTimeImmutable $at): void
    {
        $kept = [];

        foreach (array_values($children) as $position => $child) {
            $values = array_intersect_key($child, array_flip($columns));
            $id = isset($child['id']) && is_string($child['id']) && $child['id'] !== '' ? $child['id'] : null;

            if ($id !== null && DB::table($table)->where($parentColumn, $parentId)->where('id', $id)->exists()) {
                DB::table($table)->where($parentColumn, $parentId)->where('id', $id)->update([...$values, 'sort_order' => $position, 'is_active' => true, 'updated_at' => $at]);
                $kept[] = $id;

                continue;
            }

            // A choice that was taken off the list and is put back by the same name is the same row again.
            $same = DB::table($table)->where($parentColumn, $parentId)->where('name', $values['name'])->value('id');

            if (is_string($same)) {
                DB::table($table)->where('id', $same)->update([...$values, 'sort_order' => $position, 'is_active' => true, 'updated_at' => $at]);
                $kept[] = $same;

                continue;
            }

            $id = strtolower((string) Str::ulid());
            DB::table($table)->insert([...$values, 'id' => $id, $parentColumn => $parentId, 'sort_order' => $position, 'is_active' => true, 'created_at' => $at, 'updated_at' => $at]);
            $kept[] = $id;
        }

        DB::table($table)->where($parentColumn, $parentId)->whereNotIn('id', $kept)->update(['is_active' => false, 'updated_at' => $at]);
    }

    /** @param list<string> $groupIds */
    private function syncGroups(string $itemId, array $groupIds): void
    {
        DB::table('fnb_item_modifier_groups')->where('item_id', $itemId)->delete();

        foreach (array_values(array_unique($groupIds)) as $groupId) {
            DB::table('fnb_item_modifier_groups')->insert(['item_id' => $itemId, 'group_id' => $groupId]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function withChildren(array $items): array
    {
        $ids = array_column($items, 'id');
        $variants = DB::table('fnb_item_variants')->whereIn('item_id', $ids)->orderBy('sort_order')->get()->groupBy('item_id');
        $groups = DB::table('fnb_item_modifier_groups')->whereIn('item_id', $ids)->get()->groupBy('item_id');

        foreach ($items as &$item) {
            $item['variants'] = $this->rows($variants[$item['id']] ?? []);
            $item['group_ids'] = ($groups[$item['id']] ?? collect())->pluck('group_id')->values()->all();
        }

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    private function withModifiers(array $groups): array
    {
        $modifiers = DB::table('fnb_modifiers')->whereIn('group_id', array_column($groups, 'id'))->orderBy('sort_order')->get()->groupBy('group_id');

        foreach ($groups as &$group) {
            $group['modifiers'] = $this->rows($modifiers[$group['id']] ?? []);
        }

        return $groups;
    }

    /** @param array<string, mixed> $fields */
    private function update(string $table, PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table($table)->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }

    /** @param array<string, mixed> $row */
    private function insert(string $table, array $row): bool
    {
        try {
            DB::table($table)->insert($row);

            return true;
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return false;
            }

            throw $e;
        }
    }

    /** @return array<string, mixed>|null */
    private function row(?object $row): ?array
    {
        return $row === null ? null : (array) $row;
    }

    /** @param iterable<object> $rows @return list<array<string, mixed>> */
    private function rows(iterable $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[] = (array) $row;
        }

        return $out;
    }
}
