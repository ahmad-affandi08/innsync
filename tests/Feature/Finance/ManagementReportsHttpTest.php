<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Modules\Finance\Application\FinanceAccess;
use App\Modules\Finance\Application\FrontOfficeRevenueConsumer;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\InventoryPurchasing\Application\InventoryCatalogService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-FIN-030, -031, -034: the management P&L per department, the cash flow summary with its balances, and the CSV exports. */
final class ManagementReportsHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $manager;

    private UserRecord $viewer;

    private UserRecord $exporter;

    /** @var array<string, string> */
    private array $account = [];

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
        $this->manager = $make([FinanceAccess::ACCOUNT_MANAGE, PropertySettingsService::MANAGE_PERMISSION, InventoryCatalogService::MANAGE_PERMISSION]);
        $this->viewer = $make([FinanceAccess::REPORT_VIEW]);
        $this->exporter = $make([FinanceAccess::EXPORT]);
        $this->actAs($this->manager);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-03', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();

        foreach ([['ELEC', 'Electricity', 'maintenance', 'utilities'], ['PAPER', 'Paper', 'front_office', 'supplies'], ['BEER', 'Beer', 'fnb', 'goods']] as [$code, $name, $department, $category]) {
            $this->account[$code] = (string) $this->postJson('/finance/accounts', ['code' => $code, 'name' => $name, 'department' => $department, 'category' => $category])->assertCreated()->json('account.id');
        }

        $this->bookRevenue();
        $this->bookCosts();
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

    private function bookRevenue(): void
    {
        DB::table('revenue_outlets')->insert(['id' => '01arz3ndektsv4rrffq69g5fb1', 'property_id' => self::A, 'code' => 'REST', 'name' => 'Restaurant', 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('revenue_outlet_sources')->insert(['property_id' => self::A, 'source' => 'pos_restaurant', 'outlet_id' => '01arz3ndektsv4rrffq69g5fb1', 'created_at' => now()]);
        $line = static fn (string $source, int $base): array => ['source' => $source, 'base_minor' => $base, 'service_charge_minor' => intdiv($base, 10), 'tax_minor' => intdiv($base * 11, 100), 'total_minor' => $base + intdiv($base, 10) + intdiv($base * 11, 100)];
        $ids = app(IdentifierGenerator::class);

        foreach (['2026-10-01' => '01arz3ndektsv4rrffq69g5fa1', '2026-10-02' => '01arz3ndektsv4rrffq69g5fa2', '2026-09-30' => '01arz3ndektsv4rrffq69g5fa3'] as $date => $audit) {
            $message = new OutboxMessage($ids->next(), new OutboxEvent(PropertyId::fromString(self::A), FrontOfficeRevenueConsumer::NIGHT_AUDIT_EVENT, $ids->next(), 1, [
                'night_audit_id' => $audit, 'business_date' => $date, 'currency' => 'IDR', 'actor_id' => (string) $this->manager->getKey(),
                'revenue_by_source' => [$line('night_audit', 10_000_000), $line('laundry', 500_000), $line('pos_restaurant', 2_000_000)],
                'payments' => [['method' => 'card', 'received_minor' => 5_000_000, 'paid_back_minor' => 0, 'count' => 2], ['method' => 'cash', 'received_minor' => 9_000_000, 'paid_back_minor' => 500_000, 'count' => 4]],
            ]), new DateTimeImmutable('now', new DateTimeZone('UTC')), $ids->next());
            DB::transaction(fn () => app(FrontOfficeRevenueConsumer::class)->consume($message));
        }
    }

    private function bookCosts(): void
    {
        $user = (string) $this->manager->getKey();
        $payable = function (string $number, string $issued, int $amount, int $tax, ?string $account) use ($user): string {
            $id = $this->ulid();
            DB::table('ap_payables')->insert(['id' => $id, 'property_id' => self::A, 'supplier_id' => $this->ulid(), 'supplier_code' => 'ABC', 'supplier_name' => 'ABC Supplier', 'source_type' => 'supplier_invoice', 'source_id' => $this->ulid(), 'source_number' => $number, 'document_number' => 'INV-'.$number,
                'issued_on' => $issued, 'due_date' => '2026-11-30', 'business_date' => $issued, 'amount_minor' => $amount, 'tax_minor' => $tax, 'currency' => 'IDR', 'expense_account_id' => $account, 'event_id' => $this->ulid(), 'actor_id' => $user, 'occurred_at' => now(), 'created_at' => now()]);

            return $id;
        };
        $p1 = $payable('SI-1', '2026-10-01', 3_330_000, 330_000, $this->account['ELEC']);
        $payable('SI-2', '2026-10-02', 1_110_000, 110_000, $this->account['BEER']);
        $payable('SI-3', '2026-10-02', 555_000, 55_000, null);
        $payable('SI-4', '2026-09-20', 999_000, 0, $this->account['ELEC']);

        foreach ([['PAY-1', 'transfer', 1_500_000, 'paid', '2026-10-02'], ['PAY-2', 'cash', 100_000, 'paid', '2026-10-02'], ['PAY-3', 'transfer', 999_000, 'pending_approval', '2026-10-02']] as [$number, $method, $amount, $status, $date]) {
            DB::table('ap_payments')->insert(['id' => $this->ulid(), 'property_id' => self::A, 'number' => $number, 'payable_id' => $p1, 'supplier_id' => $this->ulid(), 'amount_minor' => $amount, 'method' => $method, 'paid_on' => $date, 'reference' => $method === 'cash' ? null : 'TRF', 'status' => $status, 'created_by' => $user, 'business_date' => $date, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }

        $fund = $this->ulid();
        DB::table('fin_petty_funds')->insert(['id' => $fund, 'property_id' => self::A, 'code' => 'FO', 'name' => 'Front office', 'custodian_id' => $user, 'imprest_minor' => 200_000_000, 'currency' => 'IDR', 'is_active' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $voucher = function (string $number, string $date, int $amount, string $account, string $description = 'Supplies') use ($fund, $user): string {
            $id = $this->ulid();
            DB::table('fin_petty_vouchers')->insert(['id' => $id, 'property_id' => self::A, 'fund_id' => $fund, 'number' => $number, 'voucher_date' => $date, 'payee' => 'Toko', 'description' => $description, 'expense_account_id' => $account, 'amount_minor' => $amount, 'business_date' => $date, 'created_by' => $user, 'created_at' => now()]);

            return $id;
        };
        $voucher('PCV-1', '2026-10-01', 300_000, $this->account['PAPER'], '=HYPERLINK("http://x")');
        $voided = $voucher('PCV-2', '2026-10-01', 100_000, $this->account['PAPER']);
        DB::table('fin_petty_voids')->insert(['voucher_id' => $voided, 'property_id' => self::A, 'reason' => 'Wrong', 'voided_by' => $user, 'business_date' => '2026-10-01', 'created_at' => now()]);
        $voucher('PCV-3', '2026-10-02', 200_000, $this->account['ELEC']);

        $category = (string) $this->postJson('/inventory/categories', ['code' => 'BEV', 'name' => 'Beverages'])->assertCreated()->json('category.id');
        $item = (string) $this->postJson('/inventory/items', ['code' => 'WATER', 'name' => 'Water', 'category_id' => $category, 'department' => 'fnb', 'base_unit' => 'BTL'])->assertCreated()->json('item.id');
        $location = (string) $this->postJson('/inventory/locations', ['code' => 'MAIN', 'name' => 'Main store', 'kind' => 'main'])->assertCreated()->json('location.id');
        $move = fn (string $kind, int $qty, int $value, string $date) => DB::table('stock_movements')->insert([
            'id' => $this->ulid(), 'property_id' => self::A, 'item_id' => $item, 'location_id' => $location, 'kind' => $kind, 'reason_code' => in_array($kind, ['write_off', 'adjustment_in'], true) ? 'damage' : null, 'unit' => 'BTL', 'unit_qty_milli' => $qty, 'factor_milli' => 1000,
            'base_qty_milli' => $qty, 'value_minor' => $value, 'business_date' => $date, 'posted_by' => $user, 'created_at' => now()]);
        $move('opening', 1_000_000, 100_000_000, '2026-09-01');
        $move('issue', -10_000, -1_000_000, '2026-10-02');
        $move('write_off', -500, -50_000, '2026-10-02');
        $move('adjustment_in', 200, 20_000, '2026-10-02');
        $move('receipt', 5_000, 500_000, '2026-10-02');
        $move('issue', -10_000, -777_000, '2026-09-15');

        $customer = $this->ulid();
        DB::table('fin_customers')->insert(['id' => $customer, 'property_id' => self::A, 'code' => 'AGODA', 'name' => 'Agoda', 'kind' => 'ota', 'terms_days' => 14, 'is_active' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);

        foreach ([['manual', 'AR-1', 2_000_000], ['company_folio', 'AR-2', 700_000]] as [$source, $number, $amount]) {
            $id = $this->ulid();
            DB::table('ar_receivables')->insert(['id' => $id, 'property_id' => self::A, 'number' => $number, 'customer_id' => $customer, 'source_type' => $source, 'source_id' => $this->ulid(), 'source_number' => $number, 'description' => 'x', 'issued_on' => '2026-10-01', 'due_date' => '2026-10-15', 'business_date' => '2026-10-01',
                'amount_minor' => $amount, 'currency' => 'IDR', 'occurred_at' => now(), 'created_at' => now()]);
            DB::table('ar_receipts')->insert(['id' => $this->ulid(), 'property_id' => self::A, 'number' => 'RCP-'.$number, 'receivable_id' => $id, 'amount_minor' => $amount, 'method' => 'transfer', 'received_on' => '2026-10-02', 'reference' => 'TRF', 'business_date' => '2026-10-02', 'created_by' => $user, 'created_at' => now()]);
        }
    }

    public function test_the_pnl_is_a_management_report_per_department_from_what_finance_booked(): void
    {
        $this->actAs($this->viewer);
        $this->get('/finance/pnl?from=2026-10-01&to=2026-10-02')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('finance/pages/pnl')
            ->where('report.from', '2026-10-01')
            ->where('report.notes.unclassified_payables_minor', 500_000)
            ->where('report.notes.goods_payables_minor', 1_000_000)
            ->where('report.notes.unmapped_outlets', ['REST'])
            ->where('report.notes.unverified_days', 2)
            ->where('report.totals.revenue_minor', 20_000_000 + 1_000_000 + 4_000_000)
            ->where('report.totals.service_charge_minor', 2_500_000)
            ->where('report.totals.expenses_minor', 3_000_000)
            ->where('report.totals.petty_minor', 500_000)
            ->where('report.totals.stock_minor', 1_030_000)
            ->where('report.totals.result_minor', 25_000_000 - 4_530_000)
            ->has('report.departments', 5)
            ->where('report.departments.0.department', 'front_office')
            ->where('report.departments.0.revenue_minor', 20_000_000)
            ->where('report.departments.0.petty_minor', 300_000)
            ->where('report.departments.0.result_minor', 19_700_000)
            ->where('report.departments.0.margin_bp', 9_850)
            ->where('report.departments.0.accounts.0.code', 'PAPER')
            ->where('report.may.manage', false));

        $this->get('/finance/pnl?from=2026-10-02&to=2026-10-01')->assertStatus(422);
        $this->get('/finance/pnl?from=2025-01-01&to=2026-10-01')->assertStatus(422);
    }

    public function test_the_owner_maps_an_outlet_to_a_department_and_its_revenue_follows(): void
    {
        $this->actAs($this->viewer);
        $this->postJson('/finance/pnl/mappings', ['outlet_code' => 'REST', 'department' => 'fnb'])->assertForbidden();

        $this->actAs($this->manager);
        $this->postJson('/finance/pnl/mappings', ['outlet_code' => 'REST', 'department' => 'nowhere'])->assertStatus(422);
        $this->postJson('/finance/pnl/mappings', ['outlet_code' => 'bad code', 'department' => 'fnb'])->assertStatus(422);
        $this->postJson('/finance/pnl/mappings', ['outlet_code' => 'REST', 'department' => 'fnb'])->assertOk();
        $this->postJson('/finance/pnl/mappings', ['outlet_code' => 'REST', 'department' => 'fnb'])->assertOk();
        self::assertSame(1, DB::table('fin_pnl_outlet_map')->count());
        self::assertNotNull(DB::table('audit_entries')->where('action', 'pnl.outlet_mapped')->first());

        $this->get('/finance/pnl?from=2026-10-01&to=2026-10-02')->assertInertia(fn (Assert $page) => $page
            ->where('report.notes.unmapped_outlets', [])
            ->has('report.departments', 4)
            ->where('report.departments.1.department', 'laundry')
            ->where('report.departments.2.department', 'fnb')
            ->where('report.departments.2.revenue_minor', 4_000_000)
            ->where('report.departments.2.stock_minor', 1_030_000)
            ->where('report.departments.2.result_minor', 2_970_000)
            ->where('report.departments.3.department', 'maintenance')
            ->where('report.departments.3.result_minor', -3_200_000)
            ->where('report.mapping.0.mapped', true));
    }

    public function test_the_cash_flow_splits_what_came_in_and_went_out_between_cash_and_bank(): void
    {
        $this->actAs($this->viewer);
        $this->get('/finance/cashflow?from=2026-10-01&to=2026-10-02')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('finance/pages/cashflow')
            ->where('report.totals.in.cash', 17_000_000)
            ->where('report.totals.in.bank', 12_000_000)
            ->where('report.totals.out.cash', 600_000)
            ->where('report.totals.out.bank', 1_500_000)
            ->where('report.net.cash', 16_400_000)
            ->where('report.net.bank', 10_500_000)
            ->where('report.net.total', 26_900_000)
            ->where('report.balances', null)
            ->has('report.receipts', 3)
            ->has('report.payments', 3));
    }

    public function test_the_balances_are_the_opening_plus_what_moved_since_the_opening_date(): void
    {
        $this->actAs($this->viewer);
        $body = ['cash_opening_minor' => 5_000_000, 'bank_opening_minor' => 20_000_000, 'opening_date' => '2026-10-02', 'reason' => 'Start of the books'];
        $this->postJson('/finance/cashflow/opening', $body)->assertForbidden();

        $this->actAs($this->manager);
        $this->postJson('/finance/cashflow/opening', [...$body, 'reason' => ' '])->assertStatus(422);
        $this->postJson('/finance/cashflow/opening', [...$body, 'opening_date' => '2026-10-04'])->assertStatus(422);
        $this->postJson('/finance/cashflow/opening', $body)->assertOk();
        $this->postJson('/finance/cashflow/opening', $body)->assertStatus(409);
        $this->postJson('/finance/cashflow/opening', [...$body, 'lock_version' => 7])->assertStatus(409);

        $this->get('/finance/cashflow?from=2026-10-01&to=2026-10-02')->assertInertia(fn (Assert $page) => $page
            ->where('report.balances.reached', true)
            ->where('report.balances.cash.closing_minor', 5_000_000 + 8_200_000)
            ->where('report.balances.bank.closing_minor', 20_000_000 + 5_500_000)
            ->where('report.balances.lock_version', 0));
        $this->get('/finance/cashflow?from=2026-10-01&to=2026-10-01')->assertInertia(fn (Assert $page) => $page->where('report.balances.reached', false)->where('report.balances.cash.closing_minor', null));

        $this->postJson('/finance/cashflow/opening', [...$body, 'cash_opening_minor' => 6_000_000, 'lock_version' => 0, 'reason' => 'Counted again'])->assertOk();
        $this->get('/finance/cashflow?from=2026-10-01&to=2026-10-02')->assertInertia(fn (Assert $page) => $page->where('report.balances.cash.closing_minor', 6_000_000 + 8_200_000)->where('report.balances.lock_version', 1));
        self::assertSame(2, DB::table('audit_entries')->where('action', 'cash_opening.set')->count());
    }

    public function test_only_people_who_may_see_the_reports_see_them(): void
    {
        $this->actAs($this->exporter);
        $this->get('/finance/pnl')->assertForbidden();
        $this->get('/finance/cashflow')->assertForbidden();

        $nobody = UserRecord::factory()->create();
        $this->grant($nobody, self::A, []);
        $this->actAs($nobody);
        $this->get('/finance/pnl')->assertForbidden();
        $this->get('/finance/export')->assertForbidden();
    }

    public function test_exports_are_csv_with_money_in_major_units_and_safe_cells(): void
    {
        $this->actAs($this->viewer);
        $this->get('/finance/export/revenue')->assertForbidden();

        $this->actAs($this->exporter);
        $this->get('/finance/export')->assertOk()->assertInertia(fn (Assert $page) => $page->component('finance/pages/export')->has('overview.datasets', 9));
        $csv = $this->get('/finance/export/revenue?from=2026-10-01&to=2026-10-01')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $text = $csv->getContent();
        self::assertStringStartsWith("\xEF\xBB\xBF\"Business date\",\"Source\",\"Outlet code\",\"Outlet\",\"Base\",\"Service charge\",\"Tax\",\"Total\"", $text);
        self::assertStringContainsString('"2026-10-01","night_audit","rooms","","100000.00","10000.00","11000.00","121000.00"', $text);
        self::assertStringContainsString('"pos_restaurant","REST","Restaurant"', $text);
        self::assertStringNotContainsString('2026-10-02', $text);
        self::assertStringContainsString('attachment; filename="finance-revenue-2026-10-01-2026-10-01.csv"', (string) $csv->headers->get('Content-Disposition'));

        $petty = $this->get('/finance/export/petty_vouchers?from=2026-10-01&to=2026-10-02')->assertOk()->getContent();
        self::assertStringContainsString("\"'=HYPERLINK(\"\"http://x\"\")\"", $petty, 'a cell that starts like a formula is made harmless');
        self::assertStringContainsString('"yes","1000.00"', $this->rowOf($petty, 'PCV-2'));

        $payables = $this->get('/finance/export/payables?from=2026-10-01&to=2026-10-02')->assertOk()->getContent();
        self::assertStringContainsString('"SI-1"', $payables);
        self::assertStringContainsString('"33300.00","3300.00","16000.00","0.00","17300.00"', $payables);
        self::assertStringNotContainsString('SI-4', $payables);
        self::assertSame(3, DB::table('audit_entries')->where('action', 'finance.exported')->count());

        foreach (['payments_received', 'supplier_payments', 'receivables', 'receipts'] as $dataset) {
            $this->get("/finance/export/{$dataset}?from=2026-10-01&to=2026-10-02")->assertOk();
        }

        $this->get('/finance/export/ledger')->assertNotFound();
        $this->get('/finance/export/revenue?from=2025-01-01&to=2026-10-01')->assertStatus(422);
        $this->get('/finance/export/revenue?from=2026-10-05&to=2026-10-01')->assertStatus(422);
    }

    private function rowOf(string $csv, string $needle): string
    {
        foreach (explode("\r\n", $csv) as $line) {
            if (str_contains($line, $needle)) {
                return $line;
            }
        }

        return '';
    }
}
