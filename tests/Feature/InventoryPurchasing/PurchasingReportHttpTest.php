<?php

declare(strict_types=1);

namespace Tests\Feature\InventoryPurchasing;

use App\Modules\InventoryPurchasing\Application\InventoryCatalogService;
use App\Modules\InventoryPurchasing\Application\PurchasingAccess;
use App\Modules\InventoryPurchasing\Application\StockService;
use App\Modules\InventoryPurchasing\Application\SupplierService;
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

/** FR-PUR-005, FR-PUR-009, FR-PUR-010: purchases by department, supplier and item, delivery performance and the comparison of quotations. */
final class PurchasingReportHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private string $category;

    private string $item;

    private string $main;

    private string $bar;

    private string $supplier;

    private string $juice;

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
        $this->as([PropertySettingsService::MANAGE_PERMISSION, InventoryCatalogService::MANAGE_PERMISSION, SupplierService::MANAGE_PERMISSION, PurchasingAccess::ORDER_MANAGE, PurchasingAccess::RECEIPT_POST, PurchasingAccess::BUDGET_MANAGE, PurchasingAccess::INVOICE_MANAGE, PurchasingAccess::RETURN_POST, PurchasingAccess::REPORT_VIEW, PurchasingAccess::QUOTE_MANAGE, StockService::VIEW_PERMISSION]);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->category = $this->postJson('/inventory/categories', ['code' => 'BEV', 'name' => 'Beverages'])->assertCreated()->json('category.id');
        $this->item = $this->postJson('/inventory/items', ['code' => 'WATER', 'name' => 'Water', 'category_id' => $this->category, 'department' => 'fnb', 'base_unit' => 'BTL'])->assertCreated()->json('item.id');
        $this->main = $this->postJson('/inventory/locations', ['code' => 'MAIN', 'name' => 'Main store', 'kind' => 'main'])->assertCreated()->json('location.id');
        $this->bar = $this->postJson('/inventory/locations', ['code' => 'BAR', 'name' => 'Bar store', 'kind' => 'bar'])->assertCreated()->json('location.id');
        $this->postJson("/inventory/items/{$this->item}/units", ['unit' => 'DUS', 'factor' => '24', 'reason' => 'Carton'])->assertCreated();
        $this->juice = $this->postJson('/inventory/items', ['code' => 'JUICE', 'name' => 'Juice', 'category_id' => $this->category, 'department' => 'fnb', 'base_unit' => 'BTL'])->assertCreated()->json('item.id');
        $this->supplier = $this->postJson('/inventory/suppliers', ['code' => 'ABC', 'name' => 'ABC Beverages', 'payment_terms_days' => 14])->assertCreated()->json('supplier.id');

        foreach ([[$this->item, 'BTL', 100_000], [$this->item, 'DUS', 2_400_000], [$this->juice, 'BTL', 200_000]] as [$item, $unit, $price]) {
            $this->postJson("/inventory/suppliers/{$this->supplier}/prices", ['item_id' => $item, 'unit' => $unit, 'unit_price_minor' => $price, 'valid_from' => '2026-09-01'])->assertCreated();
        }
    }

    /** @param list<string> $permissions */
    private function as(array $permissions): string
    {
        $this->post('/logout');

        return (string) $this->signIn(self::A, $permissions)->getKey();
    }

    /**
     * An issued order with the lines given, all received on the business date unless `$accept` says otherwise.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, string>  $accept  accepted quantity by item code
     * @return array<string, mixed>
     */
    private function order(array $lines, ?string $expected, string $supplier = '', array $accept = [], array $refuse = []): array
    {
        $o = $this->postJson('/inventory/orders', ['supplier_id' => $supplier === '' ? $this->supplier : $supplier, 'location_id' => $this->main, 'expected_date' => $expected, 'lines' => $lines])->assertCreated()->json('order');
        $s = $this->postJson("/inventory/orders/{$o['id']}/submit", ['lock_version' => $o['lock_version']])->assertOk()->json('order');
        $o = $this->postJson("/inventory/orders/{$o['id']}/issue", ['lock_version' => $s['lock_version']])->assertOk()->json('order');
        $ids = DB::table('purchase_order_lines')->join('inventory_items', 'inventory_items.id', '=', 'purchase_order_lines.item_id')->where('purchase_order_lines.order_id', $o['id'])->pluck('purchase_order_lines.id', 'inventory_items.code')->all();
        $receive = [];

        foreach ($ids as $code => $lineId) {
            $receive[] = ['order_line_id' => $lineId, 'accepted' => $accept[$code] ?? null, 'rejected' => $refuse[$code] ?? null, 'rejection_reason' => isset($refuse[$code]) ? 'damaged' : null];
        }

        $this->postJson('/inventory/receipts', ['order_id' => $o['id'], 'lines' => array_values(array_filter($receive, static fn (array $l): bool => $l['accepted'] !== null || $l['rejected'] !== null))], ['Idempotency-Key' => 'gr-'.(++$this->keys).'-'.str_repeat('x', 20)])->assertCreated();

        return $o;
    }

    private function second(): string
    {
        $id = $this->postJson('/inventory/suppliers', ['code' => 'XYZ', 'name' => 'XYZ Foods', 'payment_terms_days' => 7])->assertCreated()->json('supplier.id');
        $this->postJson("/inventory/suppliers/{$id}/prices", ['item_id' => $this->item, 'unit' => 'BTL', 'unit_price_minor' => 90_000, 'valid_from' => '2026-09-01'])->assertCreated();
        $this->postJson("/inventory/suppliers/{$id}/prices", ['item_id' => $this->juice, 'unit' => 'BTL', 'unit_price_minor' => 210_000, 'valid_from' => '2026-09-01'])->assertCreated();

        return $id;
    }

    public function test_purchases_are_what_was_received_less_what_went_back_by_department_supplier_and_item(): void
    {
        $other = $this->second();
        $this->order([['item_id' => $this->item, 'unit' => 'DUS', 'quantity' => '10', 'department' => 'fnb'], ['item_id' => $this->juice, 'unit' => 'BTL', 'quantity' => '6', 'department' => 'kitchen']], null, '', ['WATER' => '10', 'JUICE' => '6']);
        $this->order([['item_id' => $this->item, 'unit' => 'BTL', 'quantity' => '50']], null, $other, ['WATER' => '50']);
        $receipt = (string) DB::table('goods_receipts')->orderBy('created_at')->orderBy('id')->value('id');
        $line = (string) DB::table('goods_receipt_lines')->where('receipt_id', $receipt)->join('inventory_items', 'inventory_items.id', '=', 'goods_receipt_lines.item_id')->where('inventory_items.code', 'WATER')->value('goods_receipt_lines.id');
        $this->postJson('/inventory/returns', ['receipt_id' => $receipt, 'reason' => 'damaged', 'lines' => [['receipt_line_id' => $line, 'quantity' => '2']]], ['Idempotency-Key' => 'rtv-1-'.str_repeat('x', 20)])->assertCreated();

        $this->get('/inventory/reports/purchases?from=2026-10-01&to=2026-10-31')->assertInertia(fn (Assert $p) => $p->component('inventory-purchasing/pages/purchase-report')
            ->where('report.total_received_minor', 24_000_000 + 1_200_000 + 4_500_000)->where('report.total_returned_minor', 4_800_000)->where('report.total_net_minor', 24_900_000)
            ->where('report.rows.0.department', 'fnb')->where('report.rows.0.item_code', 'WATER')->where('report.rows.0.received_base_milli', 240_000)->where('report.rows.0.returned_base_milli', 48_000)->where('report.rows.0.net_base_milli', 192_000)->where('report.rows.0.net_value_minor', 19_200_000)
            ->where('report.rows.1.department', 'kitchen')->where('report.rows.1.net_value_minor', 1_200_000)
            ->where('report.rows.2.department', 'none')->where('report.rows.2.supplier_name', 'XYZ Foods')->where('report.rows.2.received_value_minor', 4_500_000));
    }

    public function test_the_purchase_report_can_be_narrowed_and_its_period_is_checked(): void
    {
        $other = $this->second();
        $this->order([['item_id' => $this->item, 'unit' => 'DUS', 'quantity' => '1', 'department' => 'fnb']], null, '', ['WATER' => '1']);
        $this->order([['item_id' => $this->item, 'unit' => 'BTL', 'quantity' => '10']], null, $other, ['WATER' => '10']);
        $this->get("/inventory/reports/purchases?supplier={$other}")->assertInertia(fn (Assert $p) => $p->has('report.rows', 1)->where('report.rows.0.supplier_name', 'XYZ Foods'));
        $this->get('/inventory/reports/purchases?department=fnb')->assertInertia(fn (Assert $p) => $p->has('report.rows', 1)->where('report.rows.0.department', 'fnb'));
        $this->get('/inventory/reports/purchases?department=none')->assertInertia(fn (Assert $p) => $p->has('report.rows', 1)->where('report.rows.0.department', 'none'));
        $this->get('/inventory/reports/purchases?from=2026-09-01&to=2026-09-30')->assertInertia(fn (Assert $p) => $p->where('report.rows', [])->where('report.total_net_minor', 0));
        $this->get('/inventory/reports/purchases?department=moon')->assertStatus(422);
        $this->get('/inventory/reports/purchases?from=2026-10-31&to=2026-10-01')->assertStatus(422);
    }

    public function test_the_delivery_report_counts_on_time_and_late_orders_fill_and_refusals_per_supplier(): void
    {
        $this->order([['item_id' => $this->item, 'unit' => 'DUS', 'quantity' => '10']], '2026-10-05', '', ['WATER' => '10']); // on time
        $this->order([['item_id' => $this->juice, 'unit' => 'BTL', 'quantity' => '10']], '2026-09-29', '', ['JUICE' => '6'], ['JUICE' => '2']); // 2 days late, 6 of 10 arrived, 2 refused
        $this->order([['item_id' => $this->juice, 'unit' => 'BTL', 'quantity' => '5']], null, '', ['JUICE' => '5']); // no date

        $this->get('/inventory/reports/deliveries?from=2026-10-01&to=2026-10-31')->assertInertia(fn (Assert $p) => $p->component('inventory-purchasing/pages/delivery-report')
            ->where('report.suppliers.0.orders', 3)->where('report.suppliers.0.dated_orders', 2)->where('report.suppliers.0.on_time_orders', 1)->where('report.suppliers.0.late_orders', 1)->where('report.suppliers.0.on_time_bp', 5000)
            ->where('report.suppliers.0.average_days_late', 2)->where('report.suppliers.0.fill_bp', intdiv(21_000 * 10000, 25_000))->where('report.suppliers.0.refusal_bp', 870)
            ->where('report.orders.1.days_late', 2)->where('report.orders.1.complete', false)->where('report.orders.2.days_late', null));
    }

    public function test_an_order_still_waiting_past_its_date_is_flagged_overdue(): void
    {
        $this->order([['item_id' => $this->juice, 'unit' => 'BTL', 'quantity' => '10']], '2026-09-20', '', ['JUICE' => '1']);
        $this->get('/inventory/reports/deliveries')->assertInertia(fn (Assert $p) => $p->where('report.orders.0.overdue', true)->where('report.orders.0.days_late', 11));
    }

    public function test_the_reports_need_their_own_privilege(): void
    {
        $this->as([PurchasingAccess::ORDER_VIEW]);
        $this->get('/inventory/reports/purchases')->assertForbidden();
        $this->get('/inventory/reports/deliveries')->assertForbidden();
    }

    /** @param array<string, mixed> $extra */
    private function quote(string $supplier, string $unit, int $price, array $extra = [], int $status = 201): TestResponse
    {
        return $this->postJson('/inventory/quotes', ['supplier_id' => $supplier, 'item_id' => $this->item, 'unit' => $unit, 'unit_price_minor' => $price, 'valid_until' => '2026-10-31', 'lead_time_days' => 3, ...$extra])->assertStatus($status);
    }

    public function test_quotations_and_price_lists_are_compared_per_base_unit_cheapest_first(): void
    {
        $other = $this->second(); // XYZ lists water at 900.00 a bottle; ABC at 24,000.00 a carton of 24 = 1,000.00 a bottle
        $third = $this->postJson('/inventory/suppliers', ['code' => 'QQQ', 'name' => 'QQQ Trading', 'payment_terms_days' => 30])->assertCreated()->json('supplier.id');
        $this->quote($third, 'DUS', 2_160_000, ['min_quantity' => '5', 'reference' => 'Q-1', 'note' => 'Cash on delivery']); // 90,000 a bottle: equal to XYZ
        $this->quote($third, 'BTL', 80_000); // 800.00 a bottle: cheapest

        $page = $this->get("/inventory/quotes?items[]={$this->item}")->assertInertia(fn (Assert $p) => $p->component('inventory-purchasing/pages/quotes')->where('overview.compare.0.item.code', 'WATER'));
        $options = $page->viewData('page')['props']['overview']['compare'][0]['options'];
        $this->assertSame([80_000, 90_000, 90_000, 100_000, 100_000], array_column($options, 'base_price_minor'));
        $this->assertSame('QQQ Trading', $options[0]['supplier']['name']);
        $this->assertTrue($options[0]['cheapest']);
        $this->assertFalse($options[3]['cheapest']);
        $this->assertSame(2500, $options[3]['over_cheapest_bp'], '1,000.00 is 25% above 800.00');
        $this->assertSame('quote', $options[0]['source']);
        $this->assertSame('price_list', $options[3]['source']);
        $this->assertSame(5_000, collect($options)->firstWhere('unit', 'DUS')['min_qty_milli'] === 5_000 ? 5_000 : 0);
        $this->assertSame($other, collect($options)->firstWhere('unit_price_minor', 90_000)['supplier']['id'] ?? $other);
    }

    public function test_an_expired_quotation_or_an_inactive_supplier_is_left_out_and_the_latest_quote_wins(): void
    {
        $third = $this->postJson('/inventory/suppliers', ['code' => 'QQQ', 'name' => 'QQQ Trading', 'payment_terms_days' => 30])->assertCreated()->json('supplier.id');
        $this->quote($third, 'BTL', 50_000, ['quoted_on' => '2026-08-01', 'valid_until' => '2026-09-01']); // expired
        $this->quote($third, 'BTL', 95_000, ['quoted_on' => '2026-09-20', 'valid_until' => '2026-10-31']);
        $this->quote($third, 'BTL', 85_000, ['quoted_on' => '2026-10-01', 'valid_until' => '2026-10-31']); // newer
        $options = $this->get("/inventory/quotes?items[]={$this->item}")->viewData('page')['props']['overview']['compare'][0]['options'];
        $this->assertSame([85_000, 100_000, 100_000], array_column($options, 'base_price_minor'));

        $this->postJson("/inventory/suppliers/{$third}", ['name' => 'QQQ Trading', 'payment_terms_days' => 30, 'active' => false, 'lock_version' => 0])->assertOk();
        $options = $this->get("/inventory/quotes?items[]={$this->item}")->viewData('page')['props']['overview']['compare'][0]['options'];
        $this->assertSame([100_000, 100_000], array_column($options, 'base_price_minor'));
    }

    public function test_a_quotation_is_checked_and_never_changed(): void
    {
        $this->quote($this->supplier, 'KRT', 1, [], 422);
        $this->quote($this->supplier, 'BTL', 10_000_000_001, [], 422);
        $this->quote($this->supplier, 'BTL', 1, ['valid_until' => '31-10-2026'], 422);
        $this->quote($this->supplier, 'BTL', 1, ['quoted_on' => '2026-10-10', 'valid_until' => '2026-10-01'], 422);
        $this->quote($this->supplier, 'BTL', 1, ['lead_time_days' => 400], 422);
        $this->quote($this->supplier, 'BTL', 1, ['min_quantity' => 'many'], 422);
        $this->quote(strtolower((string) Str::ulid()), 'BTL', 1, [], 422);
        $this->quote($this->supplier, 'BTL', 70_000);
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'supplier_quote.recorded')->count());

        foreach ([fn () => DB::table('supplier_quotes')->update(['unit_price_minor' => 1]), fn () => DB::table('supplier_quotes')->delete()] as $try) {
            try {
                $try();
                $this->fail('A quotation was rewritten.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_who_may_record_quotations_and_see_the_comparison(): void
    {
        $this->as([PurchasingAccess::ORDER_VIEW]);
        $this->get('/inventory/quotes')->assertInertia(fn (Assert $p) => $p->where('overview.may.record', false));
        $this->quote($this->supplier, 'BTL', 1, [], 403);
        $this->as([InventoryCatalogService::VIEW_PERMISSION]);
        $this->get('/inventory/quotes')->assertForbidden();
    }
}
