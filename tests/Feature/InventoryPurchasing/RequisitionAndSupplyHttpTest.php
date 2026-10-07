<?php

declare(strict_types=1);

namespace Tests\Feature\InventoryPurchasing;

use App\Modules\Housekeeping\Application\HousekeepingService;
use App\Modules\Housekeeping\Application\SupplyUseService as HousekeepingSupplyUseService;
use App\Modules\InventoryPurchasing\Application\InventoryCatalogService;
use App\Modules\InventoryPurchasing\Application\StockRequisitionService;
use App\Modules\InventoryPurchasing\Application\StockService;
use App\Modules\InventoryPurchasing\Application\StockTransferService;
use App\Modules\Kitchen\Application\KitchenAccess;
use App\Modules\Kitchen\Application\SupplyUseService as KitchenSupplyUseService;
use App\Modules\Laundry\Application\LaundryService;
use App\Modules\Laundry\Application\SupplyUseService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-FBS-030, FR-LDY-008, FR-KIT-007, FR-FBS-031: what an outlet asks of the main store, the supplies the laundry uses up, and the counts of a department's own stores. */
final class RequisitionAndSupplyHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private string $item;

    private string $soap;

    private string $main;

    private string $bar;

    private string $kitchen;

    private int $keys = 0;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Feature tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createProperty(self::A, 'A');
        $this->as([PropertySettingsService::MANAGE_PERMISSION, InventoryCatalogService::MANAGE_PERMISSION, StockService::POST_PERMISSION, StockService::VIEW_PERMISSION]);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $category = $this->postJson('/inventory/categories', ['code' => 'GEN', 'name' => 'General'])->assertCreated()->json('category.id');
        $this->item = $this->postJson('/inventory/items', ['code' => 'WATER', 'name' => 'Water', 'category_id' => $category, 'department' => 'fnb', 'base_unit' => 'BTL'])->assertCreated()->json('item.id');
        $this->soap = $this->postJson('/inventory/items', ['code' => 'DET', 'name' => 'Detergent', 'category_id' => $category, 'department' => 'laundry', 'base_unit' => 'KG'])->assertCreated()->json('item.id');
        $this->main = $this->postJson('/inventory/locations', ['code' => 'MAIN', 'name' => 'Main store', 'kind' => 'main'])->assertCreated()->json('location.id');
        $this->bar = $this->postJson('/inventory/locations', ['code' => 'BAR', 'name' => 'Bar store', 'kind' => 'bar'])->assertCreated()->json('location.id');
        $this->kitchen = $this->postJson('/inventory/locations', ['code' => 'KIT', 'name' => 'Kitchen store', 'kind' => 'kitchen'])->assertCreated()->json('location.id');
        $this->postJson('/inventory/stock/opening', ['unit_cost_minor' => 1_000, 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '100'])->assertCreated();
        $this->postJson('/inventory/stock/opening', ['unit_cost_minor' => 20_000, 'item_id' => $this->soap, 'location_id' => $this->main, 'unit' => 'KG', 'quantity' => '10'])->assertCreated();
    }

    /** @param list<string> $permissions */
    private function as(array $permissions): string
    {
        $this->post('/logout');

        return (string) $this->signIn(self::A, $permissions)->getKey();
    }

    /** @return array<string, string> */
    private function key(): array
    {
        return ['Idempotency-Key' => 'rq-'.(++$this->keys).'-'.str_repeat('x', 20)];
    }

    private function balance(string $item, string $location): int
    {
        return (int) DB::table('stock_movements')->where('item_id', $item)->where('location_id', $location)->sum('base_qty_milli');
    }

    /** @return array<string, mixed> */
    private function ask(int $status = 201, array $o = []): array
    {
        return $this->postJson('/inventory/requisitions', ['requesting_location_id' => $this->bar, 'supplying_location_id' => $this->main, 'note' => 'Weekend stock', 'lines' => [['item_id' => $this->item, 'unit' => 'BTL', 'quantity' => '24']], ...$o])->assertStatus($status)->json('requisition') ?? [];
    }

    public function test_an_outlet_asks_the_main_store_which_sends_what_it_can_spare_as_a_transfer(): void
    {
        $outlet = $this->as([StockRequisitionService::REQUEST_PERMISSION]);
        $r = $this->ask();
        self::assertSame(['requested', 'RQ-000001', 'Bar store', 'Main store'], [$r['status'], $r['number'], $r['requesting'], $r['supplying']]);
        self::assertTrue($r['may']['cancel']);
        self::assertFalse($r['may']['fulfil']);

        // The main store sends less than was asked; the stock moves only through the transfer, which the outlet receives.
        $this->as([StockTransferService::SEND_PERMISSION, StockTransferService::RECEIVE_PERMISSION, StockService::VIEW_PERMISSION]);
        $line = $r['lines'][0]['id'];
        $this->postJson("/inventory/requisitions/{$r['id']}/fulfil", ['lock_version' => 5, 'quantities' => [$line => '12']], $this->key())->assertStatus(409);
        $this->postJson("/inventory/requisitions/{$r['id']}/fulfil", ['lock_version' => 0, 'quantities' => [$line => '30']], $this->key())->assertStatus(422);
        $this->postJson("/inventory/requisitions/{$r['id']}/fulfil", ['lock_version' => 0, 'quantities' => [$line => '0']], $this->key())->assertStatus(422);
        self::assertSame(100_000, $this->balance($this->item, $this->main));
        $done = $this->postJson("/inventory/requisitions/{$r['id']}/fulfil", ['lock_version' => 0, 'quantities' => [$line => '12']], $this->key())->assertOk()->assertJsonPath('requisition.status', 'fulfilled')->json('requisition');
        self::assertNotNull($done['transfer_id']);
        $transfer = (array) DB::table('stock_transfers')->where('id', $done['transfer_id'])->first();
        self::assertSame(['sent', $this->main, $this->bar], [$transfer['status'], $transfer['from_location_id'], $transfer['to_location_id']]);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'requisition.fulfilled')->count());
        $this->postJson("/inventory/requisitions/{$r['id']}/fulfil", ['lock_version' => 1], $this->key())->assertStatus(409);
        $this->as([StockTransferService::RECEIVE_PERMISSION]);
        $this->postJson("/inventory/transfers/{$done['transfer_id']}/receive", ['lock_version' => 0])->assertOk();
        self::assertSame(12_000, $this->balance($this->item, $this->bar));
        self::assertSame(88_000, $this->balance($this->item, $this->main));
        self::assertNotNull($outlet);
    }

    public function test_a_requisition_is_checked_refused_with_a_reason_and_withdrawn_only_by_who_asked(): void
    {
        $this->as([StockRequisitionService::REQUEST_PERMISSION]);
        $this->ask(422, ['lines' => []]);
        $this->ask(422, ['supplying_location_id' => $this->bar]);
        $this->ask(422, ['lines' => [['item_id' => $this->item, 'unit' => 'BTL', 'quantity' => '0']]]);
        $this->ask(422, ['lines' => [['item_id' => $this->item, 'unit' => 'CRATE', 'quantity' => '1']]]);
        $this->ask(422, ['lines' => [['item_id' => $this->item, 'unit' => 'BTL', 'quantity' => '1'], ['item_id' => $this->item, 'unit' => 'BTL', 'quantity' => '2']]]);
        $this->ask(422, ['requesting_location_id' => '01arz3ndektsv4rrffq69g5fc9']);
        self::assertSame(0, DB::table('inventory_requisitions')->count());

        $withdrawn = $this->ask();
        $refused = $this->ask();
        $this->postJson("/inventory/requisitions/{$withdrawn['id']}/cancel", ['lock_version' => 0])->assertOk()->assertJsonPath('requisition.status', 'cancelled');
        $this->postJson("/inventory/requisitions/{$withdrawn['id']}/cancel", ['lock_version' => 1])->assertStatus(409);
        $this->postJson("/inventory/requisitions/{$refused['id']}/reject", ['note' => 'No stock', 'lock_version' => 0])->assertStatus(403);

        $this->as([StockTransferService::SEND_PERMISSION]);
        $this->postJson("/inventory/requisitions/{$refused['id']}/cancel", ['lock_version' => 0])->assertStatus(403);
        $this->postJson("/inventory/requisitions/{$refused['id']}/reject", ['note' => '', 'lock_version' => 0])->assertStatus(422);
        $this->postJson("/inventory/requisitions/{$refused['id']}/reject", ['note' => 'Next delivery is Friday', 'lock_version' => 0])->assertOk()->assertJsonPath('requisition.status', 'rejected')->assertJsonPath('requisition.decision_note', 'Next delivery is Friday');
        $this->postJson('/inventory/requisitions', ['requesting_location_id' => $this->bar, 'supplying_location_id' => $this->main, 'lines' => [['item_id' => $this->item, 'unit' => 'BTL', 'quantity' => '1']]])->assertStatus(403);
        $this->get('/inventory/requisitions?status=rejected')->assertOk()->assertInertia(fn (Assert $p) => $p->has('overview.requisitions', 1));
        $this->getJson('/inventory/requisitions?status=maybe')->assertStatus(422);

        $this->as(['housekeeping.view']);
        $this->getJson('/inventory/requisitions')->assertStatus(403);
        try {
            DB::table('inventory_requisitions')->delete();
            self::fail('A requisition is kept');
        } catch (QueryException) {
            self::assertSame(2, DB::table('inventory_requisitions')->count());
        }
    }

    public function test_the_laundry_records_the_supplies_it_uses_and_the_stock_card_follows(): void
    {
        $this->as([SupplyUseService::USE_PERMISSION]);
        $page = $this->get('/laundry/supplies')->assertOk()->viewData('page')['props']['overview'];
        self::assertSame(['DET'], array_column($page['items'], 'code'), 'only the items of the laundry are offered');

        $made = $this->postJson('/laundry/supplies', ['item_id' => $this->soap, 'location_id' => $this->main, 'unit' => 'KG', 'quantity' => '2.5', 'note' => 'Monday wash'], $this->key())->assertCreated()->json('use');
        self::assertSame(7_500, $made['balance_milli']);
        self::assertSame(7_500, $this->balance($this->soap, $this->main));
        $movement = (array) DB::table('stock_movements')->where('item_id', $this->soap)->where('kind', 'issue')->first();
        self::assertSame(['laundry', -2_500, 'Monday wash'], [$movement['reason_code'], (int) $movement['base_qty_milli'], $movement['note']]);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'laundry.supplies.used')->count());
        $this->get('/laundry/supplies')->assertInertia(fn (Assert $p) => $p->has('overview.recent', 1)->where('overview.recent.0.item_code', 'DET')->where('overview.recent.0.quantity_milli', 2_500));

        // Never below zero; only an item of the laundry; a quantity above zero.
        $this->postJson('/laundry/supplies', ['item_id' => $this->soap, 'location_id' => $this->main, 'unit' => 'KG', 'quantity' => '50'], $this->key())->assertStatus(409);
        $this->postJson('/laundry/supplies', ['item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '1'], $this->key())->assertStatus(422);
        $this->postJson('/laundry/supplies', ['item_id' => $this->soap, 'location_id' => $this->main, 'unit' => 'KG', 'quantity' => '0'], $this->key())->assertStatus(422);
        self::assertSame(7_500, $this->balance($this->soap, $this->main));

        // Someone who works the laundry sees it but cannot record; others see nothing.
        $this->as([LaundryService::PROCESS_PERMISSION]);
        $this->get('/laundry/supplies')->assertOk()->assertInertia(fn (Assert $p) => $p->where('overview.may.use', false)->has('overview.items', 0)->has('overview.recent', 1));
        $this->postJson('/laundry/supplies', ['item_id' => $this->soap, 'location_id' => $this->main, 'unit' => 'KG', 'quantity' => '1'], $this->key())->assertStatus(403);
        $this->as(['housekeeping.view']);
        $this->get('/laundry/supplies')->assertStatus(403);
    }

    /** FR-INV-004: what housekeeping uses up leaves the stock card of a store at the average cost, as housekeeping consumption. */
    public function test_housekeeping_records_the_supplies_it_uses_and_the_stock_card_follows(): void
    {
        $category = (string) DB::table('inventory_categories')->value('id');
        $this->as([InventoryCatalogService::MANAGE_PERMISSION, StockService::POST_PERMISSION, StockService::VIEW_PERMISSION]);
        $soap = $this->postJson('/inventory/items', ['code' => 'SOAP', 'name' => 'Guest soap', 'category_id' => $category, 'department' => 'housekeeping', 'base_unit' => 'PCS'])->assertCreated()->json('item.id');
        $this->postJson('/inventory/stock/opening', ['unit_cost_minor' => 3_000, 'item_id' => $soap, 'location_id' => $this->main, 'unit' => 'PCS', 'quantity' => '200'])->assertCreated();

        $this->as([HousekeepingSupplyUseService::USE_PERMISSION]);
        $page = $this->get('/housekeeping/supplies')->assertOk()->viewData('page')['props']['overview'];
        self::assertSame(['SOAP'], array_column($page['items'], 'code'), 'only the items of housekeeping are offered');

        $made = $this->postJson('/housekeeping/supplies', ['item_id' => $soap, 'location_id' => $this->main, 'unit' => 'PCS', 'quantity' => '30', 'note' => 'Floor 2'], $this->key())->assertCreated()->json('use');
        self::assertSame(170_000, $made['balance_milli']);
        $movement = (array) DB::table('stock_movements')->where('item_id', $soap)->where('kind', 'issue')->first();
        self::assertSame(['housekeeping', -30_000, 'Floor 2'], [$movement['reason_code'], (int) $movement['base_qty_milli'], $movement['note']]);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'housekeeping.supplies.used')->count());
        $this->get('/housekeeping/supplies')->assertInertia(fn (Assert $p) => $p->has('overview.recent', 1)->where('overview.recent.0.item_code', 'SOAP')->where('overview.recent.0.quantity_milli', 30_000));

        // Never below zero; only an item of housekeeping; a quantity above zero.
        $this->postJson('/housekeeping/supplies', ['item_id' => $soap, 'location_id' => $this->main, 'unit' => 'PCS', 'quantity' => '500'], $this->key())->assertStatus(409);
        $this->postJson('/housekeeping/supplies', ['item_id' => $this->soap, 'location_id' => $this->main, 'unit' => 'KG', 'quantity' => '1'], $this->key())->assertStatus(422);
        $this->postJson('/housekeeping/supplies', ['item_id' => $soap, 'location_id' => $this->main, 'unit' => 'PCS', 'quantity' => '0'], $this->key())->assertStatus(422);

        // An attendant sees it but cannot record; others see nothing.
        $this->as([HousekeepingService::PERFORM_PERMISSION]);
        $this->get('/housekeeping/supplies')->assertOk()->assertInertia(fn (Assert $p) => $p->where('overview.may.use', false)->has('overview.items', 0)->has('overview.recent', 1));
        $this->postJson('/housekeeping/supplies', ['item_id' => $soap, 'location_id' => $this->main, 'unit' => 'PCS', 'quantity' => '1'], $this->key())->assertStatus(403);
        $this->as([SupplyUseService::USE_PERMISSION]);
        $this->get('/housekeeping/supplies')->assertStatus(403);
    }

    /** FR-KIT-006: what the kitchen uses up outside of a sale leaves the stock card of a store at the average cost. */
    public function test_the_kitchen_records_the_ingredients_it_uses_outside_a_sale_and_the_stock_card_follows(): void
    {
        $category = (string) DB::table('inventory_categories')->value('id');
        $this->as([InventoryCatalogService::MANAGE_PERMISSION, StockService::POST_PERMISSION, StockService::VIEW_PERMISSION]);
        $rice = $this->postJson('/inventory/items', ['code' => 'RICE', 'name' => 'Rice', 'category_id' => $category, 'department' => 'kitchen', 'base_unit' => 'KG'])->assertCreated()->json('item.id');
        $this->postJson('/inventory/stock/opening', ['unit_cost_minor' => 15_000, 'item_id' => $rice, 'location_id' => $this->main, 'unit' => 'KG', 'quantity' => '50'])->assertCreated();

        $this->as([KitchenSupplyUseService::USE_PERMISSION]);
        $page = $this->get('/kitchen/ingredient-use')->assertOk()->viewData('page')['props']['overview'];
        self::assertSame(['RICE'], array_column($page['items'], 'code'), 'only the items of the kitchen are offered');

        $made = $this->postJson('/kitchen/ingredient-use', ['item_id' => $rice, 'location_id' => $this->main, 'unit' => 'KG', 'quantity' => '4.5', 'note' => 'Staff meal'], $this->key())->assertCreated()->json('use');
        self::assertSame(45_500, $made['balance_milli']);
        $movement = (array) DB::table('stock_movements')->where('item_id', $rice)->where('kind', 'issue')->first();
        self::assertSame(['kitchen', -4_500, 'Staff meal'], [$movement['reason_code'], (int) $movement['base_qty_milli'], $movement['note']]);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'kitchen.ingredients.used')->count());
        $this->postJson('/kitchen/ingredient-use', ['item_id' => $rice, 'location_id' => $this->main, 'unit' => 'KG', 'quantity' => '500'], $this->key())->assertStatus(409);
        $this->postJson('/kitchen/ingredient-use', ['item_id' => $this->soap, 'location_id' => $this->main, 'unit' => 'KG', 'quantity' => '1'], $this->key())->assertStatus(422);

        $this->as([KitchenAccess::BOARD_OPERATE]);
        $this->get('/kitchen/ingredient-use')->assertOk()->assertInertia(fn (Assert $p) => $p->where('overview.may.use', false)->has('overview.items', 0)->has('overview.recent', 1));
        $this->postJson('/kitchen/ingredient-use', ['item_id' => $rice, 'location_id' => $this->main, 'unit' => 'KG', 'quantity' => '1'], $this->key())->assertStatus(403);
        $this->as([SupplyUseService::USE_PERMISSION]);
        $this->get('/kitchen/ingredient-use')->assertStatus(403);
    }

    public function test_a_department_sees_the_counts_of_its_own_stores(): void
    {
        $this->as([InventoryCatalogService::MANAGE_PERMISSION, StockService::VIEW_PERMISSION, StockService::POST_PERMISSION, 'inventory.count.manage']);
        foreach ([$this->kitchen, $this->bar] as $place) {
            $this->postJson('/inventory/stock/opening', ['unit_cost_minor' => 1_000, 'item_id' => $this->item, 'location_id' => $place, 'unit' => 'BTL', 'quantity' => '5'])->assertCreated();
        }

        $this->postJson('/inventory/counts', ['location_id' => $this->kitchen, 'kind' => 'spot'], $this->key())->assertCreated();
        $this->postJson('/inventory/counts', ['location_id' => $this->bar, 'kind' => 'spot'], $this->key())->assertCreated();

        $kitchen = $this->get('/inventory/counts?location_kind=kitchen')->assertOk()->viewData('page')['props'];
        self::assertSame(['kitchen', ['KIT']], [$kitchen['locationKind'], array_column(array_column($kitchen['overview']['counts'], 'location'), 'code')]);
        self::assertSame(['KIT'], array_column($kitchen['overview']['locations'], 'code'));
        $bar = $this->get('/inventory/counts?location_kind=bar')->viewData('page')['props']['overview'];
        self::assertSame(['BAR'], array_column(array_column($bar['counts'], 'location'), 'code'));
        self::assertCount(2, $this->get('/inventory/counts')->viewData('page')['props']['overview']['counts']);
        $this->getJson('/inventory/counts?location_kind=moon')->assertStatus(422);
    }
}
