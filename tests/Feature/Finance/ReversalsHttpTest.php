<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Modules\Finance\Application\FinanceAccess;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Modules\Reporting\Application\DashboardService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** Reversal of a supplier payment and of a receipt, credit notes and write-offs: new rows that point at the original, never an edit. */
final class ReversalsHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $boss;

    private UserRecord $maker;

    private UserRecord $reverser;

    private UserRecord $dual;

    private UserRecord $reporter;

    private UserRecord $exporter;

    private string $payable;

    private string $payment;

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
        $make = function (array $permissions): UserRecord {
            $user = UserRecord::factory()->create();
            $this->grant($user, self::A, $permissions);

            return $user;
        };
        $this->boss = $make([PropertySettingsService::MANAGE_PERMISSION, DashboardService::VIEW_PERMISSION]);
        $this->maker = $make([FinanceAccess::PAYMENT_RECORD, FinanceAccess::RECEIPT_RECORD, FinanceAccess::RECEIVABLE_MANAGE, FinanceAccess::PAYABLE_VIEW]);
        $this->reverser = $make([FinanceAccess::PAYMENT_REVERSE, FinanceAccess::RECEIPT_REVERSE, FinanceAccess::RECEIVABLE_ADJUST, FinanceAccess::PAYABLE_VIEW, FinanceAccess::RECEIVABLE_VIEW]);
        $this->dual = $make([FinanceAccess::PAYMENT_RECORD, FinanceAccess::PAYMENT_REVERSE, FinanceAccess::RECEIPT_RECORD, FinanceAccess::RECEIPT_REVERSE, FinanceAccess::RECEIVABLE_MANAGE, FinanceAccess::RECEIVABLE_ADJUST]);
        $this->reporter = $make([FinanceAccess::REPORT_VIEW]);
        $this->exporter = $make([FinanceAccess::EXPORT]);
        $this->actAs($this->boss);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-03', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();

        $this->payable = $this->ulid();
        DB::table('ap_payables')->insert(['id' => $this->payable, 'property_id' => self::A, 'supplier_id' => $this->ulid(), 'supplier_code' => 'ABC', 'supplier_name' => 'ABC Supplier', 'source_type' => 'supplier_invoice', 'source_id' => $this->ulid(), 'source_number' => 'SI-1', 'document_number' => 'INV-1',
            'issued_on' => '2026-10-01', 'due_date' => '2026-10-15', 'business_date' => '2026-10-01', 'amount_minor' => 10_000_000, 'tax_minor' => 0, 'currency' => 'IDR', 'event_id' => $this->ulid(), 'occurred_at' => now(), 'created_at' => now()]);
        $this->payment = $this->insertPayment('OLD-000001', 'paid', 4_000_000, 'transfer');
    }

    private function actAs(UserRecord $user): void
    {
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->post('/properties/select', ['property_id' => self::A])->assertRedirect('/');
    }

    private function ulid(): string
    {
        return strtolower((string) Str::ulid());
    }

    private function insertPayment(string $number, string $status, int $amount, string $method, ?UserRecord $by = null): string
    {
        $id = $this->ulid();
        DB::table('ap_payments')->insert(['id' => $id, 'property_id' => self::A, 'number' => $number, 'payable_id' => $this->payable, 'supplier_id' => $this->ulid(), 'amount_minor' => $amount, 'method' => $method, 'paid_on' => '2026-10-02', 'reference' => $method === 'cash' ? null : 'TRF', 'status' => $status,
            'created_by' => (string) ($by ?? $this->maker)->getKey(), 'business_date' => '2026-10-02', 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /** @return array<string, string> */
    private function key(): array
    {
        return ['Idempotency-Key' => 'rv-'.(++$this->keys).'-'.str_repeat('x', 24)];
    }

    public function test_a_paid_payment_is_reversed_in_full_by_someone_else_and_the_payable_owes_it_again(): void
    {
        $url = "/finance/payments/{$this->payment}/reverse";
        $this->actAs($this->maker);
        $this->postJson($url, ['reason' => 'Paid to the wrong account'])->assertForbidden();
        $this->actAs($this->dual);
        $own = $this->insertPayment('OLD-000009', 'paid', 1_000_000, 'cash', $this->dual);
        $this->postJson("/finance/payments/{$own}/reverse", ['reason' => 'My own'])->assertForbidden();

        $this->actAs($this->reverser);
        $this->postJson($url, ['reason' => ' '])->assertStatus(422);
        $this->postJson('/finance/payments/'.$this->insertPayment('OLD-000008', 'pending_approval', 1_000_000, 'cash').'/reverse', ['reason' => 'x'])->assertStatus(409);
        $this->postJson('/finance/payments/'.$this->ulid().'/reverse', ['reason' => 'x'])->assertNotFound();
        $this->get("/finance/payments/{$this->payment}")->assertInertia(fn (Assert $page) => $page->where('payment.may_reverse', true)->where('payment.reversal_number', null));

        $r = $this->postJson($url, ['reason' => 'Paid to the wrong account'])->assertCreated()->json('payment');
        self::assertSame(['reversal', 4_000_000, 'OLD-000001', 'Paid to the wrong account'], [$r['status'], $r['amount_minor'], $r['reverses_number'], $r['reason']]);
        $this->postJson($url, ['reason' => 'Again'])->assertStatus(409);
        $this->postJson('/finance/payments/'.$r['id'].'/reverse', ['reason' => 'Reverse the reversal'])->assertStatus(409);

        $this->get("/finance/payments/{$this->payment}")->assertInertia(fn (Assert $page) => $page->where('payment.reversal_number', $r['number'])->where('payment.may_reverse', false));
        $this->get("/finance/payables/{$this->payable}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('payable.balance_minor', 9_000_000)->where('payable.paid_minor', 1_000_000)->where('payable.status', 'partial'));
        self::assertNotNull(DB::table('audit_entries')->where('action', 'supplier_payment.reversed')->first());
        self::assertNotNull(DB::table('outbox_messages')->where('event_type', 'finance.supplier.payment_reversed')->first());

        foreach ([
            fn () => DB::table('ap_payments')->where('status', 'reversal')->update(['note' => 'edited']),
            fn () => DB::table('ap_payments')->where('id', $this->payment)->delete(),
            fn () => DB::table('ap_payments')->insert(['id' => $this->ulid(), 'property_id' => self::A, 'number' => 'OLD-000077', 'payable_id' => $this->payable, 'reverses_id' => $this->payment, 'supplier_id' => $this->ulid(), 'amount_minor' => 1, 'method' => 'cash', 'paid_on' => '2026-10-03', 'status' => 'reversal', 'created_by' => (string) $this->maker->getKey(), 'business_date' => '2026-10-03', 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]),
        ] as $change) {
            try {
                $change();
                self::fail('A payment or its reversal was changed.');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_a_reversal_is_netted_in_aging_cash_flow_and_exports_from_the_day_it_was_made(): void
    {
        $this->actAs($this->reverser);
        $this->postJson("/finance/payments/{$this->payment}/reverse", ['reason' => 'Returned by the bank'])->assertCreated();

        $this->actAs($this->reporter);
        $this->get('/finance/cashflow?from=2026-10-02&to=2026-10-02')->assertInertia(fn (Assert $page) => $page->where('report.totals.out.bank', 4_000_000));
        $this->get('/finance/cashflow?from=2026-10-03&to=2026-10-03')->assertInertia(fn (Assert $page) => $page->where('report.totals.out.bank', -4_000_000));
        $this->get('/finance/cashflow?from=2026-10-01&to=2026-10-03')->assertInertia(fn (Assert $page) => $page->where('report.totals.out.bank', 0));

        $this->actAs($this->reverser);
        $this->get('/finance/aging?as_of=2026-10-02')->assertInertia(fn (Assert $page) => $page->where('report.totals.total_minor', 6_000_000));
        $this->get('/finance/aging?as_of=2026-10-03')->assertInertia(fn (Assert $page) => $page->where('report.totals.total_minor', 10_000_000));

        $this->actAs($this->exporter);
        $csv = $this->get('/finance/export/supplier_payments?from=2026-10-01&to=2026-10-03')->assertOk()->getContent();
        self::assertStringContainsString('"OLD-000001"', $csv);
        self::assertStringContainsString('"40000.00","payment"', $csv);
        self::assertStringContainsString('"-40000.00","reversal"', $csv);
        $payables = $this->get('/finance/export/payables?from=2026-10-01&to=2026-10-03')->getContent();
        self::assertStringContainsString('"100000.00","0.00","0.00","0.00","100000.00"', $payables);
    }

    /** @return array{id: string, number: string} a receivable made by hand for 10,000,000 by the maker */
    private function manual(?UserRecord $by = null, string $number = 'AR-000001', string $code = 'AGODA'): array
    {
        $customer = $this->ulid();
        DB::table('fin_customers')->insert(['id' => $customer, 'property_id' => self::A, 'code' => $code, 'name' => 'Agoda', 'kind' => 'ota', 'terms_days' => 14, 'is_active' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $id = $this->ulid();
        DB::table('ar_receivables')->insert(['id' => $id, 'property_id' => self::A, 'number' => $number, 'customer_id' => $customer, 'source_type' => 'manual', 'source_id' => $id, 'source_number' => $number, 'description' => 'September payout', 'issued_on' => '2026-09-01', 'due_date' => '2026-09-15', 'business_date' => '2026-09-01',
            'amount_minor' => 10_000_000, 'currency' => 'IDR', 'actor_id' => (string) ($by ?? $this->maker)->getKey(), 'occurred_at' => now(), 'created_at' => now()]);

        return ['id' => $id, 'number' => $number];
    }

    private function receive(string $receivable, int $amount): string
    {
        $this->actAs($this->maker);
        $this->postJson("/finance/receivables/{$receivable}/receipts", ['amount_minor' => $amount, 'method' => 'transfer', 'reference' => 'TRF-1'], $this->key())->assertCreated();

        return (string) DB::table('ar_receipts')->where('kind', 'receipt')->orderByDesc('number')->value('id');
    }

    public function test_a_receipt_of_a_receivable_made_by_hand_is_reversed_by_someone_else(): void
    {
        $r = $this->manual();
        $receipt = $this->receive($r['id'], 4_000_000);
        $url = "/finance/receivables/receipts/{$receipt}/reverse";

        $this->actAs($this->maker);
        $this->postJson($url, ['reason' => 'Wrong customer'])->assertForbidden();
        $this->actAs($this->dual);
        $this->postJson("/finance/receivables/{$r['id']}/receipts", ['amount_minor' => 1_000_000, 'method' => 'giro', 'reference' => 'G-1'], $this->key())->assertCreated();
        $own = (string) DB::table('ar_receipts')->where('reference', 'G-1')->value('id');
        $this->postJson("/finance/receivables/receipts/{$own}/reverse", ['reason' => 'Own'])->assertForbidden();

        $this->actAs($this->reverser);
        $this->postJson($url, ['reason' => ' '])->assertStatus(422);
        $this->postJson('/finance/receivables/receipts/'.$this->ulid().'/reverse', ['reason' => 'x'])->assertNotFound();
        $after = $this->postJson($url, ['reason' => 'Wrong customer'])->assertCreated()->json('receivable');
        self::assertSame([9_000_000, 'receipt'], [$after['balance_minor'], $after['receipts'][0]['kind']]);
        $row = collect($after['receipts'])->firstWhere('kind', 'reversal');
        self::assertSame([4_000_000, 'RCP-000003', 'transfer'], [$row['amount_minor'], $row['number'], $row['method']]);
        self::assertSame($row['number'], collect($after['receipts'])->firstWhere('id', $receipt)['reversal_number']);
        $this->postJson($url, ['reason' => 'Again'])->assertStatus(409);
        $this->postJson('/finance/receivables/receipts/'.$row['id'].'/reverse', ['reason' => 'Reverse the reversal'])->assertStatus(409);
        self::assertNotNull(DB::table('audit_entries')->where('action', 'receipt.reversed')->first());

        $this->actAs($this->reporter);
        $this->get('/finance/cashflow?from=2026-10-03&to=2026-10-03')->assertInertia(fn (Assert $page) => $page->where('report.totals.in.bank', 1_000_000));

        try {
            DB::table('ar_receipts')->where('kind', 'reversal')->update(['note' => 'edited']);
            self::fail('A reversal was edited.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }

    public function test_a_receipt_that_settled_a_company_folio_is_not_reversed_here(): void
    {
        $id = $this->ulid();
        $customer = $this->ulid();
        DB::table('fin_customers')->insert(['id' => $customer, 'property_id' => self::A, 'code' => 'ACME', 'name' => 'Acme', 'kind' => 'company', 'company_id' => $this->ulid(), 'terms_days' => 30, 'is_active' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ar_receivables')->insert(['id' => $id, 'property_id' => self::A, 'number' => 'AR-000002', 'customer_id' => $customer, 'source_type' => 'company_folio', 'source_id' => $this->ulid(), 'source_number' => 'FOL-1', 'description' => 'Stay', 'issued_on' => '2026-09-01', 'due_date' => '2026-10-01', 'business_date' => '2026-09-01',
            'amount_minor' => 5_000_000, 'currency' => 'IDR', 'actor_id' => (string) $this->maker->getKey(), 'occurred_at' => now(), 'created_at' => now()]);
        $receipt = $this->receive($id, 1_000_000);

        $this->actAs($this->reverser);
        $this->postJson("/finance/receivables/receipts/{$receipt}/reverse", ['reason' => 'Wrong'])->assertStatus(409);
        $this->postJson("/finance/receivables/{$id}/adjustments", ['kind' => 'write_off', 'amount_minor' => 1_000, 'reason' => 'Wrong'])->assertStatus(409);
        $this->get("/finance/receivables/{$id}")->assertInertia(fn (Assert $page) => $page->where('receivable.may_adjust', false)->where('receivable.receipts.0.may_reverse', false));
    }

    public function test_a_credit_note_and_a_write_off_settle_a_receivable_made_by_hand_without_money(): void
    {
        $r = $this->manual();
        $this->receive($r['id'], 3_000_000);
        $url = "/finance/receivables/{$r['id']}/adjustments";
        $body = ['kind' => 'credit_note', 'amount_minor' => 2_000_000, 'reason' => 'Billed twice for one booking'];

        $this->actAs($this->maker);
        $this->postJson($url, $body)->assertForbidden();
        $this->actAs($this->dual);
        $made = $this->manual($this->dual, 'AR-000009', 'MINE');
        $this->postJson("/finance/receivables/{$made['id']}/adjustments", $body)->assertForbidden();

        $this->actAs($this->reverser);
        $this->postJson($url, [...$body, 'kind' => 'gift'])->assertStatus(422);
        $this->postJson($url, [...$body, 'amount_minor' => 0])->assertStatus(422);
        $this->postJson($url, [...$body, 'reason' => ' '])->assertStatus(422);
        $this->postJson($url, [...$body, 'amount_minor' => 7_000_001])->assertStatus(409);

        $credit = $this->postJson($url, $body)->assertCreated()->json('receivable');
        self::assertSame([5_000_000, 'partial', 'credit_note', null, 'ADJ-000001'], [$credit['balance_minor'], $credit['status'], $credit['receipts'][1]['kind'], $credit['receipts'][1]['method'], $credit['receipts'][1]['number']]);

        $this->postJson($url, ['kind' => 'write_off', 'amount_minor' => 5_000_000, 'reason' => 'The channel closed down'])->assertCreated()->assertJsonPath('receivable.balance_minor', 0)->assertJsonPath('receivable.status', 'paid');
        $this->postJson($url, ['kind' => 'write_off', 'amount_minor' => 1, 'reason' => 'More'])->assertStatus(409);
        self::assertSame(2, DB::table('audit_entries')->whereIn('action', ['receivable.credit_note', 'receivable.write_off'])->count());

        // Neither is money: the cash flow counts only the receipt of 3,000,000.
        $this->actAs($this->reporter);
        $this->get('/finance/cashflow?from=2026-10-03&to=2026-10-03')->assertInertia(fn (Assert $page) => $page->where('report.totals.in.bank', 3_000_000));

        $this->actAs($this->boss);
        $alerts = collect($this->get('/dashboard')->viewData('page')['props']['snapshot']['alerts'])->keyBy('code');
        self::assertSame(['Agoda · AR-000009'], $alerts->get('receivables_overdue')['items'], 'the written-off receivable is no longer owed; only the other one is');

        $this->actAs($this->exporter);
        $csv = $this->get('/finance/export/receipts?from=2026-10-01&to=2026-10-03')->assertOk()->getContent();
        self::assertStringContainsString('"20000.00","credit_note"', $csv);
        self::assertStringContainsString('"50000.00","write_off"', $csv);
    }
}
