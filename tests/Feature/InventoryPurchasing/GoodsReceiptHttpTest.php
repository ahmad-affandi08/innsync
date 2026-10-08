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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-PUR-006, FR-PUR-008: goods received against an order, partial deliveries, refused goods, stock at the order price and the supplier payable fact. */
final class GoodsReceiptHttpTest extends TestCase
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
        $this->as([PropertySettingsService::MANAGE_PERMISSION, InventoryCatalogService::MANAGE_PERMISSION, SupplierService::MANAGE_PERMISSION, PurchasingAccess::ORDER_MANAGE, PurchasingAccess::RECEIPT_POST, PurchasingAccess::BUDGET_MANAGE, StockService::VIEW_PERMISSION, StockService::VALUATION_PERMISSION]);
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

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** @return array<string, mixed> an issued order of 10 cartons of water and 6 bottles of juice */
    private function issued(): array
    {
        $o = $this->postJson('/inventory/orders', ['supplier_id' => $this->supplier, 'location_id' => $this->main, 'lines' => [['item_id' => $this->item, 'unit' => 'DUS', 'quantity' => '10'], ['item_id' => $this->juice, 'unit' => 'BTL', 'quantity' => '6']]])->assertCreated()->json('order');
        $s = $this->postJson("/inventory/orders/{$o['id']}/submit", ['lock_version' => $o['lock_version']])->assertOk()->json('order');

        return $this->postJson("/inventory/orders/{$o['id']}/issue", ['lock_version' => $s['lock_version']])->assertOk()->json('order');
    }

    /** @return array<string, string> order line ids by item code */
    private function lineIds(): array
    {
        return DB::table('purchase_order_lines')->join('inventory_items', 'inventory_items.id', '=', 'purchase_order_lines.item_id')->pluck('purchase_order_lines.id', 'inventory_items.code')->all();
    }

    /** @param array<string, mixed> $extra */
    private function receive(string $orderId, array $lines, array $extra = [], int $status = 201): TestResponse
    {
        return $this->postJson('/inventory/receipts', ['order_id' => $orderId, 'delivery_note' => 'SJ-001', 'lines' => $lines, ...$extra], ['Idempotency-Key' => 'gr-'.(++$this->keys).'-'.str_repeat('x', 20)])->assertStatus($status);
    }

    private function stock(string $item, string $location): int
    {
        return (int) DB::table('stock_movements')->where('item_id', $item)->where('location_id', $location)->sum('base_qty_milli');
    }

    public function test_a_full_delivery_adds_stock_at_the_order_price_completes_the_order_and_records_the_payable(): void
    {
        $o = $this->issued();
        $ids = $this->lineIds();
        $r = $this->receive($o['id'], [['order_line_id' => $ids['WATER'], 'accepted' => '10'], ['order_line_id' => $ids['JUICE'], 'accepted' => '6']])->json('receipt');
        $this->assertMatchesRegularExpression('/^GR-\d{6}$/', $r['number']);
        $this->assertSame(24_000_000 + 1_200_000, $r['value_minor']);
        $this->assertSame(240_000, $this->stock($this->item, $this->main), '10 cartons of 24 bottles');
        $this->assertSame(6_000, $this->stock($this->juice, $this->main));
        $this->assertSame(24_000_000, (int) DB::table('stock_movements')->where('item_id', $this->item)->sum('value_minor'), 'stock is valued at the price of the order line, before tax');
        $this->assertSame('received', DB::table('purchase_orders')->value('status'));
        $this->assertSame(10_000, (int) DB::table('purchase_order_lines')->where('id', $ids['WATER'])->value('received_qty_milli'));
        $this->assertSame(25_200_000, (int) DB::table('supplier_ledger_entries')->where('kind', 'goods_received')->sum('amount_minor'));
        $this->assertSame('goods_received', DB::table('supplier_ledger_entries')->value('kind'));
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'goods_receipt.posted')->count());
        $this->assertSame('receipt', DB::table('stock_movements')->where('source_type', 'goods_receipt')->value('kind'));
        $this->get("/inventory/suppliers/{$this->supplier}")->assertInertia(fn (Assert $p) => $p->where('supplier.payable_minor', 25_200_000)->where('supplier.ledger.0.ref_number', $r['number']));
    }

    public function test_a_partial_delivery_keeps_the_order_open_and_the_rest_can_follow(): void
    {
        $o = $this->issued();
        $ids = $this->lineIds();
        $this->receive($o['id'], [['order_line_id' => $ids['WATER'], 'accepted' => '4']]);
        $this->assertSame('partially_received', DB::table('purchase_orders')->value('status'));
        $this->receive($o['id'], [['order_line_id' => $ids['WATER'], 'accepted' => '6'], ['order_line_id' => $ids['JUICE'], 'accepted' => '5']]);
        $this->assertSame('partially_received', DB::table('purchase_orders')->value('status'));
        $this->receive($o['id'], [['order_line_id' => $ids['JUICE'], 'accepted' => '1']]);
        $this->assertSame('received', DB::table('purchase_orders')->value('status'));
        $this->assertSame(3, DB::table('goods_receipts')->count());
        $this->receive($o['id'], [['order_line_id' => $ids['JUICE'], 'accepted' => '1']], [], 409); // the order is complete
    }

    public function test_refused_goods_never_enter_stock_or_the_payable_and_need_a_reason(): void
    {
        $o = $this->issued();
        $ids = $this->lineIds();
        $this->receive($o['id'], [['order_line_id' => $ids['WATER'], 'accepted' => '8', 'rejected' => '2']], [], 422); // refused without a reason
        $this->receive($o['id'], [['order_line_id' => $ids['WATER'], 'accepted' => '0', 'rejected' => '2', 'rejection_reason' => 'other']], [], 422); // "other" needs a note
        $r = $this->receive($o['id'], [['order_line_id' => $ids['WATER'], 'accepted' => '8', 'rejected' => '2', 'rejection_reason' => 'damaged', 'condition' => 'minor_damage', 'note' => 'Crushed cartons']])->json('receipt');
        $this->assertSame(192_000, $this->stock($this->item, $this->main));
        $this->assertSame(19_200_000, $r['value_minor']);
        $this->assertSame(2_000, (int) DB::table('purchase_order_lines')->where('id', $ids['WATER'])->value('rejected_qty_milli'));
        $this->assertSame(8_000, (int) DB::table('purchase_order_lines')->where('id', $ids['WATER'])->value('received_qty_milli'));
        $this->assertSame('partially_received', DB::table('purchase_orders')->value('status'), 'what was refused still has to be delivered');
        $this->get("/inventory/receipts/{$r['id']}")->assertInertia(fn (Assert $p) => $p->where('receipt.lines.0.rejected_qty_milli', 2_000)->where('receipt.lines.0.rejection_reason', 'damaged')->where('receipt.lines.0.condition', 'minor_damage'));
    }

    public function test_more_than_ordered_is_accepted_only_within_the_tolerance(): void
    {
        $o = $this->issued();
        $ids = $this->lineIds();
        $this->receive($o['id'], [['order_line_id' => $ids['JUICE'], 'accepted' => '6.7']], [], 409); // 10% of 6 is 0.6
        $this->receive($o['id'], [['order_line_id' => $ids['JUICE'], 'accepted' => '6.6']]);
        $this->assertSame(6_600, $this->stock($this->juice, $this->main));
    }

    public function test_a_receipt_needs_an_issued_order_a_known_line_once_and_a_quantity(): void
    {
        $draft = $this->postJson('/inventory/orders', ['supplier_id' => $this->supplier, 'location_id' => $this->main, 'lines' => [['item_id' => $this->item, 'unit' => 'DUS', 'quantity' => '1']]])->assertCreated()->json('order');
        $line = (string) DB::table('purchase_order_lines')->value('id');
        $this->receive($draft['id'], [['order_line_id' => $line, 'accepted' => '1']], [], 409);

        $o = $this->issued();
        $ids = $this->lineIds();
        $this->receive($o['id'], [['order_line_id' => $line, 'accepted' => '1']], [], 422); // a line of another order
        $this->receive($o['id'], [['order_line_id' => $ids['WATER'], 'accepted' => '1'], ['order_line_id' => $ids['WATER'], 'accepted' => '1']], [], 422);
        $this->receive($o['id'], [['order_line_id' => $ids['WATER'], 'accepted' => '0']], [], 422);
        $this->receive($o['id'], [['order_line_id' => $ids['WATER'], 'accepted' => 'a lot']], [], 422);
        $this->receive($o['id'], [['order_line_id' => $ids['WATER'], 'accepted' => '1', 'condition' => 'perfect']], [], 422);
        $this->receive($o['id'], [['order_line_id' => $ids['WATER'], 'accepted' => '1']], ['location_id' => strtolower((string) Str::ulid())], 422);
        $this->assertSame(0, DB::table('goods_receipts')->count());
        $this->assertSame(0, DB::table('stock_movements')->where('source_type', 'goods_receipt')->count());
    }

    public function test_goods_can_go_to_another_location_than_the_order_names(): void
    {
        $o = $this->issued();
        $this->receive($o['id'], [['order_line_id' => $this->lineIds()['JUICE'], 'accepted' => '2']], ['location_id' => $this->bar]);
        $this->assertSame(2_000, $this->stock($this->juice, $this->bar));
        $this->assertSame(0, $this->stock($this->juice, $this->main));
    }

    public function test_the_same_request_with_the_same_key_receives_once(): void
    {
        $o = $this->issued();
        $body = ['order_id' => $o['id'], 'lines' => [['order_line_id' => $this->lineIds()['JUICE'], 'accepted' => '2']]];
        $headers = ['Idempotency-Key' => 'gr-once-'.str_repeat('x', 20)];
        $first = $this->postJson('/inventory/receipts', $body, $headers)->assertCreated()->json('receipt.id');
        $this->assertSame($first, $this->postJson('/inventory/receipts', $body, $headers)->json('receipt.id'));
        $this->assertSame(1, DB::table('goods_receipts')->count());
        $this->assertSame(2_000, $this->stock($this->juice, $this->main));
    }

    public function test_a_receipt_and_its_lines_and_the_payable_facts_cannot_be_rewritten(): void
    {
        $o = $this->issued();
        $this->receive($o['id'], [['order_line_id' => $this->lineIds()['JUICE'], 'accepted' => '2']]);

        foreach (['goods_receipts' => ['note' => 'x'], 'goods_receipt_lines' => ['note' => 'x'], 'supplier_ledger_entries' => ['amount_minor' => 1]] as $table => $change) {
            foreach ([fn () => DB::table($table)->update($change), fn () => DB::table($table)->delete()] as $try) {
                try {
                    $try();
                    $this->fail("A row of {$table} was rewritten.");
                } catch (QueryException) {
                    $this->assertTrue(true);
                }
            }
        }
    }

    public function test_photos_are_added_to_a_line_and_only_seen_by_those_who_may(): void
    {
        $o = $this->issued();
        $r = $this->receive($o['id'], [['order_line_id' => $this->lineIds()['JUICE'], 'accepted' => '2']])->json('receipt');
        $line = $r['lines'][0]['id'];
        $photo = UploadedFile::fake()->createWithContent('crate.png', (string) base64_decode(self::PNG, true));
        $withPhoto = $this->post("/inventory/receipts/{$r['id']}/lines/{$line}/photos", ['photo' => $photo], ['Accept' => 'application/json'])->assertCreated()->json('receipt');
        $photoId = $withPhoto['lines'][0]['photos'][0]['id'];
        $this->get("/inventory/receipts/{$r['id']}/photos/{$photoId}")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->post("/inventory/receipts/{$r['id']}/lines/{$line}/photos", ['photo' => UploadedFile::fake()->create('notes.txt', 1, 'text/plain')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame(1, DB::table('goods_receipt_photos')->count());

        $this->as([InventoryCatalogService::VIEW_PERMISSION]);
        $this->get("/inventory/receipts/{$r['id']}/photos/{$photoId}")->assertForbidden();
        $this->as([PurchasingAccess::ORDER_VIEW]);
        $this->get("/inventory/receipts/{$r['id']}/photos/{$photoId}")->assertOk();
        $this->post("/inventory/receipts/{$r['id']}/lines/{$line}/photos", ['photo' => UploadedFile::fake()->createWithContent('b.png', (string) base64_decode(self::PNG, true))], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_who_may_receive_and_the_overview_lists_what_is_still_to_come(): void
    {
        $o = $this->issued();
        $this->receive($o['id'], [['order_line_id' => $this->lineIds()['WATER'], 'accepted' => '4']]);
        $this->get('/inventory/receipts')->assertInertia(fn (Assert $p) => $p->component('inventory-purchasing/pages/receipts')->where('overview.may.post', true)->where('overview.receivable.0.number', $o['number'])->where('overview.receivable.0.lines.0.remaining_milli', 6_000)->where('overview.over_receipt_bp', 1000));
        $this->as([PurchasingAccess::ORDER_VIEW]);
        $this->get('/inventory/receipts')->assertInertia(fn (Assert $p) => $p->where('overview.may.post', false));
        $this->receive($o['id'], [['order_line_id' => $this->lineIds()['WATER'], 'accepted' => '1']], [], 403);
        $this->as([InventoryCatalogService::VIEW_PERMISSION]);
        $this->get('/inventory/receipts')->assertForbidden();
    }

    public function test_items_below_their_minimum_are_drafted_into_one_request_once_and_never_submitted(): void
    {
        $this->as([PropertySettingsService::MANAGE_PERMISSION, InventoryCatalogService::MANAGE_PERMISSION, StockService::POST_PERMISSION, StockService::VIEW_PERMISSION]);
        $this->postJson('/inventory/stock-limits', ['item_id' => $this->item, 'location_id' => $this->main, 'min' => '12', 'max' => '40'])->assertOk();
        $this->postJson('/inventory/stock-limits', ['item_id' => $this->juice, 'location_id' => $this->main, 'min' => '5'])->assertOk();
        $this->postJson('/inventory/stock/opening', ['unit_cost_minor' => 1_000, 'item_id' => $this->juice, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '9'])->assertCreated();

        $this->artisan('purchasing:restock-drafts')->assertSuccessful();

        self::assertSame(1, DB::table('purchase_requests')->count(), 'only the water is short, and one draft per department');
        $request = DB::table('purchase_requests')->first();
        self::assertSame('draft', $request->status);
        self::assertSame('fnb', $request->department);
        self::assertSame('Automation', DB::table('users')->where('id', $request->requested_by)->value('name'));
        self::assertSame([40_000], DB::table('purchase_request_lines')->where('request_id', $request->id)->pluck('qty_milli')->map(fn ($q) => (int) $q)->all(), 'it asks for the shortfall up to the maximum');

        $this->artisan('purchasing:restock-drafts')->assertSuccessful();
        self::assertSame(1, DB::table('purchase_requests')->count(), 'what is already on a request is not asked for again');

        $this->postJson('/inventory/stock-limits', ['item_id' => $this->juice, 'location_id' => $this->main, 'min' => '50', 'lock_version' => 0])->assertOk();
        config(['inventory.restock.enabled' => false]);
        $this->artisan('purchasing:restock-drafts')->assertSuccessful();
        self::assertSame(1, DB::table('purchase_requests')->count(), 'switched off, it drafts nothing');
    }
}
