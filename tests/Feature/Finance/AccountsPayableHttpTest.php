<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Modules\Finance\Application\FinanceAccess;
use App\Modules\IdentityAccess\Application\Approval\ApprovalPolicyAdmin;
use App\Modules\IdentityAccess\Application\Approval\ApprovalService;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\InventoryPurchasing\Application\InventoryCatalogService;
use App\Modules\InventoryPurchasing\Application\PurchasingAccess;
use App\Modules\InventoryPurchasing\Application\StockService;
use App\Modules\InventoryPurchasing\Application\SupplierService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Modules\Reporting\Application\DashboardService;
use App\Shared\Application\Outbox\ProcessOutboxMessage;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
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

/** FR-FIN-010, -011, -012, -013, -018, -006: accounts payable made from what Purchasing publishes, supplier credits, payments with a second approver, aging and the due schedule. */
final class AccountsPayableHttpTest extends TestCase
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
        $this->as([PropertySettingsService::MANAGE_PERMISSION, InventoryCatalogService::MANAGE_PERMISSION, SupplierService::MANAGE_PERMISSION, PurchasingAccess::ORDER_MANAGE, PurchasingAccess::RECEIPT_POST, PurchasingAccess::BUDGET_MANAGE, PurchasingAccess::INVOICE_MANAGE, PurchasingAccess::RETURN_POST, FinanceAccess::PAYABLE_MANAGE, FinanceAccess::PAYMENT_RECORD, FinanceAccess::ACCOUNT_MANAGE, ApprovalPolicyAdmin::MANAGE_PERMISSION, StockService::VIEW_PERMISSION]);
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

    /** @return array<string, mixed> an issued order of 10 cartons of water and 6 bottles of juice, with the water received */
    private function ordered(string $received = '10'): array
    {
        $o = $this->postJson('/inventory/orders', ['supplier_id' => $this->supplier, 'location_id' => $this->main, 'lines' => [['item_id' => $this->item, 'unit' => 'DUS', 'quantity' => '10'], ['item_id' => $this->juice, 'unit' => 'BTL', 'quantity' => '6']]])->assertCreated()->json('order');
        $this->current = $o['id'];
        $s = $this->postJson("/inventory/orders/{$o['id']}/submit", ['lock_version' => $o['lock_version']])->assertOk()->json('order');
        $o = $this->postJson("/inventory/orders/{$o['id']}/issue", ['lock_version' => $s['lock_version']])->assertOk()->json('order');
        $this->postJson('/inventory/receipts', ['order_id' => $o['id'], 'lines' => [['order_line_id' => $this->line('WATER'), 'accepted' => $received]]], ['Idempotency-Key' => 'gr-'.(++$this->keys).'-'.str_repeat('x', 20)])->assertCreated();

        return $o;
    }

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

    /** Lets the accounts payable consumer handle what Purchasing published, as the outbox worker would. */
    private function drain(): void
    {
        app(PropertyContext::class)->activate(PropertyId::fromString(self::A));

        foreach (DB::table('outbox_messages')->whereIn('event_type', ['purchasing.invoice.recognised', 'purchasing.return.posted'])->where('status', 'pending')->orderBy('occurred_at')->pluck('id')->all() as $id) {
            DB::table('outbox_messages')->where('id', $id)->update(['status' => 'queued']);
            app(ProcessOutboxMessage::class)->execute(PropertyId::fromString(self::A), (string) $id, 1);
        }
    }

    /** @return array<string, mixed> a payable of 26,640,000 due on 2026-10-15 */
    private function payable(): array
    {
        $o = $this->ordered();
        $this->invoice($o['id']);
        $this->drain();

        return (array) DB::table('ap_payables')->first();
    }

    /** @param array<string, mixed> $extra */
    private function pay(string $payableId, int $amount, array $extra = [], int $status = 201): TestResponse
    {
        return $this->postJson('/finance/payments', ['payable_id' => $payableId, 'amount_minor' => $amount, 'method' => 'transfer', 'reference' => 'TRX-1', ...$extra], ['Idempotency-Key' => 'pay-'.(++$this->keys).'-'.str_repeat('x', 20)])->assertStatus($status);
    }

    private function policy(int $min = 0): void
    {
        $this->postJson('/approvals/policies', ['subject_type' => 'finance.supplier-payment', 'band_min_amount_minor' => $min, 'steps' => [['permission' => 'finance.test.approve']], 'reason' => 'Owner policy'])->assertCreated();
    }

    private function approve(string $approvalId, bool $yes = true): void
    {
        $approver = UserRecord::factory()->create();
        $this->grant($approver, self::A, ['finance.test.approve']);
        app(PropertyContext::class)->activate(PropertyId::fromString(self::A));
        $yes ? app(ApprovalService::class)->approve(PropertyId::fromString(self::A), $approvalId, strtolower((string) $approver->getKey())) : app(ApprovalService::class)->reject(PropertyId::fromString(self::A), $approvalId, strtolower((string) $approver->getKey()), 'Not agreed');
    }

    public function test_a_recognised_invoice_becomes_a_payable_with_the_posting_facts(): void
    {
        $o = $this->ordered();
        $this->invoice($o['id']);
        $this->assertSame(0, DB::table('ap_payables')->count(), 'nothing exists before the outbox worker has run');
        $this->drain();
        $p = (array) DB::table('ap_payables')->first();
        $this->assertSame(26_640_000, (int) $p['amount_minor']);
        $this->assertSame(2_640_000, (int) $p['tax_minor']);
        $this->assertSame('2026-10-15', substr((string) $p['due_date'], 0, 10));
        $this->assertSame('2026-10-01', substr((string) $p['business_date'], 0, 10));
        $this->assertSame('INV/2026/001', $p['document_number']);
        $this->assertSame('ABC Beverages', $p['supplier_name']);
        $this->assertSame('PO-000001', $p['order_number']);
        $this->assertSame('supplier_invoice', $p['source_type']);
        $this->assertNotNull($p['event_id']);
        $this->assertNotNull($p['correlation_id']);
        $this->assertNotNull($p['actor_id']);
        $this->assertNotNull($p['occurred_at']);
        $this->get('/finance/payables')->assertInertia(fn (Assert $page) => $page->component('finance/pages/payables')->where('overview.owed_minor', 26_640_000)->where('overview.payables.0.status', 'open')->where('overview.payables.0.balance_minor', 26_640_000));
    }

    public function test_the_same_event_delivered_twice_makes_one_payable(): void
    {
        $o = $this->ordered();
        $this->invoice($o['id']);
        $this->drain();
        $message = DB::table('outbox_messages')->where('event_type', 'purchasing.invoice.recognised')->value('id');
        DB::table('ap_payables')->count();
        app(ProcessOutboxMessage::class)->execute(PropertyId::fromString(self::A), (string) $message, 2);
        $this->assertSame(1, DB::table('ap_payables')->count());
    }

    public function test_an_invoice_with_differences_makes_a_payable_only_when_it_is_approved(): void
    {
        $o = $this->ordered('4');
        $held = $this->invoice($o['id'])->json('invoice');
        $this->drain();
        $this->assertSame(0, DB::table('ap_payables')->count());
        $this->as([PurchasingAccess::INVOICE_RESOLVE]);
        $this->postJson("/inventory/invoices/{$held['id']}/approve", ['lock_version' => $held['lock_version'], 'note' => 'ok'])->assertOk();
        $this->drain();
        $this->assertSame(1, DB::table('ap_payables')->count());
    }

    public function test_a_return_with_a_credit_note_makes_a_credit_and_one_without_does_not(): void
    {
        $o = $this->ordered();
        $this->invoice($o['id']);
        $receipt = (string) DB::table('goods_receipts')->value('id');
        $rl = (string) DB::table('goods_receipt_lines')->value('id');
        $return = fn (array $extra) => $this->postJson('/inventory/returns', ['receipt_id' => $receipt, 'reason' => 'damaged', 'lines' => [['receipt_line_id' => $rl, 'quantity' => '1']], ...$extra], ['Idempotency-Key' => 'rtv-'.(++$this->keys).'-'.str_repeat('x', 20)])->assertCreated();
        $return([]);
        $return(['credit_note_number' => 'CN-1', 'credit_tax_minor' => 264_000]);
        $this->drain();
        $this->assertSame(1, DB::table('ap_credits')->count());
        $c = (array) DB::table('ap_credits')->first();
        $this->assertSame(2_664_000, (int) $c['amount_minor'], '1 carton at 24,000.00 and its 11% tax');
        $this->assertSame('CN-1', $c['credit_note_number']);
    }

    public function test_a_payment_may_be_partial_and_the_payable_is_paid_when_nothing_is_left(): void
    {
        $p = $this->payable();
        $first = $this->pay($p['id'], 10_000_000, ['note' => 'First instalment'])->json('payment');
        $this->assertMatchesRegularExpression('/^PAY-\d{6}$/', $first['number']);
        $this->assertSame('paid', $first['status']);
        $this->get("/finance/payables/{$p['id']}")->assertInertia(fn (Assert $page) => $page->component('finance/pages/payable')->where('payable.status', 'partial')->where('payable.balance_minor', 16_640_000)->where('payable.payments.0.amount_minor', 10_000_000));
        $this->pay($p['id'], 16_640_001, [], 409);
        $this->pay($p['id'], 16_640_000);
        $this->get("/finance/payables/{$p['id']}")->assertInertia(fn (Assert $page) => $page->where('payable.status', 'paid')->where('payable.balance_minor', 0));
        $this->pay($p['id'], 1, [], 409);
        $this->assertSame(2, DB::table('audit_entries')->where('action', 'supplier_payment.recorded')->count());
        $this->assertSame(2, DB::table('outbox_messages')->where('event_type', 'finance.supplier.paid')->count());
    }

    public function test_a_payment_is_checked_for_method_reference_date_and_amount(): void
    {
        $p = $this->payable();
        $this->pay($p['id'], 0, [], 422);
        $this->pay($p['id'], 1, ['method' => 'cheque'], 422);
        $this->pay($p['id'], 1, ['reference' => null], 422); // a transfer needs its reference
        $this->pay($p['id'], 1, ['method' => 'cash', 'reference' => null]);
        $this->pay($p['id'], 1, ['paid_on' => '2026-12-01'], 422);
        $this->pay($p['id'], 1, ['paid_on' => '01-10-2026'], 422);
        $this->pay(strtolower((string) Str::ulid()), 1, [], 404);
        $this->assertSame(1, DB::table('ap_payments')->count());
        self::assertSame(1, DB::table('ap_payments')->whereNotNull('correlation_id')->count(), 'a payment keeps the correlation of the request');
    }

    public function test_the_same_request_with_the_same_key_pays_once(): void
    {
        $p = $this->payable();
        $body = ['payable_id' => $p['id'], 'amount_minor' => 5_000_000, 'method' => 'transfer', 'reference' => 'TRX-9'];
        $headers = ['Idempotency-Key' => 'pay-once-'.str_repeat('x', 20)];
        $first = $this->postJson('/finance/payments', $body, $headers)->assertCreated()->json('payment.id');
        $this->assertSame($first, $this->postJson('/finance/payments', $body, $headers)->json('payment.id'));
        $this->assertSame(1, DB::table('ap_payments')->count());
    }

    public function test_a_payment_over_the_policy_amount_waits_for_a_second_person_and_the_maker_releases_it(): void
    {
        $this->policy(10_000_000);
        $p = $this->payable();
        $small = $this->pay($p['id'], 5_000_000)->json('payment');
        $this->assertSame('paid', $small['status']);
        $big = $this->pay($p['id'], 12_000_000)->json('payment');
        $this->assertSame('pending_approval', $big['status']);
        $approval = (string) DB::table('ap_payments')->where('id', $big['id'])->value('approval_id');
        $this->assertNotSame('', $approval);
        $this->postJson("/finance/payments/{$big['id']}/release")->assertOk()->assertJsonPath('payment.status', 'pending_approval');
        $this->get("/finance/payables/{$p['id']}")->assertInertia(fn (Assert $page) => $page->where('payable.balance_minor', 26_640_000 - 5_000_000)->where('payable.pending_minor', 12_000_000)->where('payable.available_minor', 26_640_000 - 17_000_000));
        $this->pay($p['id'], 9_700_000, [], 409); // pending counts against what can still be paid

        $this->approve($approval);
        $this->postJson("/finance/payments/{$big['id']}/release")->assertOk()->assertJsonPath('payment.status', 'paid');
        $this->postJson("/finance/payments/{$big['id']}/release")->assertStatus(409);
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'supplier_payment.released')->count());
        $this->get("/finance/payables/{$p['id']}")->assertInertia(fn (Assert $page) => $page->where('payable.paid_minor', 17_000_000)->where('payable.pending_minor', 0));
    }

    public function test_only_the_person_who_recorded_a_payment_releases_or_cancels_it(): void
    {
        $this->policy();
        $p = $this->payable();
        $pay = $this->pay($p['id'], 8_000_000)->json('payment');
        $this->approve((string) DB::table('ap_payments')->where('id', $pay['id'])->value('approval_id'));
        $this->as([FinanceAccess::PAYMENT_RECORD]);
        $this->postJson("/finance/payments/{$pay['id']}/release")->assertForbidden();
        $this->postJson("/finance/payments/{$pay['id']}/cancel", ['reason' => 'x'])->assertForbidden();
        $this->get("/finance/payments/{$pay['id']}")->assertInertia(fn (Assert $page) => $page->where('payment.may_release', false)->where('payment.may_cancel', false));
    }

    public function test_a_rejected_payment_is_closed_with_the_reason_and_a_pending_one_can_be_cancelled(): void
    {
        $this->policy();
        $p = $this->payable();
        $rejected = $this->pay($p['id'], 8_000_000)->json('payment');
        $this->approve((string) DB::table('ap_payments')->where('id', $rejected['id'])->value('approval_id'), false);
        $this->postJson("/finance/payments/{$rejected['id']}/release")->assertOk()->assertJsonPath('payment.status', 'rejected')->assertJsonPath('payment.decision_note', 'Not agreed');
        $this->get("/finance/payables/{$p['id']}")->assertInertia(fn (Assert $page) => $page->where('payable.pending_minor', 0)->where('payable.available_minor', 26_640_000));

        $cancelled = $this->pay($p['id'], 3_000_000)->json('payment');
        $this->postJson("/finance/payments/{$cancelled['id']}/cancel", [])->assertStatus(422);
        $this->postJson("/finance/payments/{$cancelled['id']}/cancel", ['reason' => 'Wrong amount'])->assertOk()->assertJsonPath('payment.status', 'cancelled');
        $this->postJson("/finance/payments/{$cancelled['id']}/cancel", ['reason' => 'Again'])->assertStatus(409);
    }

    public function test_a_paid_payment_and_its_payable_cannot_be_rewritten(): void
    {
        $p = $this->payable();
        $this->pay($p['id'], 1_000_000);

        foreach (['ap_payments' => ['amount_minor' => 1], 'ap_payables' => ['amount_minor' => 1]] as $table => $change) {
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

    public function test_a_supplier_credit_is_set_against_a_payable_of_the_same_supplier_in_parts(): void
    {
        $p = $this->payable();
        $supplier = $p['supplier_id'];
        DB::table('ap_credits')->insert(['id' => strtolower((string) Str::ulid()), 'property_id' => self::A, 'supplier_id' => $supplier, 'supplier_code' => 'ABC', 'supplier_name' => 'ABC Beverages', 'source_type' => 'purchase_return', 'source_id' => strtolower((string) Str::ulid()),
            'source_number' => 'RTV-000001', 'credit_note_number' => 'CN-1', 'business_date' => '2026-10-01', 'amount_minor' => 2_664_000, 'currency' => 'IDR', 'event_id' => strtolower((string) Str::ulid()), 'occurred_at' => now(), 'created_at' => now()]);
        $credit = (string) DB::table('ap_credits')->value('id');
        $other = strtolower((string) Str::ulid());
        DB::table('ap_credits')->insert(['id' => $other, 'property_id' => self::A, 'supplier_id' => strtolower((string) Str::ulid()), 'supplier_code' => 'XYZ', 'supplier_name' => 'XYZ', 'source_type' => 'purchase_return', 'source_id' => strtolower((string) Str::ulid()),
            'source_number' => 'RTV-000002', 'credit_note_number' => 'CN-2', 'business_date' => '2026-10-01', 'amount_minor' => 500_000, 'currency' => 'IDR', 'event_id' => strtolower((string) Str::ulid()), 'occurred_at' => now(), 'created_at' => now()]);

        $this->postJson("/finance/payables/{$p['id']}/credits", ['credit_id' => $credit, 'amount_minor' => 1_000_000])->assertCreated()->assertJsonPath('payable.credit_minor', 1_000_000)->assertJsonPath('payable.balance_minor', 25_640_000);
        $this->postJson("/finance/payables/{$p['id']}/credits", ['credit_id' => $credit, 'amount_minor' => 1_664_001])->assertStatus(409);
        $this->postJson("/finance/payables/{$p['id']}/credits", ['credit_id' => $credit, 'amount_minor' => 1_664_000])->assertCreated()->assertJsonPath('payable.balance_minor', 23_976_000);
        $this->postJson("/finance/payables/{$p['id']}/credits", ['credit_id' => $other, 'amount_minor' => 1])->assertStatus(409);
        $this->postJson("/finance/payables/{$p['id']}/credits", ['credit_id' => $credit, 'amount_minor' => 0])->assertStatus(422);
        $this->pay($p['id'], 23_976_000);
        $this->get("/finance/payables/{$p['id']}")->assertInertia(fn (Assert $page) => $page->where('payable.status', 'paid')->has('payable.applications', 2));
    }

    public function test_aging_buckets_what_is_owed_by_days_past_due_as_of_a_date(): void
    {
        $p = $this->payable(); // due 2026-10-15
        $aged = fn (string $date) => $this->get("/finance/aging?as_of={$date}")->viewData('page')['props']['report'];
        $this->assertSame(26_640_000, $aged('2026-10-01')['totals']['current']);
        $this->assertSame(26_640_000, $aged('2026-10-15')['totals']['current'], 'due today is not yet late');
        $this->assertSame(26_640_000, $aged('2026-10-16')['totals']['d1_30']);
        $this->assertSame(26_640_000, $aged('2026-11-14')['totals']['d1_30']);
        $this->assertSame(26_640_000, $aged('2026-11-15')['totals']['d31_60']);
        $this->assertSame(26_640_000, $aged('2026-12-15')['totals']['d61_90']);
        $this->assertSame(26_640_000, $aged('2027-01-14')['totals']['d90_plus']);
        $this->assertSame([], $aged('2026-09-30')['rows'], 'not issued yet');
        $this->pay($p['id'], 6_640_000);
        $report = $aged('2026-11-20');
        $this->assertSame(20_000_000, $report['totals']['d31_60']);
        $this->assertSame('ABC Beverages', $report['rows'][0]['supplier_name']);
        $this->assertSame(20_000_000, $aged('2026-10-10')['totals']['current'], 'the payment was made on 2026-10-01, so it counts from that date on');
        $this->get('/finance/aging?as_of=bad')->assertStatus(302);
    }

    public function test_the_due_schedule_lists_what_is_overdue_and_what_falls_due_soon(): void
    {
        $p = $this->payable();
        $this->get('/finance/schedule?days=7')->assertInertia(fn (Assert $page) => $page->component('finance/pages/schedule')->where('schedule.rows', [])->where('schedule.due_minor', 0));
        $this->get('/finance/schedule?days=14')->assertInertia(fn (Assert $page) => $page->where('schedule.rows.0.balance_minor', 26_640_000)->where('schedule.rows.0.overdue', false)->where('schedule.rows.0.days_to_due', 14));
        $this->pay($p['id'], 26_640_000);
        $this->get('/finance/schedule?days=30')->assertInertia(fn (Assert $page) => $page->where('schedule.rows', []));
    }

    public function test_the_dashboard_warns_of_payables_overdue_and_due_soon(): void
    {
        $p = $this->payable(); // due 2026-10-15, today is 2026-10-01
        $this->as([DashboardService::VIEW_PERMISSION]);
        $alerts = fn () => collect($this->get('/dashboard')->viewData('page')['props']['snapshot']['alerts'])->keyBy('code');
        $this->assertNull($alerts()->get('payables_overdue'));
        $this->assertNull($alerts()->get('payables_due_soon'), 'it falls due in 14 days');
        DB::statement('DROP TRIGGER ap_payables_guard'); // the test moves the due date, which the guard forbids in real use
        DB::table('ap_payables')->update(['due_date' => '2026-10-05']);
        $this->assertSame(1, $alerts()->get('payables_due_soon')['count']);
        $this->assertSame('ABC Beverages · INV/2026/001', $alerts()->get('payables_due_soon')['items'][0]);
        $this->assertSame('/finance/schedule', $alerts()->get('payables_due_soon')['href']);
        DB::table('ap_payables')->update(['due_date' => '2026-09-25']);
        $this->assertSame(1, $alerts()->get('payables_overdue')['count']);
        $this->as([FinanceAccess::PAYMENT_RECORD]);
        $this->pay($p['id'], 26_640_000);
        $this->as([DashboardService::VIEW_PERMISSION]);
        $this->assertNull($alerts()->get('payables_overdue'), 'once paid it is no longer an alert');
    }

    public function test_a_payable_is_classified_under_an_active_expense_account(): void
    {
        $p = $this->payable();
        $a = $this->postJson('/finance/accounts', ['code' => 'bev-cost', 'name' => 'Beverage cost', 'department' => 'fnb', 'category' => 'goods'])->assertCreated()->json('account');
        $this->assertSame('BEV-COST', $a['code']);
        $this->postJson('/finance/accounts', ['code' => 'BEV-COST', 'name' => 'Again', 'department' => 'fnb', 'category' => 'goods'])->assertStatus(409);
        $this->postJson('/finance/accounts', ['code' => 'X', 'name' => 'Bad', 'department' => 'moon', 'category' => 'goods'])->assertStatus(422);
        $this->postJson('/finance/accounts', ['code' => 'Y', 'name' => 'Bad', 'department' => 'fnb', 'category' => 'fun'])->assertStatus(422);
        $this->postJson("/finance/payables/{$p['id']}/classify", ['expense_account_id' => $a['id']])->assertOk()->assertJsonPath('payable.expense_account', 'BEV-COST');
        $this->postJson("/finance/accounts/{$a['id']}", ['name' => 'Beverage cost', 'department' => 'fnb', 'category' => 'goods', 'active' => false, 'lock_version' => $a['lock_version']])->assertOk()->assertJsonPath('account.is_active', false);
        $this->postJson("/finance/payables/{$p['id']}/classify", ['expense_account_id' => $a['id']])->assertStatus(409);
        $this->postJson("/finance/payables/{$p['id']}/classify", ['expense_account_id' => strtolower((string) Str::ulid())])->assertStatus(422);
        $this->postJson("/finance/payables/{$p['id']}/classify", ['expense_account_id' => null])->assertOk()->assertJsonPath('payable.expense_account', null);
        $this->postJson("/finance/accounts/{$a['id']}", ['name' => 'Stale', 'department' => 'fnb', 'category' => 'goods', 'active' => true, 'lock_version' => 0])->assertStatus(409);
    }

    public function test_a_proof_of_payment_is_added_and_only_seen_by_those_who_may(): void
    {
        $p = $this->payable();
        $pay = $this->pay($p['id'], 1_000_000)->json('payment');
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
        $with = $this->post("/finance/payments/{$pay['id']}/proofs", ['proof' => UploadedFile::fake()->createWithContent('slip.pdf', $pdf)], ['Accept' => 'application/json'])->assertCreated()->json('payment');
        $this->get("/finance/payments/{$pay['id']}/proofs/{$with['proofs'][0]['id']}")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->post("/finance/payments/{$pay['id']}/proofs", ['proof' => UploadedFile::fake()->create('x.exe', 1, 'application/octet-stream')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->as([InventoryCatalogService::VIEW_PERMISSION]);
        $this->get("/finance/payments/{$pay['id']}/proofs/{$with['proofs'][0]['id']}")->assertForbidden();
    }

    public function test_who_may_see_pay_and_manage_accounts_payable(): void
    {
        $p = $this->payable();
        $this->as([FinanceAccess::PAYABLE_VIEW]);
        $this->get('/finance/payables')->assertInertia(fn (Assert $page) => $page->where('overview.may.pay', false)->where('overview.may.manage', false));
        $this->pay($p['id'], 1, [], 403);
        $this->postJson("/finance/payables/{$p['id']}/classify", ['expense_account_id' => null])->assertForbidden();
        $this->postJson('/finance/accounts', ['code' => 'A', 'name' => 'A', 'department' => 'fnb', 'category' => 'goods'])->assertForbidden();
        $this->as([InventoryCatalogService::VIEW_PERMISSION]);
        $this->get('/finance/payables')->assertForbidden();
        $this->get('/finance/aging')->assertForbidden();
        $this->get('/finance/payments')->assertForbidden();
    }
}
