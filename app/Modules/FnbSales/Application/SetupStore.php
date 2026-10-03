<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Storage of the outlets, tables, menu and modifiers. Rows are plain arrays; every query is scoped to the property. */
interface SetupStore
{
    /** @return list<array<string, mixed>> */
    public function outlets(PropertyId $property): array;

    /** @return array<string, mixed>|null */
    public function outlet(PropertyId $property, string $id): ?array;

    /** @param array<string, mixed> $row @return bool false when the code is taken */
    public function addOutlet(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $fields @return bool false when the outlet changed meanwhile */
    public function updateOutlet(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> */
    public function tables(PropertyId $property, string $outletId): array;

    /** @return array<string, mixed>|null */
    public function table(PropertyId $property, string $id): ?array;

    /** @param array<string, mixed> $row @return bool false when the code is taken in the outlet */
    public function addTable(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $fields @return bool */
    public function updateTable(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> */
    public function categories(PropertyId $property, string $outletId): array;

    /** @return array<string, mixed>|null */
    public function category(PropertyId $property, string $id): ?array;

    /** @param array<string, mixed> $row @return bool false when the code is taken in the outlet */
    public function addCategory(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $fields */
    public function updateCategory(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> items of an outlet with their variants and the ids of their modifier groups */
    public function items(PropertyId $property, string $outletId): array;

    /** @return array<string, mixed>|null the item with `outlet_id`, `variants` and `group_ids` */
    public function item(PropertyId $property, string $id): ?array;

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $variants
     * @param  list<string>  $groupIds
     * @return bool false when the code is taken
     */
    public function addItem(PropertyId $property, array $row, array $variants, array $groupIds, DateTimeImmutable $at): bool;

    /**
     * Variants with an id are updated, those without are added, and active ones that are not sent are deactivated, never removed.
     *
     * @param  array<string, mixed>  $fields
     * @param  list<array<string, mixed>>  $variants
     * @param  list<string>  $groupIds
     */
    public function updateItem(PropertyId $property, string $id, int $lock, array $fields, array $variants, array $groupIds, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> groups with their modifiers */
    public function groups(PropertyId $property): array;

    /** @return array<string, mixed>|null */
    public function group(PropertyId $property, string $id): ?array;

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $modifiers
     * @return bool false when the code is taken
     */
    public function addGroup(PropertyId $property, array $row, array $modifiers, DateTimeImmutable $at): bool;

    /**
     * @param  array<string, mixed>  $fields
     * @param  list<array<string, mixed>>  $modifiers  as the variants of an item: updated, added, or deactivated when left out
     */
    public function updateGroup(PropertyId $property, string $id, int $lock, array $fields, array $modifiers, DateTimeImmutable $at): bool;
}
