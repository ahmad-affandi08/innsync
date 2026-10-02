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

/** FR-PUR-007, FR-PUR-012: supplier invoices that cannot be entered twice, matched to the order and the goods received, and recognised as a payable. */
final class SupplierInvoiceHttpTest extends TestCase
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
        $this->as([PropertySettingsService::MANAGE_PERMISSION, InventoryCatalogService::MANAGE_PERMISSION, SupplierService::MANAGE_PERMISSION, PurchasingAccess::ORDER_MANAGE, PurchasingAccess::RECEIPT_POST, PurchasingAccess::BUDGET_MANAGE, PurchasingAccess::INVOICE_MANAGE, StockService::VIEW_PERMISSION]);
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

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    /** @return array<string, mixed> an issued order of 10 cartons of water and 6 bottles of juice, with `water` cartons received */
    private function ordered(string $received = '10'): array
    {
        $o = $this->postJson('/inventory/orders', ['supplier_id' => $this->supplier, 'location_id' => $this->main, 'lines' => [['item_id' => $this->item, 'unit' => 'DUS', 'quantity' => '10'], ['item_id' => $this->juice, 'unit' => 'BTL', 'quantity' => '6']]])->assertCreated()->json('order');
        $this->current = $o['id'];
        $s = $this->postJson("/inventory/orders/{$o['id']}/submit", ['lock_version' => $o['lock_version']])->assertOk()->json('order');
        $o = $this->postJson("/inventory/orders/{$o['id']}/issue", ['lock_version' => $s['lock_version']])->assertOk()->json('order');

        if ($received !== '0') {
            $this->postJson('/inventory/receipts', ['order_id' => $o['id'], 'lines' => [['order_line_id' => $this->line('WATER'), 'accepted' => $received]]], ['Idempotency-Key' => 'gr-'.(++$this->keys).'-'.str_repeat('x', 20)])->assertCreated();
        }

        return $o;
    }

    private string $current = '';

    private function line(string $code): string
    {
        return (string) DB::table('purchase_order_lines')->join('inventory_items', 'inventory_items.id', '=', 'purchase_order_lines.item_id')->where('purchase_order_lines.order_id', $this->current)->where('inventory_items.code', $code)->value('purchase_order_lines.id');
    }

    /** @param array<string, mixed> $extra */
    private function invoice(string $orderId, array $extra = [], int $status = 201): TestResponse
    {
        $body = ['order_id' => $orderId, 'invoice_number' => 'INV/2026/001', 'invoice_date' => '2026-10-01', 'tax_number' => '0100002400000001', 'tax_minor' => 2_640_000, 'total_minor' => 26_640_000,
            'lines' => [['order_line_id' => $this->line('WATER'), 'quantity' => '10', 'unit_price_minor' => 2_400_000]], ...$extra];

        return $this->postJson('/inventory/invoices', $body, ['Idempotency-Key' => 'si-'.(++$this->keys).'-'.str_repeat('x', 20)])->assertStatus($status);
    }

    private function owed(): int
    {
        return (int) DB::table('supplier_ledger_entries')->sum('amount_minor');
    }

    public function test_an_invoice_that_matches_is_recognised_at_once_replacing_the_goods_received_accrual(): void
    {
        $o = $this->ordered();
        $this->assertSame(24_000_000, $this->owed());
        $i = $this->invoice($o['id'])->json('invoice');
        $this->assertMatchesRegularExpression('/^SI-\d{6}$/', $i['number']);
        $this->assertSame('matched', $i['status']);
        $this->assertSame([], $i['variances']);
        $this->assertSame('2026-10-15', $i['due_date'], '14 days of payment terms');
        $this->assertSame(26_640_000, $this->owed(), 'the accrual of 24,000,000 comes off and the invoice of 26,640,000 goes on');
        $this->assertSame(['goods_received', 'invoice', 'invoice_accrual'], DB::table('supplier_ledger_entries')->orderBy('kind')->pluck('kind')->all());
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'supplier_invoice.entered')->count());
    }

    public function test_the_same_invoice_cannot_be_entered_twice_whatever_the_spacing_or_case(): void
    {
        $o = $this->ordered();
        $this->invoice($o['id']);
        $this->invoice($o['id'], ['invoice_number' => 'inv 2026 001'], 409);
        $this->invoice($o['id'], ['invoice_number' => 'INV-2026-001 '], 409);
        $this->assertSame(1, DB::table('supplier_invoices')->count());
        $this->assertSame(26_640_000, $this->owed());
    }

    public function test_the_same_request_with_the_same_key_enters_one_invoice(): void
    {
        $o = $this->ordered();
        $body = ['order_id' => $o['id'], 'invoice_number' => 'K-1', 'invoice_date' => '2026-10-01', 'tax_number' => '0100002400000001', 'tax_minor' => 264_000, 'total_minor' => 2_664_000, 'lines' => [['order_line_id' => $this->line('WATER'), 'quantity' => '1', 'unit_price_minor' => 2_400_000]]];
        $headers = ['Idempotency-Key' => 'si-once-'.str_repeat('x', 20)];
        $first = $this->postJson('/inventory/invoices', $body, $headers)->assertCreated()->json('invoice.id');
        $this->assertSame($first, $this->postJson('/inventory/invoices', $body, $headers)->json('invoice.id'));
        $this->assertSame(1, DB::table('supplier_invoices')->count());
    }

    public function test_an_invoice_is_checked_for_its_order_date_lines_and_total(): void
    {
        $draft = $this->postJson('/inventory/orders', ['supplier_id' => $this->supplier, 'location_id' => $this->main, 'lines' => [['item_id' => $this->item, 'unit' => 'DUS', 'quantity' => '1']]])->assertCreated()->json('order');
        $this->invoice($draft['id'], ['lines' => [['order_line_id' => (string) DB::table('purchase_order_lines')->value('id'), 'quantity' => '1', 'unit_price_minor' => 2_400_000]], 'tax_minor' => 264_000, 'total_minor' => 2_664_000], 409);

        $o = $this->ordered();
        $this->invoice($o['id'], ['total_minor' => 26_000_000], 422);
        $this->invoice($o['id'], ['invoice_date' => '2026-12-01'], 422);
        $this->invoice($o['id'], ['invoice_date' => '01-10-2026'], 422);
        $this->invoice($o['id'], ['invoice_number' => '///'], 422);
        $this->invoice($o['id'], ['tax_number' => '123'], 422);
        $this->invoice($o['id'], ['lines' => [['order_line_id' => strtolower((string) Str::ulid()), 'quantity' => '1', 'unit_price_minor' => 1]], 'tax_minor' => 0, 'total_minor' => 1], 422);
        $this->invoice($o['id'], ['lines' => [['order_line_id' => $this->line('WATER'), 'quantity' => '0', 'unit_price_minor' => 1]]], 422);
        $this->invoice($o['id'], ['lines' => [['order_line_id' => $this->line('WATER'), 'quantity' => '1', 'unit_price_minor' => 1], ['order_line_id' => $this->line('WATER'), 'quantity' => '1', 'unit_price_minor' => 1]]], 422);
        $this->assertSame(0, DB::table('supplier_invoices')->count());
    }

    public function test_more_than_was_received_a_different_price_and_a_wrong_tax_hold_the_invoice(): void
    {
        $o = $this->ordered('4');
        $held = $this->invoice($o['id'])->json('invoice'); // invoices 10 cartons, 4 received
        $this->assertSame('variance', $held['status']);
        $this->assertSame(['quantity'], array_column($held['variances'], 'type'));
        $this->assertSame(4_000, $held['variances'][0]['expected_milli']);
        $this->assertSame(0, (int) DB::table('supplier_ledger_entries')->where('kind', 'invoice')->count(), 'nothing is recognised while it waits');

        $price = $this->invoice($o['id'], ['invoice_number' => 'P-1', 'lines' => [['order_line_id' => $this->line('WATER'), 'quantity' => '1', 'unit_price_minor' => 2_600_000]], 'tax_minor' => 286_000, 'total_minor' => 2_886_000])->json('invoice');
        $this->assertContains('price', array_column($price['variances'], 'type'));

        $tax = $this->invoice($o['id'], ['invoice_number' => 'T-1', 'tax_number' => null, 'lines' => [['order_line_id' => $this->line('WATER'), 'quantity' => '1', 'unit_price_minor' => 2_400_000]], 'tax_minor' => 100_000, 'total_minor' => 2_500_000])->json('invoice');
        $this->assertEqualsCanonicalizing(['not_received', 'tax', 'tax_document'], array_column($tax['variances'], 'type'), 'what a held invoice covers is counted as invoiced until it is rejected');
        $this->assertSame(['variance', 'variance', 'variance'], DB::table('supplier_invoices')->orderBy('invoice_number')->pluck('status')->all());
    }

    public function test_a_small_price_difference_inside_the_tolerance_matches(): void
    {
        $o = $this->ordered();
        $i = $this->invoice($o['id'], ['lines' => [['order_line_id' => $this->line('WATER'), 'quantity' => '10', 'unit_price_minor' => 2_410_000]], 'tax_minor' => 2_651_000, 'total_minor' => 26_751_000])->json('invoice'); // +0.4%
        $this->assertSame('matched', $i['status']);
    }

    public function test_partial_invoices_use_up_what_was_received_and_a_third_goes_over(): void
    {
        $o = $this->ordered();
        $part = fn (string $no, string $qty, int $total, int $tax) => $this->invoice($o['id'], ['invoice_number' => $no, 'lines' => [['order_line_id' => $this->line('WATER'), 'quantity' => $qty, 'unit_price_minor' => 2_400_000]], 'tax_minor' => $tax, 'total_minor' => $total])->json('invoice');
        $this->assertSame('matched', $part('A-1', '4', 10_656_000, 1_056_000)['status']);
        $this->assertSame('matched', $part('A-2', '6', 15_984_000, 1_584_000)['status']);
        $this->assertSame('variance', $part('A-3', '1', 2_664_000, 264_000)['status'], 'everything received is already invoiced');
        $this->get('/inventory/invoices')->assertInertia(fn (Assert $p) => $p->where('overview.invoiceable', []));
    }

    public function test_someone_else_decides_an_invoice_with_differences_with_a_note(): void
    {
        $o = $this->ordered('4');
        $held = $this->invoice($o['id'])->json('invoice');
        $this->as([PurchasingAccess::INVOICE_RESOLVE]);
        $this->postJson("/inventory/invoices/{$held['id']}/approve", ['lock_version' => $held['lock_version']])->assertStatus(422);
        $done = $this->postJson("/inventory/invoices/{$held['id']}/approve", ['lock_version' => $held['lock_version'], 'note' => 'Goods arrive tomorrow, supplier confirmed'])->assertOk()->json('invoice');
        $this->assertSame('approved', $done['status']);
        $this->assertSame(26_640_000, $this->owed(), 'the accrual of the 4 received cartons (9,600,000) comes off and the whole invoice goes on');
        $this->postJson("/inventory/invoices/{$held['id']}/approve", ['lock_version' => $held['lock_version'] + 1, 'note' => 'again'])->assertStatus(409);
    }

    public function test_the_person_who_entered_an_invoice_cannot_decide_it(): void
    {
        $o = $this->ordered('4');
        $this->as([PurchasingAccess::INVOICE_MANAGE, PurchasingAccess::INVOICE_RESOLVE, PurchasingAccess::ORDER_MANAGE, PurchasingAccess::RECEIPT_POST]);
        $held = $this->invoice($o['id'])->json('invoice');
        $this->get("/inventory/invoices/{$held['id']}")->assertInertia(fn (Assert $p) => $p->where('invoice.may_resolve', false)->where('invoice.resolve_blocked_self', true));
        $this->postJson("/inventory/invoices/{$held['id']}/reject", ['lock_version' => $held['lock_version'], 'note' => 'mine'])->assertForbidden();
    }

    public function test_a_rejected_invoice_recognises_nothing_and_frees_its_quantity(): void
    {
        $o = $this->ordered('4');
        $held = $this->invoice($o['id'])->json('invoice');
        $this->as([PurchasingAccess::INVOICE_MANAGE, PurchasingAccess::INVOICE_RESOLVE]);
        $this->postJson("/inventory/invoices/{$held['id']}/reject", ['lock_version' => $held['lock_version'], 'note' => 'Wrong quantity'])->assertOk()->assertJsonPath('invoice.status', 'rejected');
        $this->assertSame(9_600_000, $this->owed());
        $this->get('/inventory/invoices')->assertInertia(fn (Assert $p) => $p->where('overview.invoiceable.0.lines.0.open_milli', 4_000));
        $this->invoice($o['id'], ['invoice_number' => 'INV/2026/002', 'lines' => [['order_line_id' => $this->line('WATER'), 'quantity' => '4', 'unit_price_minor' => 2_400_000]], 'tax_minor' => 1_056_000, 'total_minor' => 10_656_000])->assertJsonPath('invoice.status', 'matched');
    }

    public function test_invoices_their_lines_and_documents_cannot_be_rewritten(): void
    {
        $o = $this->ordered();
        $this->invoice($o['id']);

        foreach (['supplier_invoices' => ['note' => 'x'], 'supplier_invoice_lines' => ['qty_milli' => 1]] as $table => $change) {
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

    public function test_a_supporting_document_is_added_and_only_seen_by_those_who_may(): void
    {
        $o = $this->ordered();
        $i = $this->invoice($o['id'])->json('invoice');
        $doc = UploadedFile::fake()->createWithContent('invoice.pdf', self::PDF);
        $with = $this->post("/inventory/invoices/{$i['id']}/documents", ['document' => $doc], ['Accept' => 'application/json'])->assertCreated()->json('invoice');
        $this->get("/inventory/invoices/{$i['id']}/documents/{$with['documents'][0]['id']}")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->post("/inventory/invoices/{$i['id']}/documents", ['document' => UploadedFile::fake()->create('x.exe', 1, 'application/octet-stream')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame(1, DB::table('supplier_invoice_documents')->count());
        $this->as([InventoryCatalogService::VIEW_PERMISSION]);
        $this->get("/inventory/invoices/{$i['id']}/documents/{$with['documents'][0]['id']}")->assertForbidden();
    }

    public function test_who_may_enter_decide_and_see_invoices(): void
    {
        $o = $this->ordered();
        $this->as([PurchasingAccess::ORDER_VIEW]);
        $this->get('/inventory/invoices')->assertInertia(fn (Assert $p) => $p->component('inventory-purchasing/pages/invoices')->where('overview.may.record', false)->where('overview.may.resolve', false));
        $this->invoice($o['id'], [], 403);
        $this->as([InventoryCatalogService::VIEW_PERMISSION]);
        $this->get('/inventory/invoices')->assertForbidden();
    }
}
