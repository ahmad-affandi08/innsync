<?php

declare(strict_types=1);

namespace Tests\Feature\InventoryPurchasing;

use App\Modules\InventoryPurchasing\Application\InventoryCatalogService;
use App\Modules\InventoryPurchasing\Application\PurchasingAccess;
use App\Modules\InventoryPurchasing\Application\StockService;
use App\Modules\InventoryPurchasing\Application\StockTransferService;
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

/** FR-INV-011: goods returned to a supplier against the receipt and back between locations against the transfer, so stock, what is owed and the history reconcile. */
final class ReturnHttpTest extends TestCase
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
        $this->as([PropertySettingsService::MANAGE_PERMISSION, InventoryCatalogService::MANAGE_PERMISSION, SupplierService::MANAGE_PERMISSION, PurchasingAccess::ORDER_MANAGE, PurchasingAccess::RECEIPT_POST, PurchasingAccess::BUDGET_MANAGE, PurchasingAccess::INVOICE_MANAGE, PurchasingAccess::RETURN_POST, StockService::VIEW_PERMISSION, StockService::POST_PERMISSION, StockTransferService::SEND_PERMISSION]);
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

    private string $current = '';

    private string $receiptId = '';

    /** @return array<string, mixed> an issued order of 10 cartons of water (240 bottles), all received */
    private function received(): array
    {
        $o = $this->postJson('/inventory/orders', ['supplier_id' => $this->supplier, 'location_id' => $this->main, 'lines' => [['item_id' => $this->item, 'unit' => 'DUS', 'quantity' => '10']]])->assertCreated()->json('order');
        $s = $this->postJson("/inventory/orders/{$o['id']}/submit", ['lock_version' => $o['lock_version']])->assertOk()->json('order');
        $o = $this->postJson("/inventory/orders/{$o['id']}/issue", ['lock_version' => $s['lock_version']])->assertOk()->json('order');
        $this->current = $o['id'];
        $line = (string) DB::table('purchase_order_lines')->where('order_id', $o['id'])->value('id');
        $r = $this->postJson('/inventory/receipts', ['order_id' => $o['id'], 'lines' => [['order_line_id' => $line, 'accepted' => '10']]], ['Idempotency-Key' => 'gr-'.(++$this->keys).'-'.str_repeat('x', 20)])->assertCreated()->json('receipt');
        $this->receiptId = $r['id'];

        return $r;
    }

    private function receiptLine(): string
    {
        return (string) DB::table('goods_receipt_lines')->where('receipt_id', $this->receiptId)->value('id');
    }

    /** @param array<string, mixed> $extra */
    private function giveBack(string $quantity, array $extra = [], int $status = 201): TestResponse
    {
        return $this->postJson('/inventory/returns', ['receipt_id' => $this->receiptId, 'reason' => 'damaged', 'lines' => [['receipt_line_id' => $this->receiptLine(), 'quantity' => $quantity]], ...$extra], ['Idempotency-Key' => 'rtv-'.(++$this->keys).'-'.str_repeat('x', 20)])->assertStatus($status);
    }

    private function stock(string $location): int
    {
        return (int) DB::table('stock_movements')->where('item_id', $this->item)->where('location_id', $location)->sum('base_qty_milli');
    }

    private function owed(): int
    {
        return (int) DB::table('supplier_ledger_entries')->sum('amount_minor');
    }

    public function test_a_return_takes_stock_out_at_the_receipt_cost_and_comes_off_what_is_owed(): void
    {
        $this->received();
        $r = $this->giveBack('3', ['note' => 'Crushed cartons'])->json('return');
        $this->assertMatchesRegularExpression('/^RTV-\d{6}$/', $r['number']);
        $this->assertSame(7_200_000, $r['value_minor']);
        $this->assertSame(240_000 - 72_000, $this->stock($this->main));
        $this->assertSame('return_out', DB::table('stock_movements')->where('source_type', 'purchase_return')->value('kind'));
        $this->assertSame(-72_000, (int) DB::table('stock_movements')->where('kind', 'return_out')->value('base_qty_milli'));
        $this->assertSame(-7_200_000, (int) DB::table('stock_movements')->where('kind', 'return_out')->value('value_minor'), 'out at what the goods cost on the receipt');
        $this->assertSame(16_800_000, (int) DB::table('stock_movements')->where('item_id', $this->item)->sum('value_minor'));
        $this->assertSame(24_000_000 - 7_200_000, $this->owed());
        $this->assertSame(3_000, (int) DB::table('purchase_order_lines')->value('returned_qty_milli'));
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'purchase_return.posted')->count());
        $this->get("/inventory/returns/{$r['id']}")->assertInertia(fn (Assert $p) => $p->component('inventory-purchasing/pages/return')->where('purchaseReturn.lines.0.qty_milli', 3_000)->where('purchaseReturn.receipt.number', 'GR-000001'));
    }

    public function test_a_credit_note_of_tax_is_recorded_with_the_return(): void
    {
        $this->received();
        $this->giveBack('2', ['credit_tax_minor' => 528_000], 422); // tax credit needs the number
        $this->giveBack('2', ['credit_tax_minor' => 528_000, 'credit_note_number' => 'CN-77']);
        $this->assertSame(['credit_note', 'goods_received', 'goods_returned'], DB::table('supplier_ledger_entries')->orderBy('kind')->pluck('kind')->all());
        $this->assertSame(24_000_000 - 4_800_000 - 528_000, $this->owed());
    }

    public function test_no_more_can_go_back_than_the_receipt_accepted_less_what_already_went(): void
    {
        $this->received();
        $this->giveBack('4');
        $this->giveBack('7', [], 409);
        $this->giveBack('6');
        $this->giveBack('0.5', [], 409);
        $this->assertSame(10_000, (int) DB::table('purchase_order_lines')->value('returned_qty_milli'));
        $this->assertSame(0, $this->stock($this->main));
    }

    public function test_goods_that_are_no_longer_in_the_location_cannot_be_returned(): void
    {
        $this->received();
        $this->postJson('/inventory/stock/movements', ['kind' => 'issue', 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '200', 'reason_code' => 'fnb'], ['Idempotency-Key' => 'mv-1-'.str_repeat('x', 20)])->assertStatus(201);
        $this->giveBack('3', [], 409); // only 40 bottles left, 72 would go back
        $this->assertSame(40_000, $this->stock($this->main));
        $this->assertSame(0, DB::table('purchase_returns')->count());
        $this->assertSame(24_000_000, $this->owed());
        $this->giveBack('1');
    }

    public function test_a_return_is_checked_for_its_receipt_reason_and_lines(): void
    {
        $this->received();
        $this->giveBack('1', ['reason' => 'dislike'], 422);
        $this->giveBack('1', ['reason' => 'other'], 422);
        $this->giveBack('1', ['receipt_id' => strtolower((string) Str::ulid())], 422);
        $this->giveBack('1', ['lines' => [['receipt_line_id' => strtolower((string) Str::ulid()), 'quantity' => '1']]], 422);
        $this->giveBack('x', [], 422);
        $this->postJson('/inventory/returns', ['receipt_id' => $this->receiptId, 'reason' => 'damaged', 'lines' => [['receipt_line_id' => $this->receiptLine(), 'quantity' => '1'], ['receipt_line_id' => $this->receiptLine(), 'quantity' => '1']]], ['Idempotency-Key' => 'rtv-dup-'.str_repeat('x', 20)])->assertStatus(422);
        $this->assertSame(0, DB::table('purchase_returns')->count());
    }

    public function test_the_same_request_with_the_same_key_returns_once(): void
    {
        $this->received();
        $body = ['receipt_id' => $this->receiptId, 'reason' => 'damaged', 'lines' => [['receipt_line_id' => $this->receiptLine(), 'quantity' => '1']]];
        $headers = ['Idempotency-Key' => 'rtv-once-'.str_repeat('x', 20)];
        $first = $this->postJson('/inventory/returns', $body, $headers)->assertCreated()->json('return.id');
        $this->assertSame($first, $this->postJson('/inventory/returns', $body, $headers)->json('return.id'));
        $this->assertSame(1, DB::table('purchase_returns')->count());
        $this->assertSame(240_000 - 24_000, $this->stock($this->main));
    }

    public function test_goods_returned_before_they_are_invoiced_are_not_invoiceable(): void
    {
        $this->received();
        $this->giveBack('3');
        $this->get('/inventory/invoices')->assertInertia(fn (Assert $p) => $p->where('overview.invoiceable.0.lines.0.open_milli', 7_000));
    }

    public function test_a_return_and_its_lines_cannot_be_rewritten(): void
    {
        $this->received();
        $this->giveBack('1');

        foreach (['purchase_returns' => ['note' => 'x'], 'purchase_return_lines' => ['qty_milli' => 5]] as $table => $change) {
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

    public function test_the_returns_screen_lists_what_can_go_back_and_who_may(): void
    {
        $this->received();
        $this->giveBack('4');
        $this->get('/inventory/returns')->assertInertia(fn (Assert $p) => $p->component('inventory-purchasing/pages/returns')->where('overview.may.post', true)->where('overview.returnable.0.lines.0.returnable_milli', 6_000)->where('overview.returns.0.value_minor', 9_600_000));
        $this->as([PurchasingAccess::ORDER_VIEW]);
        $this->get('/inventory/returns')->assertInertia(fn (Assert $p) => $p->where('overview.may.post', false));
        $this->giveBack('1', [], 403);
        $this->as([InventoryCatalogService::VIEW_PERMISSION]);
        $this->get('/inventory/returns')->assertForbidden();
    }

    /** @return array{0: string, 1: string} a received transfer of 24 bottles from the main store to the bar and its id, then the receiver signed in */
    private function transferred(): array
    {
        $this->postJson('/inventory/stock/opening', ['unit_cost_minor' => 1_000, 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '100'])->assertCreated();
        $id = $this->postJson('/inventory/transfers', ['from_location_id' => $this->main, 'to_location_id' => $this->bar, 'lines' => [['item_id' => $this->item, 'unit' => 'BTL', 'quantity' => '24']]], ['Idempotency-Key' => 'trf-'.(++$this->keys).'-'.str_repeat('x', 20)])->assertCreated()->json('transfer.id');
        $this->as([StockTransferService::RECEIVE_PERMISSION, StockTransferService::SEND_PERMISSION, StockService::VIEW_PERMISSION]);
        $this->postJson("/inventory/transfers/{$id}/receive", ['lock_version' => 0])->assertOk();

        return [$id, 'TRF-000001'];
    }

    /** @param array<string, mixed> $extra */
    private function sendBack(string $from, string $to, string $quantity, ?string $returnOf, int $status = 201): TestResponse
    {
        return $this->postJson('/inventory/transfers', ['from_location_id' => $from, 'to_location_id' => $to, 'return_of' => $returnOf, 'lines' => [['item_id' => $this->item, 'unit' => 'BTL', 'quantity' => $quantity]]], ['Idempotency-Key' => 'trf-'.(++$this->keys).'-'.str_repeat('x', 20)])->assertStatus($status);
    }

    public function test_goods_can_go_back_to_where_they_were_sent_from_against_the_transfer(): void
    {
        [$id, $number] = $this->transferred();
        $back = $this->sendBack($this->bar, $this->main, '10', $id)->json('transfer');
        $this->assertSame($id, DB::table('stock_transfers')->where('id', $back['id'])->value('return_of'));
        $this->get('/inventory/transfers')->assertInertia(fn (Assert $p) => $p->where('overview.transfers.0.return_of_number', $number));
        $this->sendBack($this->bar, $this->main, '20', $id, 409); // 24 were moved, 10 are already on their way back
        $this->sendBack($this->bar, $this->main, '14', $id);
        $this->sendBack($this->bar, $this->main, '1', $id, 409);
    }

    public function test_a_return_transfer_goes_the_other_way_and_only_for_a_received_transfer(): void
    {
        [$id] = $this->transferred();
        $this->sendBack($this->main, $this->bar, '1', $id, 422); // same direction as the original
        $this->sendBack($this->bar, $this->main, '1', strtolower((string) Str::ulid()), 422);
        $pending = $this->postJson('/inventory/transfers', ['from_location_id' => $this->main, 'to_location_id' => $this->bar, 'lines' => [['item_id' => $this->item, 'unit' => 'BTL', 'quantity' => '1']]], ['Idempotency-Key' => 'trf-p-'.str_repeat('x', 20)])->assertCreated()->json('transfer.id');
        $this->sendBack($this->bar, $this->main, '1', $pending, 409); // not received yet
    }

    public function test_a_rejected_return_transfer_frees_its_quantity_again(): void
    {
        [$id] = $this->transferred();
        $back = $this->sendBack($this->bar, $this->main, '24', $id)->json('transfer');
        $this->sendBack($this->bar, $this->main, '1', $id, 409);
        $this->postJson("/inventory/transfers/{$back['id']}/cancel", ['lock_version' => 0])->assertOk();
        $this->sendBack($this->bar, $this->main, '24', $id);
    }
}
