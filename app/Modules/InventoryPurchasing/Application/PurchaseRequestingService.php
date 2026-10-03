<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Shared\Domain\Tenancy\PropertyId;

final readonly class PurchaseRequestingService implements PurchaseRequesting
{
    public function __construct(private PurchaseRequestService $requests, private PurchasingStore $store, private PurchasingAccess $access) {}

    public function draft(PropertyId $property, string $actorId, string $department, string $urgency, string $reason, string $neededBy, array $lines): array
    {
        $made = $this->requests->create($property, $actorId, $department, $urgency, $reason, $neededBy, array_map(static fn (array $l): array => [
            'item_id' => $l['item_id'], 'unit' => $l['unit'], 'quantity' => number_format($l['quantity_milli'] / 1000, 3, '.', ''), 'note' => $l['note'] ?? null,
        ], $lines));

        return ['id' => (string) $made['id'], 'number' => (string) $made['number']];
    }

    public function statusOf(PropertyId $property, array $ids): array
    {
        $this->access->assertProperty($property);
        $out = [];

        foreach ($ids as $id) {
            $r = $this->store->request($property, strtolower($id));

            if ($r !== null) {
                $out[$r['id']] = ['number' => $r['number'], 'status' => $r['status'], 'total_minor' => (int) $r['total_minor']];
            }
        }

        return $out;
    }
}
