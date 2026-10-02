<?php

declare(strict_types=1);

namespace Tests\Feature\InventoryPurchasing;

use App\Modules\InventoryPurchasing\Application\InventoryCatalogService;
use App\Modules\InventoryPurchasing\Application\StockMovementService;
use App\Modules\InventoryPurchasing\Application\StockPoster;
use App\Modules\InventoryPurchasing\Application\StockService;
use App\Modules\InventoryPurchasing\Application\StockTransferService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-INV-007: stock is valued by the moving average; outflows are taken out at the average; transfers keep the total; the report is a sum up to a date. */
final class StockValuationHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private string $category;

    private string $item;

    private string $main;

    private string $bar;

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
        $this->as([PropertySettingsService::MANAGE_PERMISSION, InventoryCatalogService::MANAGE_PERMISSION, StockService::POST_PERMISSION, StockService::VIEW_PERMISSION, StockService::VALUATION_PERMISSION, StockMovementService::ADJUST_PERMISSION, StockTransferService::SEND_PERMISSION]);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->category = $this->postJson('/inventory/categories', ['code' => 'BEV', 'name' => 'Beverages'])->assertCreated()->json('category.id');
        $this->item = $this->postJson('/inventory/items', ['code' => 'WATER', 'name' => 'Water', 'category_id' => $this->category, 'department' => 'fnb', 'base_unit' => 'BTL'])->assertCreated()->json('item.id');
        $this->main = $this->postJson('/inventory/locations', ['code' => 'MAIN', 'name' => 'Main store', 'kind' => 'main'])->assertCreated()->json('location.id');
        $this->bar = $this->postJson('/inventory/locations', ['code' => 'BAR', 'name' => 'Bar store', 'kind' => 'bar'])->assertCreated()->json('location.id');
        $this->postJson("/inventory/items/{$this->item}/units", ['unit' => 'DUS', 'factor' => '24', 'reason' => 'Carton'])->assertCreated();
        $this->postJson('/inventory/stock/opening', ['unit_cost_minor' => 1_000, 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '100'])->assertCreated();
    }

    /** @param list<string> $permissions */
    private function as(array $permissions): string
    {
        $this->post('/logout');

        return (string) $this->signIn(self::A, $permissions)->getKey();
    }

    /** @param array<string, mixed> $body */
    private function move(array $body, int $expect = 201): TestResponse
    {
        return $this->postJson('/inventory/stock/movements', $body, ['Idempotency-Key' => 'mv-'.(++$this->keys).'-'.str_repeat('x', 20)])->assertStatus($expect);
    }

    private function balance(string $location): int
    {
        return (int) DB::table('stock_movements')->where('item_id', $this->item)->where('location_id', $location)->sum('base_qty_milli');
    }

    private function value(?string $location = null): int
    {
        return (int) DB::table('stock_movements')->where('item_id', $this->item)->when($location !== null, fn ($q) => $q->where('location_id', $location))->sum('value_minor');
    }

    public function test_an_inflow_carries_its_cost_and_the_value_of_a_posting_is_quantity_times_cost(): void
    {
        $this->assertSame(100_000, $this->value($this->main)); // 100 BTL at 1,000
        $this->move(['kind' => 'receipt', 'unit_cost_minor' => 30_000, 'item_id' => $this->item, 'location_id' => $this->bar, 'unit' => 'DUS', 'quantity' => '2.5'])
            ->assertJsonPath('movement.value_minor', 75_000)->assertJsonPath('movement.base_qty_milli', 60_000);
        $this->assertSame(75_000, $this->value($this->bar));
        $this->assertSame(30_000, (int) DB::table('stock_movements')->where('kind', 'receipt')->value('unit_cost_minor'));
    }

    public function test_an_opening_and_a_receipt_need_a_cost(): void
    {
        $this->postJson('/inventory/stock/opening', ['item_id' => $this->item, 'location_id' => $this->bar, 'unit' => 'BTL', 'quantity' => '5'])->assertStatus(422);
        $this->move(['kind' => 'receipt', 'item_id' => $this->item, 'location_id' => $this->bar, 'unit' => 'BTL', 'quantity' => '5'], 422)->assertJsonStructure(['error' => ['fields' => ['unit_cost_minor']]]);
        $this->move(['kind' => 'receipt', 'unit_cost_minor' => 10_000_000_001, 'item_id' => $this->item, 'location_id' => $this->bar, 'unit' => 'BTL', 'quantity' => '5'], 422);
        $this->assertSame(0, $this->balance($this->bar));
    }

    public function test_an_outflow_is_taken_out_at_the_moving_average_and_the_last_one_empties_the_value(): void
    {
        $this->move(['kind' => 'receipt', 'unit_cost_minor' => 2_000, 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '100']); // 200 BTL, 300,000: average 1,500
        $this->move(['kind' => 'issue', 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '50', 'reason_code' => 'fnb'])->assertJsonPath('movement.value_minor', -75_000);
        $this->assertSame(225_000, $this->value());
        $this->move(['kind' => 'write_off', 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '1', 'reason_code' => 'damaged'])->assertJsonPath('movement.value_minor', -1_500);
        $this->move(['kind' => 'issue', 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '149', 'reason_code' => 'fnb'])->assertJsonPath('movement.value_minor', -223_500);
        $this->assertSame(0, $this->balance($this->main));
        $this->assertSame(0, $this->value(), 'the last unit takes the whole remaining value, so no rounding is left behind');
    }

    public function test_stock_that_goes_negative_is_valued_too_and_a_correction_restores_the_value(): void
    {
        $this->as([StockService::POST_PERMISSION, StockService::VIEW_PERMISSION, StockService::VALUATION_PERMISSION, StockMovementService::ADJUST_PERMISSION, StockPoster::NEGATIVE_PERMISSION]);
        $this->move(['kind' => 'issue', 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '130', 'reason_code' => 'fnb', 'negative_reason' => 'Event, count tomorrow'])->assertJsonPath('movement.value_minor', -130_000);
        $this->assertSame(-30_000, $this->balance($this->main));
        $this->assertSame(-30_000, $this->value(), 'the 30 bottles that were not there are valued at the same average');
        $this->move(['kind' => 'adjustment_in', 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '30', 'reason_code' => 'count_correction'])->assertJsonPath('movement.value_minor', 30_000);
        $this->assertSame(0, $this->balance($this->main));
        $this->assertSame(0, $this->value());
    }

    public function test_the_average_rounds_half_up_and_never_leaves_a_remainder(): void
    {
        $this->move(['kind' => 'receipt', 'unit_cost_minor' => 1_001, 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '2']); // 102 BTL, 102,002
        $first = $this->move(['kind' => 'issue', 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '1', 'reason_code' => 'fnb'])->json('movement.value_minor');
        $this->assertSame(-1_000, $first); // 102,002 / 102 = 1,000.02
        $this->assertSame(101_002, $this->value());
    }

    public function test_a_transfer_keeps_the_value_it_left_with_and_the_total_does_not_change(): void
    {
        $this->move(['kind' => 'receipt', 'unit_cost_minor' => 2_000, 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '100']);
        $before = $this->value();
        $id = $this->postJson('/inventory/transfers', ['from_location_id' => $this->main, 'to_location_id' => $this->bar, 'lines' => [['item_id' => $this->item, 'unit' => 'DUS', 'quantity' => '2']]], ['Idempotency-Key' => 'trf-0001-'.str_repeat('x', 20)])->assertCreated()->json('transfer.id');
        $this->as([StockTransferService::RECEIVE_PERMISSION]);
        $this->postJson("/inventory/transfers/{$id}/receive", ['lock_version' => 0])->assertOk();
        $this->assertSame(72_000, $this->value($this->bar)); // 48 BTL at the average 1,500
        $this->assertSame(228_000, $this->value($this->main));
        $this->assertSame($before, $this->value());
    }

    public function test_an_adjustment_in_uses_the_average_unless_given_a_cost_and_needs_one_when_there_is_none(): void
    {
        $this->move(['kind' => 'adjustment_in', 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '10', 'reason_code' => 'found'])->assertJsonPath('movement.value_minor', 10_000);
        $this->move(['kind' => 'adjustment_in', 'unit_cost_minor' => 5_000, 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '1', 'reason_code' => 'found'])->assertJsonPath('movement.value_minor', 5_000);

        $other = $this->postJson('/inventory/items', ['code' => 'JUICE', 'name' => 'Juice', 'category_id' => $this->category, 'department' => 'fnb', 'base_unit' => 'BTL'])->assertCreated()->json('item.id');
        $this->move(['kind' => 'adjustment_in', 'item_id' => $other, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '3', 'reason_code' => 'found'], 422)->assertJsonStructure(['error' => ['fields' => ['unit_cost_minor']]]);
    }

    public function test_the_ledger_refuses_a_value_whose_sign_does_not_match_the_quantity(): void
    {
        $row = ['id' => strtolower((string) Str::ulid()), 'property_id' => self::A, 'item_id' => $this->item, 'location_id' => $this->main, 'kind' => 'receipt', 'unit' => 'BTL', 'unit_qty_milli' => 1_000, 'factor_milli' => 1_000, 'base_qty_milli' => 1_000, 'business_date' => '2026-10-01', 'posted_by' => $this->as([StockService::POST_PERMISSION]), 'created_at' => now()];
        $this->expectException(QueryException::class);
        DB::table('stock_movements')->insert([...$row, 'value_minor' => -1]);
    }

    public function test_the_valuation_report_is_a_sum_up_to_the_date_and_needs_its_own_privilege(): void
    {
        $this->move(['kind' => 'receipt', 'unit_cost_minor' => 24_000, 'item_id' => $this->item, 'location_id' => $this->bar, 'unit' => 'DUS', 'quantity' => '1']);
        $this->get('/inventory/valuation?as_of=2026-10-01')->assertInertia(fn (Assert $p) => $p->component('inventory-purchasing/pages/valuation')->where('report.as_of', '2026-10-01')->where('report.total_value_minor', 124_000)
            ->where('report.rows.0.item_code', 'WATER')->where('report.rows.0.location_code', 'BAR')->where('report.rows.0.qty_milli', 24_000)->where('report.rows.0.value_minor', 24_000)->where('report.rows.0.average_cost_minor', 1_000)
            ->where('report.rows.1.location_code', 'MAIN')->where('report.rows.1.value_minor', 100_000)->where('report.currency', 'IDR'));
        $this->get('/inventory/valuation?as_of=2026-09-30')->assertInertia(fn (Assert $p) => $p->where('report.rows', [])->where('report.total_value_minor', 0));
        $this->get('/inventory/valuation?as_of=30-09-2026')->assertStatus(302);

        $this->as([StockService::VIEW_PERMISSION]);
        $this->get('/inventory/valuation')->assertForbidden();
        $this->get('/inventory/stock')->assertInertia(fn (Assert $p) => $p->where('position.may.valuation', false)->where('position.rows.0.value_minor', null)->where('movements.0.value_minor', null));
        $this->as([StockService::VIEW_PERMISSION, StockService::VALUATION_PERMISSION]);
        $this->get('/inventory/stock')->assertInertia(fn (Assert $p) => $p->where('position.may.valuation', true)->where('position.rows.0.value_minor', 24_000)->where('movements.0.value_minor', 24_000));
    }
}
