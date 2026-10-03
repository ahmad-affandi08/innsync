<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Modules\Finance\Application\FinanceAccess;
use App\Modules\Finance\Application\FrontOfficeRevenueConsumer;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Modules\Reporting\Application\DashboardService;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-FIN-016, -017: recurring expenses with their due dates and reminders, department budgets and the budget against actual report. */
final class RecurringAndBudgetHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $manager;

    private UserRecord $viewer;

    private UserRecord $budgeter;

    private string $electricity;

    private string $paper;

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
        $this->manager = $make([FinanceAccess::RECURRING_MANAGE, FinanceAccess::ACCOUNT_MANAGE, PropertySettingsService::MANAGE_PERMISSION, DashboardService::VIEW_PERMISSION]);
        $this->viewer = $make([FinanceAccess::RECURRING_VIEW, FinanceAccess::REPORT_VIEW]);
        $this->budgeter = $make([FinanceAccess::BUDGET_MANAGE, FinanceAccess::REPORT_VIEW]);
        $this->actAs($this->manager);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-03', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->electricity = (string) $this->postJson('/finance/accounts', ['code' => 'ELEC', 'name' => 'Electricity', 'department' => 'maintenance', 'category' => 'utilities'])->assertCreated()->json('account.id');
        $this->paper = (string) $this->postJson('/finance/accounts', ['code' => 'PAPER', 'name' => 'Paper', 'department' => 'front_office', 'category' => 'supplies'])->assertCreated()->json('account.id');
    }

    private function actAs(UserRecord $user): void
    {
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->post('/properties/select', ['property_id' => self::A])->assertRedirect('/');
    }

    /** @return array<string, string> */
    private function key(): array
    {
        return ['Idempotency-Key' => 'rc-'.(++$this->keys).'-'.str_repeat('x', 24)];
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function item(array $extra = []): array
    {
        return $this->postJson('/finance/recurring', ['name' => 'Electricity', 'expense_account_id' => $this->electricity, 'payee' => 'PLN', 'amount_minor' => 15_000_000, 'frequency' => 'monthly', 'due_day' => 25, 'start_month' => '2026-09', ...$extra])->assertCreated()->json('item');
    }

    /** @param array<string, mixed> $body @return \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse> */
    private function settle(string $id, array $body)
    {
        return $this->postJson("/finance/recurring/{$id}/settle", $body, $this->key());
    }

    public function test_a_recurring_expense_is_made_with_a_schedule_that_follows_the_calendar(): void
    {
        $body = ['name' => 'Rent', 'expense_account_id' => $this->electricity, 'amount_minor' => 1_000_000, 'frequency' => 'monthly', 'due_day' => 5, 'start_month' => '2026-10'];
        $this->actAs($this->viewer);
        $this->postJson('/finance/recurring', $body)->assertForbidden();

        $this->actAs($this->manager);
        $this->postJson('/finance/recurring', [...$body, 'amount_minor' => 0])->assertStatus(422);
        $this->postJson('/finance/recurring', [...$body, 'frequency' => 'weekly'])->assertStatus(422);
        $this->postJson('/finance/recurring', [...$body, 'due_day' => 32])->assertStatus(422);
        $this->postJson('/finance/recurring', [...$body, 'start_month' => '2026-13'])->assertStatus(422);
        $this->postJson('/finance/recurring', [...$body, 'start_month' => 'October'])->assertStatus(422);
        $this->postJson('/finance/recurring', [...$body, 'end_date' => '2026-10-01'])->assertStatus(422);
        $this->postJson('/finance/recurring', [...$body, 'expense_account_id' => str_repeat('0', 26)])->assertStatus(422);
        $this->postJson('/finance/recurring', [...$body, 'remind_days' => 61])->assertStatus(422);
        self::assertSame(0, DB::table('fin_recurring_expenses')->count());

        $month = $this->item(['name' => 'Water', 'due_day' => 31, 'start_month' => '2026-02']);
        self::assertSame('2026-02-28', $month['next_due'], 'a day past the end of a short month falls on its last day');
        self::assertSame(['2026-02-28', '2026-03-31', '2026-04-30'], $month['upcoming']);

        $quarter = $this->item(['name' => 'Insurance', 'frequency' => 'quarterly', 'due_day' => 15, 'start_month' => '2026-11']);
        self::assertSame(['2026-11-15', '2027-02-15', '2027-05-15'], $quarter['upcoming']);
        $year = $this->item(['name' => 'Licence', 'frequency' => 'yearly', 'due_day' => 1, 'start_month' => '2026-12']);
        self::assertSame(['2026-12-01', '2027-12-01', '2028-12-01'], $year['upcoming']);
        self::assertNotNull(DB::table('audit_entries')->where('action', 'recurring_expense.created')->first());

        foreach ([fn () => DB::table('fin_recurring_expenses')->update(['frequency' => 'yearly']), fn () => DB::table('fin_recurring_expenses')->update(['due_day' => 2]), fn () => DB::table('fin_recurring_expenses')->update(['next_due' => '2020-01-01']), fn () => DB::table('fin_recurring_expenses')->delete()] as $change) {
            try {
                $change();
                self::fail('A schedule was changed.');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_the_state_is_overdue_due_soon_or_upcoming_by_the_days_of_notice(): void
    {
        $this->item(['name' => 'Late', 'due_day' => 25, 'start_month' => '2026-09']);
        $this->item(['name' => 'Soon', 'due_day' => 8, 'start_month' => '2026-10']);
        $this->item(['name' => 'Later', 'due_day' => 20, 'start_month' => '2026-10']);
        $this->item(['name' => 'Early notice', 'due_day' => 20, 'start_month' => '2026-10', 'remind_days' => 30]);

        $this->actAs($this->viewer);
        $this->get('/finance/recurring')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('finance/pages/recurring')
            ->where('overview.overdue_count', 1)
            ->where('overview.due_soon_count', 2)
            ->where('overview.items.0.name', 'Late')
            ->where('overview.items.0.state', 'overdue')
            ->where('overview.items.0.days_overdue', 8)
            ->where('overview.items.1.state', 'due_soon')
            ->where('overview.items.1.days_to_due', 5)
            ->where('overview.may.manage', false)
            ->where('overview.expected_30_days_minor', 15_000_000 * 5));

        $nobody = UserRecord::factory()->create();
        $this->grant($nobody, self::A, []);
        $this->actAs($nobody);
        $this->get('/finance/recurring')->assertForbidden();
    }

    public function test_a_due_date_is_paid_or_skipped_in_order_and_the_next_one_follows(): void
    {
        $item = $this->item();
        $id = $item['id'];
        $this->actAs($this->viewer);
        $this->settle($id, ['action' => 'skipped', 'note' => 'x'])->assertForbidden();

        $this->actAs($this->manager);
        $this->settle($id, ['action' => 'later'])->assertStatus(422);
        $this->settle($id, ['action' => 'paid'])->assertStatus(422);
        $this->settle($id, ['action' => 'paid', 'method' => 'transfer'])->assertStatus(422);
        $this->settle($id, ['action' => 'paid', 'method' => 'cash', 'paid_on' => '2026-10-04'])->assertStatus(422);
        $this->settle($id, ['action' => 'paid', 'method' => 'cash', 'paid_on' => '2026-08-30'])->assertStatus(422);
        $this->settle($id, ['action' => 'skipped'])->assertStatus(422);
        self::assertSame(0, DB::table('fin_recurring_occurrences')->count());

        $headers = $this->key();
        $body = ['action' => 'paid', 'method' => 'transfer', 'reference' => 'TRF-9', 'amount_minor' => 16_000_000, 'paid_on' => '2026-10-01'];
        $paid = $this->postJson("/finance/recurring/{$id}/settle", $body, $headers)->assertCreated()->json('item');
        $this->postJson("/finance/recurring/{$id}/settle", $body, $headers);
        self::assertSame(1, DB::table('fin_recurring_occurrences')->count(), 'a retry with the same key settles once');
        self::assertSame(['2026-10-25', 'upcoming'], [$paid['next_due'], $paid['state']]);
        self::assertSame(['2026-09-25', 'paid', 16_000_000, 'transfer'], [$paid['history'][0]['due_date'], $paid['history'][0]['status'], $paid['history'][0]['amount_minor'], $paid['history'][0]['method']]);

        $skipped = $this->settle($id, ['action' => 'skipped', 'note' => 'Billed with the rent this month'])->assertCreated()->json('item');
        self::assertSame(['2026-11-25', 'skipped', 'Billed with the rent this month'], [$skipped['next_due'], $skipped['history'][0]['status'], $skipped['history'][0]['note']]);

        $default = $this->settle($id, ['action' => 'paid', 'method' => 'cash'])->assertCreated()->json('item');
        self::assertSame([15_000_000, '2026-10-03', '2026-12-25'], [$default['history'][0]['amount_minor'], $default['history'][0]['paid_on'], $default['next_due']]);
        self::assertNotNull(DB::table('audit_entries')->where('action', 'recurring_expense.skipped')->first());
        self::assertSame(2, DB::table('outbox_messages')->where('event_type', 'finance.recurring.paid')->count());

        foreach ([fn () => DB::table('fin_recurring_occurrences')->update(['amount_minor' => 1]), fn () => DB::table('fin_recurring_occurrences')->delete()] as $change) {
            try {
                $change();
                self::fail('A settled due date was changed.');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_an_expense_can_end_and_can_be_paused_and_changed(): void
    {
        $ended = $this->item(['name' => 'Fixed term', 'start_month' => '2026-10', 'due_day' => 15, 'end_date' => '2026-10-31']);
        $done = $this->settle($ended['id'], ['action' => 'paid', 'method' => 'cash'])->assertCreated()->json('item');
        self::assertSame(['finished', null], [$done['state'], $done['next_due']]);
        $this->settle($ended['id'], ['action' => 'paid', 'method' => 'cash'])->assertStatus(409);

        $item = $this->item();
        $url = "/finance/recurring/{$item['id']}";
        $body = ['name' => 'Electricity and gas', 'expense_account_id' => $this->paper, 'payee' => 'PLN', 'amount_minor' => 17_000_000, 'remind_days' => 3, 'end_date' => null, 'active' => true, 'lock_version' => 0];
        $this->postJson($url, [...$body, 'lock_version' => 9])->assertStatus(409);
        $this->postJson($url, [...$body, 'amount_minor' => 0])->assertStatus(422);
        $this->postJson($url, [...$body, 'expense_account_id' => str_repeat('0', 26)])->assertStatus(422);
        $changed = $this->postJson($url, $body)->assertOk()->json('item');
        self::assertSame(['Electricity and gas', 'PAPER', 17_000_000, 3, 1], [$changed['name'], $changed['account_code'], $changed['amount_minor'], $changed['remind_days'], $changed['lock_version']]);
        self::assertNotNull(DB::table('audit_entries')->where('action', 'recurring_expense.updated')->first());

        $paused = $this->postJson($url, [...$body, 'active' => false, 'lock_version' => 1])->assertOk()->json('item');
        self::assertSame('paused', $paused['state']);
        $this->settle($item['id'], ['action' => 'paid', 'method' => 'cash'])->assertStatus(409);
        $this->get("/finance/recurring/{$item['id']}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('finance/pages/recurring-detail')->where('item.state', 'paused')->has('item.history', 0)->where('item.may.manage', true));
    }

    public function test_the_dashboard_reminds_of_what_is_overdue_and_what_falls_due(): void
    {
        $late = $this->item(['name' => 'Late rent', 'start_month' => '2026-09', 'due_day' => 25]);
        $this->item(['name' => 'Water soon', 'start_month' => '2026-10', 'due_day' => 8]);
        $alerts = fn () => collect($this->get('/dashboard')->viewData('page')['props']['snapshot']['alerts'])->keyBy('code');

        self::assertSame(1, $alerts()->get('recurring_overdue')['count']);
        self::assertSame('Late rent', $alerts()->get('recurring_overdue')['items'][0]);
        self::assertSame('/finance/recurring', $alerts()->get('recurring_overdue')['href']);
        self::assertSame(1, $alerts()->get('recurring_due_soon')['count']);

        $this->settle($late['id'], ['action' => 'paid', 'method' => 'cash'])->assertCreated();
        self::assertNull($alerts()->get('recurring_overdue'), 'once settled it is no longer an alert');
    }

    public function test_paid_recurring_expenses_are_costs_of_their_department_and_cash_paid_out(): void
    {
        $item = $this->item(['start_month' => '2026-10', 'due_day' => 1]);
        $this->settle($item['id'], ['action' => 'paid', 'method' => 'transfer', 'reference' => 'TRF-1', 'amount_minor' => 4_000_000, 'paid_on' => '2026-10-02'])->assertCreated();
        $this->settle($item['id'], ['action' => 'skipped', 'note' => 'None this month'])->assertCreated();
        $this->actAs($this->viewer);

        $this->get('/finance/pnl?from=2026-10-01&to=2026-10-31')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.totals.recurring_minor', 4_000_000)
            ->where('report.totals.cost_total_minor', 4_000_000)
            ->where('report.departments.0.department', 'maintenance')
            ->where('report.departments.0.recurring_minor', 4_000_000)
            ->where('report.departments.0.accounts.0.recurring_minor', 4_000_000));
        $this->get('/finance/pnl?from=2026-09-01&to=2026-09-30')->assertInertia(fn (Assert $page) => $page->where('report.totals.recurring_minor', 0));
        $this->get('/finance/cashflow?from=2026-10-01&to=2026-10-31')->assertInertia(fn (Assert $page) => $page->where('report.totals.out.bank', 4_000_000)->where('report.payments.0.source', 'recurring'));
    }

    public function test_budgets_are_set_per_department_and_month_with_a_reason(): void
    {
        $year = fn (array $m) => ['year' => 2026, 'department' => 'front_office', 'reason' => 'Annual plan', 'months' => $m];
        $this->actAs($this->viewer);
        $this->postJson('/finance/budget', $year([['month' => 10, 'revenue_minor' => 1, 'cost_minor' => 1]]))->assertForbidden();

        $this->actAs($this->budgeter);
        $this->postJson('/finance/budget', [...$year([['month' => 10, 'revenue_minor' => 1, 'cost_minor' => 1]]), 'department' => 'nowhere'])->assertStatus(422);
        $this->postJson('/finance/budget', [...$year([['month' => 10, 'revenue_minor' => 1, 'cost_minor' => 1]]), 'reason' => ' '])->assertStatus(422);
        $this->postJson('/finance/budget', $year([['month' => 13, 'revenue_minor' => 1, 'cost_minor' => 1]]))->assertStatus(422);
        $this->postJson('/finance/budget', $year([['month' => 10, 'revenue_minor' => -1, 'cost_minor' => 1]]))->assertStatus(422);
        $this->postJson('/finance/budget', $year([['month' => 10, 'revenue_minor' => 1, 'cost_minor' => 1], ['month' => 10, 'revenue_minor' => 2, 'cost_minor' => 2]]))->assertStatus(422);
        $this->postJson('/finance/budget', $year([]))->assertStatus(422);
        self::assertSame(0, DB::table('fin_budgets')->count());

        $this->postJson('/finance/budget', $year([['month' => 9, 'revenue_minor' => 15_000_000, 'cost_minor' => 1_000_000], ['month' => 10, 'revenue_minor' => 20_000_000, 'cost_minor' => 2_000_000]]))->assertOk()->assertJsonPath('budget.rows.1.revenue_minor', 20_000_000);
        $this->postJson('/finance/budget', $year([['month' => 10, 'revenue_minor' => 22_000_000, 'cost_minor' => 2_000_000]]))->assertOk();
        self::assertSame(2, DB::table('fin_budgets')->count());
        self::assertSame(2, DB::table('audit_entries')->where('action', 'budget.set')->count());
        $second = json_decode((string) DB::table('audit_entries')->where('action', 'budget.set')->orderByDesc('id')->value('before_state'), true);
        self::assertSame(20_000_000, $second['months'][10]['revenue_minor'], 'the audit keeps the figures before the change');

        $this->get('/finance/budget?year=2026')->assertOk()->assertInertia(fn (Assert $page) => $page->component('finance/pages/budget')->where('budget.year', 2026)->has('budget.rows', 2)->where('budget.may.manage', true));
        $this->get('/finance/budget?year=1999')->assertSessionHasErrors('year');
    }

    public function test_the_budget_report_sets_the_budget_against_the_management_pnl(): void
    {
        $ids = app(IdentifierGenerator::class);
        $message = new OutboxMessage($ids->next(), new OutboxEvent(PropertyId::fromString(self::A), FrontOfficeRevenueConsumer::NIGHT_AUDIT_EVENT, $ids->next(), 1, [
            'night_audit_id' => '01arz3ndektsv4rrffq69g5fa1', 'business_date' => '2026-10-01', 'currency' => 'IDR', 'actor_id' => (string) $this->manager->getKey(),
            'revenue_by_source' => [['source' => 'night_audit', 'base_minor' => 10_000_000, 'service_charge_minor' => 1_000_000, 'tax_minor' => 1_100_000, 'total_minor' => 12_100_000]], 'payments' => [],
        ]), new DateTimeImmutable('now', new DateTimeZone('UTC')), $ids->next());
        DB::transaction(fn () => app(FrontOfficeRevenueConsumer::class)->consume($message));
        $fund = strtolower((string) Str::ulid());
        $user = (string) $this->manager->getKey();
        DB::table('fin_petty_funds')->insert(['id' => $fund, 'property_id' => self::A, 'code' => 'FO', 'name' => 'FO', 'custodian_id' => $user, 'imprest_minor' => 100_000_000, 'currency' => 'IDR', 'is_active' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('fin_petty_vouchers')->insert(['id' => strtolower((string) Str::ulid()), 'property_id' => self::A, 'fund_id' => $fund, 'number' => 'PCV-1', 'voucher_date' => '2026-10-02', 'payee' => 'Toko', 'description' => 'Paper', 'expense_account_id' => $this->paper, 'amount_minor' => 300_000, 'business_date' => '2026-10-02', 'created_by' => $user, 'created_at' => now()]);

        $this->actAs($this->budgeter);
        $this->postJson('/finance/budget', ['year' => 2026, 'department' => 'front_office', 'reason' => 'Plan', 'months' => [['month' => 10, 'revenue_minor' => 20_000_000, 'cost_minor' => 2_000_000]]])->assertOk();
        $this->postJson('/finance/budget', ['year' => 2026, 'department' => 'fnb', 'reason' => 'Plan', 'months' => [['month' => 10, 'revenue_minor' => 5_000_000, 'cost_minor' => 1_000_000]]])->assertOk();

        $this->get('/finance/budget/report?from=2026-10&to=2026-10')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('finance/pages/budget-report')
            ->where('report.from', '2026-10')
            ->has('report.departments', 2)
            ->where('report.departments.0.department', 'front_office')
            ->where('report.departments.0.budget_revenue_minor', 20_000_000)
            ->where('report.departments.0.actual_revenue_minor', 10_000_000)
            ->where('report.departments.0.revenue_variance_minor', -10_000_000)
            ->where('report.departments.0.revenue_used_bp', 5_000)
            ->where('report.departments.0.actual_cost_minor', 300_000)
            ->where('report.departments.0.cost_variance_minor', -1_700_000)
            ->where('report.departments.0.cost_used_bp', 1_500)
            ->where('report.departments.0.result_variance_minor', -10_000_000 + 1_700_000)
            ->where('report.departments.1.department', 'fnb')
            ->where('report.departments.1.actual_revenue_minor', 0)
            ->where('report.departments.1.revenue_used_bp', 0)
            ->where('report.totals.budget_revenue_minor', 25_000_000)
            ->where('report.totals.actual_revenue_minor', 10_000_000)
            ->has('report.monthly', 1)
            ->where('report.monthly.0.budget_cost_minor', 3_000_000)
            ->where('report.notes.unverified_days', 1));

        $this->get('/finance/budget/report?from=2026-01&to=2026-12')->assertOk()->assertInertia(fn (Assert $page) => $page->has('report.monthly', 12));
        $this->get('/finance/budget/report?from=2025-12&to=2026-12')->assertStatus(422);
        $this->get('/finance/budget/report?from=2026-10&to=2026-09')->assertStatus(422);
        $this->get('/finance/budget/report?from=2026-13&to=2026-13')->assertStatus(422);

        $nobody = UserRecord::factory()->create();
        $this->grant($nobody, self::A, []);
        $this->actAs($nobody);
        $this->get('/finance/budget/report')->assertForbidden();
        $this->get('/finance/budget')->assertForbidden();
    }
}
