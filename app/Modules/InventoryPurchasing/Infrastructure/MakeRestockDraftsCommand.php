<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Infrastructure;

use App\Modules\InventoryPurchasing\Application\RestockDraftService;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Every morning, from the scheduler. Drafts a purchase request for what is below its minimum stock; one property failing must not stop the others. */
final class MakeRestockDraftsCommand extends Command
{
    protected $signature = 'purchasing:restock-drafts';

    protected $description = 'Draft purchase requests for items below their minimum stock';

    public function handle(RestockDraftService $service, PropertyContext $context): int
    {
        if (! (bool) config('inventory.restock.enabled')) {
            $this->line('{"drafts":0,"disabled":true}');

            return self::SUCCESS;
        }

        $drafts = 0;
        $failed = false;

        foreach (DB::table('inventory_stock_limits')->distinct()->pluck('property_id') as $id) {
            try {
                Context::scope(function () use ($service, $context, $id, &$drafts): void {
                    $property = PropertyId::fromString((string) $id);
                    $drafts += $context->run($property, static fn (): int => $service->run($property, (int) config('inventory.restock.lead_days'))['drafts']);
                });
            } catch (Throwable $exception) {
                $failed = true;
                report($exception);
                $this->error("Property {$id}: restock drafts failed.");
            }
        }

        $this->line(json_encode(['drafts' => $drafts, 'failed' => $failed], JSON_THROW_ON_ERROR));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
