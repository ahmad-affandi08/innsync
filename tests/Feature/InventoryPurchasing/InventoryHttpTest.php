<?php

declare(strict_types=1);

namespace Tests\Feature\InventoryPurchasing;

use App\Modules\InventoryPurchasing\Application\InventoryCatalogService;
use App\Modules\InventoryPurchasing\Application\StockService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Modules\Reporting\Application\DashboardService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-INV-001, -002, -003, -009: item master data, locations, minimum and maximum stock, versioned unit conversions and the opening-stock ledger. */
final class InventoryHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private string $category;

    private string $item;

    private string $main;

    private string $bar;

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
        $this->signIn(self::A, [PropertySettingsService::MANAGE_PERMISSION, InventoryCatalogService::MANAGE_PERMISSION, StockService::POST_PERMISSION]);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->category = $this->postJson('/inventory/categories', ['code' => 'bev', 'name' => 'Beverages'])->assertCreated()->json('category.id');
        $this->item = $this->postJson('/inventory/items', ['code' => 'water-600', 'name' => 'Mineral water 600 ml', 'category_id' => $this->category, 'department' => 'fnb', 'base_unit' => 'btl'])->assertCreated()->json('item.id');
        $this->main = $this->postJson('/inventory/locations', ['code' => 'main', 'name' => 'Main store', 'kind' => 'main'])->assertCreated()->json('location.id');
        $this->bar = $this->postJson('/inventory/locations', ['code' => 'bar', 'name' => 'Bar store', 'kind' => 'bar'])->assertCreated()->json('location.id');
    }

    /** @param list<string> $permissions */
    private function as(array $permissions): void
    {
        $this->post('/logout');
        $this->signIn(self::A, $permissions);
    }

    public function test_codes_are_upper_cased_and_unique_and_the_base_unit_is_fixed(): void
    {
        $this->postJson('/inventory/categories', ['code' => 'BEV', 'name' => 'Again'])->assertStatus(409);
        $this->postJson('/inventory/items', ['code' => 'WATER-600', 'name' => 'Dup', 'category_id' => $this->category, 'department' => 'fnb', 'base_unit' => 'BTL'])->assertStatus(409);
        $this->postJson('/inventory/items', ['code' => 'X 1', 'name' => 'Bad code', 'category_id' => $this->category, 'department' => 'fnb', 'base_unit' => 'BTL'])->assertStatus(422);
        $this->postJson('/inventory/items', ['code' => 'X1', 'name' => 'Bad dept', 'category_id' => $this->category, 'department' => 'nonsense', 'base_unit' => 'BTL'])->assertStatus(422);
        $this->assertSame('WATER-600', DB::table('inventory_items')->where('id', $this->item)->value('code'));
        $this->assertSame('BTL', DB::table('inventory_items')->where('id', $this->item)->value('base_unit'));

        $this->expectException(QueryException::class);
        DB::table('inventory_items')->where('id', $this->item)->update(['base_unit' => 'KG']);
    }

    public function test_a_conversion_is_versioned_and_never_edited(): void
    {
        $this->postJson("/inventory/items/{$this->item}/units", ['unit' => 'dus', 'factor' => '24', 'reason' => 'Supplier carton'])->assertCreated()
            ->assertJsonPath('conversion.version', 1)->assertJsonPath('conversion.factor_milli', 24_000);
        $this->postJson("/inventory/items/{$this->item}/units", ['unit' => 'DUS', 'factor' => '24', 'reason' => 'Same'])->assertStatus(409);
        $this->postJson("/inventory/items/{$this->item}/units", ['unit' => 'DUS', 'factor' => '20', 'reason' => 'New supplier carton'])->assertCreated()->assertJsonPath('conversion.version', 2);
        $this->postJson("/inventory/items/{$this->item}/units", ['unit' => 'BTL', 'factor' => '1', 'reason' => 'x'])->assertStatus(422);
        $this->postJson("/inventory/items/{$this->item}/units", ['unit' => 'DUS', 'factor' => '0', 'reason' => 'x'])->assertStatus(422);
        $this->postJson("/inventory/items/{$this->item}/units", ['unit' => 'DUS', 'factor' => '20.5', 'reason' => ''])->assertStatus(422);

        $this->assertSame(2, DB::table('inventory_item_units')->where('item_id', $this->item)->count());
        $this->get('/inventory/items')->assertInertia(fn (Assert $p) => $p->where('catalog.items.0.units.0.factor_milli', 20_000)->where('catalog.items.0.units.0.version', 2)->has('catalog.items.0.units.0.history', 2));
        $this->assertSame(2, DB::table('audit_entries')->where('action', 'inventory_item.conversion_set')->count());

        $this->expectException(QueryException::class);
        DB::table('inventory_item_units')->where('item_id', $this->item)->update(['factor_milli' => 1]);
    }

    public function test_opening_stock_keeps_the_unit_the_factor_and_the_base_equivalent(): void
    {
        $this->postJson("/inventory/items/{$this->item}/units", ['unit' => 'DUS', 'factor' => '24', 'reason' => 'Carton'])->assertCreated();
        $this->postJson('/inventory/stock/opening', ['item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'dus', 'quantity' => '2', 'reference' => 'Count 1 Oct'])->assertCreated()
            ->assertJsonPath('movement.unit_qty_milli', 2_000)->assertJsonPath('movement.factor_milli', 24_000)->assertJsonPath('movement.base_qty_milli', 48_000)->assertJsonPath('movement.balance_milli', 48_000);

        // A later factor does not touch what was posted; the next posting uses the new version.
        $this->postJson("/inventory/items/{$this->item}/units", ['unit' => 'DUS', 'factor' => '20', 'reason' => 'New carton'])->assertCreated();
        $this->postJson('/inventory/stock/opening', ['item_id' => $this->item, 'location_id' => $this->bar, 'unit' => 'DUS', 'quantity' => '1.5'])->assertCreated()->assertJsonPath('movement.base_qty_milli', 30_000)->assertJsonPath('movement.factor_milli', 20_000);
        $this->assertEqualsCanonicalizing([24_000, 20_000], DB::table('stock_movements')->pluck('factor_milli')->map(fn ($v) => (int) $v)->all());
        $this->assertSame('2026-10-01', (string) DB::table('stock_movements')->value('business_date'));
        $this->assertSame(2, DB::table('audit_entries')->where('action', 'stock.opening_posted')->count());
        $this->assertSame(2, DB::table('outbox_messages')->where('event_type', 'inventory.stock.moved')->count());
    }

    public function test_opening_stock_is_posted_once_and_with_a_known_unit(): void
    {
        $this->postJson('/inventory/stock/opening', ['item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '10'])->assertCreated();
        $this->postJson('/inventory/stock/opening', ['item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '10'])->assertStatus(409);
        $this->postJson('/inventory/stock/opening', ['item_id' => $this->item, 'location_id' => $this->bar, 'unit' => 'DUS', 'quantity' => '1'])->assertStatus(422);
        $this->postJson('/inventory/stock/opening', ['item_id' => $this->item, 'location_id' => $this->bar, 'unit' => 'BTL', 'quantity' => '0'])->assertStatus(422);
        $this->postJson('/inventory/stock/opening', ['item_id' => $this->item, 'location_id' => $this->bar, 'unit' => 'BTL', 'quantity' => '1.2345'])->assertStatus(422);
        $this->assertSame(1, DB::table('stock_movements')->count());

        $this->expectException(QueryException::class);
        DB::table('stock_movements')->update(['note' => 'changed']);
    }

    public function test_a_movement_cannot_be_deleted(): void
    {
        $this->postJson('/inventory/stock/opening', ['item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '10'])->assertCreated();

        $this->expectException(QueryException::class);
        DB::table('stock_movements')->delete();
    }

    public function test_minimum_and_maximum_stock_flag_the_position(): void
    {
        $this->postJson('/inventory/stock-limits', ['item_id' => $this->item, 'location_id' => $this->main, 'min' => '12', 'max' => '40'])->assertOk()->assertJsonPath('limits.min_milli', 12_000);
        $this->postJson('/inventory/stock-limits', ['item_id' => $this->item, 'location_id' => $this->bar, 'min' => '5', 'max' => '4'])->assertStatus(422);
        $this->postJson('/inventory/stock-limits', ['item_id' => $this->item, 'location_id' => $this->main, 'min' => '10'])->assertStatus(409);
        $this->postJson('/inventory/stock/opening', ['item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '8'])->assertCreated();

        $this->get('/inventory/stock')->assertInertia(fn (Assert $p) => $p->component('inventory-purchasing/pages/stock')->where('position.below_minimum', 1)->where('position.rows.0.status', 'below_minimum')->where('position.rows.0.balance_milli', 8_000)->where('position.rows.0.min_milli', 12_000));
        $this->postJson('/inventory/stock-limits', ['item_id' => $this->item, 'location_id' => $this->main, 'min' => '5', 'max' => '7', 'lock_version' => 0])->assertOk();
        $this->get('/inventory/stock')->assertInertia(fn (Assert $p) => $p->where('position.below_minimum', 0)->where('position.rows.0.status', 'above_maximum'));
        $this->postJson('/inventory/stock-limits', ['item_id' => $this->item, 'location_id' => $this->main, 'min' => '5', 'lock_version' => 0])->assertStatus(409);
    }

    public function test_stock_below_its_minimum_shows_on_the_dashboard(): void
    {
        $this->postJson('/inventory/stock-limits', ['item_id' => $this->item, 'location_id' => $this->main, 'min' => '12'])->assertOk();
        $this->as([DashboardService::VIEW_PERMISSION]);
        $this->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('snapshot.alerts.0.code', 'stock_below_minimum')->where('snapshot.alerts.0.count', 1)->where('snapshot.alerts.0.items.0', 'WATER-600 · MAIN')->where('snapshot.alerts.0.href', '/inventory/stock'));

        $this->as([InventoryCatalogService::MANAGE_PERMISSION, StockService::POST_PERMISSION]);
        $this->postJson('/inventory/stock/opening', ['item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '12'])->assertCreated();
        $this->as([DashboardService::VIEW_PERMISSION]);
        $this->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('snapshot.alerts', []));
    }

    public function test_who_may_see_and_who_may_change(): void
    {
        $this->as([StockService::VIEW_PERMISSION]);
        $this->get('/inventory/stock')->assertOk();
        $this->get('/inventory/items')->assertOk();
        $this->postJson('/inventory/items', ['code' => 'N1', 'name' => 'No', 'category_id' => $this->category, 'department' => 'fnb', 'base_unit' => 'BTL'])->assertForbidden();
        $this->postJson('/inventory/stock/opening', ['item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '1'])->assertForbidden();
        $this->postJson('/inventory/stock-limits', ['item_id' => $this->item, 'location_id' => $this->main, 'min' => '1'])->assertForbidden();

        $this->as([]);
        $this->get('/inventory/stock')->assertForbidden();
        $this->get('/inventory/items')->assertForbidden();

        $this->as([InventoryCatalogService::VIEW_PERMISSION]);
        $this->get('/inventory/items')->assertOk();
        $this->get('/inventory/stock')->assertForbidden();
    }

    public function test_the_catalog_is_scoped_to_the_property(): void
    {
        $other = '01arz3ndektsv4rrffq69g5fb0';
        $this->createProperty($other, 'B');
        $this->post('/logout');
        $this->signIn($other, [InventoryCatalogService::MANAGE_PERMISSION, StockService::POST_PERMISSION]);
        $this->get('/inventory/items')->assertInertia(fn (Assert $p) => $p->has('catalog.items', 0));
        $this->postJson('/inventory/stock/opening', ['item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '1'])->assertNotFound();
        $this->postJson('/inventory/items/'.$this->item, ['name' => 'Hijack', 'category_id' => $this->category, 'department' => 'fnb', 'active' => true, 'lock_version' => 0])->assertNotFound();
    }

    public function test_an_item_a_location_and_a_category_can_be_changed_and_stock_is_then_refused_to_an_inactive_item(): void
    {
        $this->postJson("/inventory/items/{$this->item}", ['name' => 'Mineral water', 'category_id' => $this->category, 'department' => 'fnb', 'active' => false, 'lock_version' => 0])->assertOk()->assertJsonPath('item.is_active', false);
        $this->postJson("/inventory/items/{$this->item}", ['name' => 'Stale', 'category_id' => $this->category, 'department' => 'fnb', 'active' => true, 'lock_version' => 0])->assertStatus(409);
        $this->postJson('/inventory/stock/opening', ['item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '1'])->assertStatus(409);
        $this->postJson("/inventory/locations/{$this->bar}", ['name' => 'Bar', 'kind' => 'bar', 'active' => false, 'lock_version' => 0])->assertOk();
        $this->postJson("/inventory/categories/{$this->category}", ['name' => 'Drinks', 'active' => true, 'lock_version' => 0])->assertOk()->assertJsonPath('category.name', 'Drinks');
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'inventory_location.updated')->count());
    }
}
