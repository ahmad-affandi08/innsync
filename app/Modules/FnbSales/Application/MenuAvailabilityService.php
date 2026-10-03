<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Application;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

final readonly class MenuAvailabilityService implements MenuAvailability
{
    public function __construct(private SetupStore $store, private FnbAccess $access, private TransactionRunner $transactions, private AuditTrail $audit, private Clock $clock) {}

    public function items(PropertyId $property): array
    {
        $this->access->assertProperty($property);
        $out = [];

        foreach ($this->store->outlets($property) as $outlet) {
            if (! (bool) $outlet['is_active']) {
                continue;
            }

            $categories = [];

            foreach ($this->store->categories($property, $outlet['id']) as $c) {
                $categories[$c['id']] = $c;
            }

            foreach ($this->store->items($property, $outlet['id']) as $i) {
                $category = $categories[$i['category_id']] ?? null;

                if ((bool) $i['is_active'] && $category !== null && (bool) $category['is_active']) {
                    $out[] = ['id' => $i['id'], 'code' => $i['code'], 'name' => $i['name'], 'category' => $category['name'], 'outlet' => $outlet['name'], 'is_available' => (bool) $i['is_available']];
                }
            }
        }

        return $out;
    }

    public function set(PropertyId $property, string $actorId, string $itemId, bool $available): void
    {
        $this->access->assertProperty($property);
        $item = $this->store->item($property, strtolower($itemId)) ?? throw Refusal::notFound('Item not found.');

        if ((bool) $item['is_available'] === $available) {
            return;
        }

        $this->transactions->run(function () use ($property, $actorId, $item, $available): void {
            if (! $this->store->updateItem($property, $item['id'], (int) $item['lock_version'], ['is_available' => $available], array_values(array_map(static fn (array $v): array => ['id' => $v['id'], 'name' => $v['name'], 'price_minor' => (int) $v['price_minor']], array_filter($item['variants'], static fn (array $v): bool => (bool) $v['is_active']))), $item['group_ids'], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This item changed meanwhile. Try again.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), $available ? 'fnb_item.on_sale' : 'fnb_item.sold_out', 'fnb_item', $item['id'], ['is_available' => (bool) $item['is_available']], ['is_available' => $available], 'Set by the kitchen'));
        });
    }
}
