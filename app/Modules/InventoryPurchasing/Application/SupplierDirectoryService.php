<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Shared\Domain\Tenancy\PropertyId;

final readonly class SupplierDirectoryService implements SupplierDirectory
{
    public function __construct(private PurchasingStore $store, private PurchasingAccess $access) {}

    public function active(PropertyId $property): array
    {
        $this->access->assertProperty($property);
        $rows = array_filter($this->store->suppliers($property), static fn (array $s): bool => (bool) $s['is_active']);
        usort($rows, static fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));

        return array_values(array_map(static fn (array $s): array => ['id' => $s['id'], 'code' => $s['code'], 'name' => $s['name']], $rows));
    }

    public function find(PropertyId $property, string $id): ?array
    {
        $this->access->assertProperty($property);
        $s = $this->store->supplier($property, strtolower($id));

        return $s === null ? null : ['id' => $s['id'], 'code' => $s['code'], 'name' => $s['name'], 'is_active' => (bool) $s['is_active']];
    }
}
