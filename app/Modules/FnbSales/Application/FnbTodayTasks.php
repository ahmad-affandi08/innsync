<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Application;

use App\Shared\Application\Setup\TodayTasks;
use App\Shared\Domain\Tenancy\PropertyId;

/** Bills still open at the outlets, for a person who works the point of sale. */
final readonly class FnbTodayTasks implements TodayTasks
{
    public function __construct(private SetupStore $setup, private BillStore $bills, private FnbAccess $access) {}

    public function tasks(PropertyId $property, string $actorId): array
    {
        if (! $this->access->may($property, $actorId, FnbAccess::POS_OPERATE)) {
            return [];
        }

        $open = 0;

        foreach ($this->setup->outlets($property) as $outlet) {
            if ((bool) ($outlet['is_active'] ?? true)) {
                $open += count($this->bills->openBills($property, (string) $outlet['id']));
            }
        }

        return [['key' => 'fnb.bills', 'count' => $open, 'href' => '/fnb/pos']];
    }
}
