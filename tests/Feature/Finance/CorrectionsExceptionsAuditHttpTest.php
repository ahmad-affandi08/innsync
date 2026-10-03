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
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-FIN-036, -019, -035: corrections to a booked day, reconciliation exceptions that hold the day, and the finance audit trail. */
final class CorrectionsExceptionsAuditHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $maker;

    private UserRecord $approver;

    private UserRecord $dual;

    private UserRecord $viewer;

    private UserRecord $auditor;

    private UserRecord $exporter;

    private UserRecord $reporter;

    private UserRecord $boss;

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
        $this->maker = $make([FinanceAccess::RECONCILE]);
        $this->approver = $make([FinanceAccess::CORRECTION_APPROVE, FinanceAccess::REVENUE_VIEW]);
        $this->dual = $make([FinanceAccess::RECONCILE, FinanceAccess::CORRECTION_APPROVE]);
        $this->viewer = $make([FinanceAccess::REVENUE_VIEW]);
        $this->auditor = $make([FinanceAccess::AUDIT_VIEW]);
        $this->exporter = $make([FinanceAccess::EXPORT]);
        $this->reporter = $make([FinanceAccess::REPORT_VIEW]);
        $this->actAs($this->boss);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-03', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->bookDay('2026-10-01', '01arz3ndektsv4rrffq69g5fa1');
    }

    private function actAs(UserRecord $user): void
    {
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->post('/properties/select', ['property_id' => self::A])->assertRedirect('/');
    }

    private function bookDay(string $date, string $audit): void
    {
        $line = static fn (string $source, int $base): array => ['source' => $source, 'base_minor' => $base, 'service_charge_minor' => intdiv($base, 10), 'tax_minor' => intdiv($base * 11, 100), 'total_minor' => $base + intdiv($base, 10) + intdiv($base * 11, 100)];
        $ids = app(IdentifierGenerator::class);
        $message = new OutboxMessage($ids->next(), new OutboxEvent(PropertyId::fromString(self::A), FrontOfficeRevenueConsumer::NIGHT_AUDIT_EVENT, $ids->next(), 1, [
            'night_audit_id' => $audit, 'business_date' => $date, 'currency' => 'IDR', 'actor_id' => (string) $this->boss->getKey(),
            'revenue_by_source' => [$line('night_audit', 10_000_000), $line('pos_restaurant', 2_000_000)],
            'payments' => [['method' => 'card', 'received_minor' => 5_000_000, 'paid_back_minor' => 0, 'count' => 2], ['method' => 'cash', 'received_minor' => 9_000_000, 'paid_back_minor' => 500_000, 'count' => 4]],
        ]), new DateTimeImmutable('now', new DateTimeZone('UTC')), $ids->next());
        DB::transaction(fn () => app(FrontOfficeRevenueConsumer::class)->consume($message));
    }

    /** @return array<string, string> */
    private function key(): array
    {
        return ['Idempotency-Key' => 'cx-'.(++$this->keys).'-'.str_repeat('x', 24)];
    }

    /** @return list<array<string, mixed>> overstated rooms revenue (without 1,000,000 base, 100,000 service charge and 110,000 tax), cash overstated by 500,000 and card understated by 200,000 */
    private function lines(): array
    {
        return [
            ['kind' => 'revenue', 'outlet_code' => 'rooms', 'base_minor' => -1_000_000, 'service_charge_minor' => -100_000, 'tax_minor' => -110_000],
            ['kind' => 'payment', 'method' => 'cash', 'received_minor' => -500_000],
            ['kind' => 'payment', 'method' => 'card', 'received_minor' => 200_000],
        ];
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function ask(array $extra = []): array
    {
        $this->actAs($this->maker);

        return $this->postJson('/finance/corrections', ['date' => '2026-10-01', 'reason' => 'Rooms charged twice for one guest', 'lines' => $this->lines(), ...$extra], $this->key())->assertCreated()->json('correction');
    }

    private function approve(string $id, int $lock = 0): void
    {
        $this->actAs($this->approver);
        $this->postJson("/finance/corrections/{$id}/decide", ['decision' => 'approve', 'lock_version' => $lock])->assertOk();
    }

    public function test_a_correction_to_a_verified_day_is_asked_for_with_its_reason_and_lines(): void
    {
        $this->actAs($this->maker);
        $this->postJson('/finance/revenue/2026-10-01/verify', [])->assertOk();

        $body = ['date' => '2026-10-01', 'reason' => 'Rooms charged twice', 'lines' => $this->lines()];
        $this->actAs($this->viewer);
        $this->postJson('/finance/corrections', $body, $this->key())->assertForbidden();

        $this->actAs($this->maker);
        $post = fn (array $change) => $this->postJson('/finance/corrections', [...$body, ...$change], $this->key());
        $post(['reason' => ' '])->assertStatus(422);
        $post(['lines' => []])->assertStatus(422);
        $post(['lines' => array_fill(0, 21, $this->lines()[0])])->assertStatus(422);
        $post(['date' => '2026-09-01'])->assertNotFound();
        $post(['lines' => [['kind' => 'tax']]])->assertStatus(422);
        $post(['lines' => [['kind' => 'revenue', 'outlet_code' => 'bad code!', 'base_minor' => 1]]])->assertStatus(422);
        $post(['lines' => [['kind' => 'revenue', 'outlet_code' => 'rooms', 'base_minor' => 100, 'service_charge_minor' => -100]]])->assertStatus(422);
        $post(['lines' => [['kind' => 'payment', 'method' => 'barter', 'received_minor' => 5]]])->assertStatus(422);
        $post(['lines' => [['kind' => 'payment', 'method' => 'cash', 'received_minor' => 0]]])->assertStatus(422);
        self::assertSame(0, DB::table('fin_corrections')->count());

        $headers = $this->key();
        $c = $this->postJson('/finance/corrections', $body, $headers)->assertCreated()->json('correction');
        $this->postJson('/finance/corrections', $body, $headers);
        self::assertSame(1, DB::table('fin_corrections')->count(), 'a retry with the same key asks once');
        self::assertSame(['COR-000001', 'pending', '2026-10-01', 3, -1_210_000, -300_000, null], [$c['number'], $c['status'], $c['day_date'], $c['line_count'], $c['revenue_minor'], $c['received_minor'], $c['effective_date']]);
        self::assertSame('rooms', $c['lines'][0]['outlet_code']);
        self::assertSame(['verified'], [DB::table('fin_revenue_days')->value('status')], 'the verified day is not touched');
        self::assertNotNull(DB::table('audit_entries')->where('action', 'correction.requested')->first());
    }

    public function test_a_different_person_approves_and_it_takes_effect_on_the_approval_date(): void
    {
        $c = $this->ask();
        $url = "/finance/corrections/{$c['id']}/decide";

        $this->actAs($this->maker);
        $this->postJson($url, ['decision' => 'approve', 'lock_version' => 0])->assertForbidden();
        $this->actAs($this->viewer);
        $this->postJson($url, ['decision' => 'approve', 'lock_version' => 0])->assertForbidden();

        $this->actAs($this->dual);
        $own = $this->postJson('/finance/corrections', ['date' => '2026-10-01', 'reason' => 'Own', 'lines' => [$this->lines()[0]]], $this->key())->assertCreated()->json('correction');
        $this->postJson("/finance/corrections/{$own['id']}/decide", ['decision' => 'approve', 'lock_version' => 0])->assertForbidden();

        $this->actAs($this->approver);
        $this->postJson($url, ['decision' => 'reject', 'lock_version' => 0])->assertStatus(422);
        $this->postJson($url, ['decision' => 'approve', 'lock_version' => 5])->assertStatus(409);
        $this->postJson($url, ['decision' => 'maybe', 'lock_version' => 0])->assertStatus(422);
        $done = $this->postJson($url, ['decision' => 'approve', 'note' => 'Checked the folio', 'lock_version' => 0])->assertOk()->json('correction');
        self::assertSame(['approved', '2026-10-03'], [$done['status'], $done['effective_date']]);
        $this->postJson($url, ['decision' => 'approve', 'lock_version' => 1])->assertStatus(409);

        $rejected = $this->postJson("/finance/corrections/{$own['id']}/decide", ['decision' => 'reject', 'note' => 'Not needed', 'lock_version' => 0])->assertOk()->json('correction');
        self::assertSame(['rejected', null], [$rejected['status'], $rejected['effective_date']]);
        self::assertNotNull(DB::table('audit_entries')->where('action', 'correction.approved')->first());
        self::assertNotNull(DB::table('outbox_messages')->where('event_type', 'finance.correction.approved')->first());

        foreach ([fn () => DB::table('fin_corrections')->update(['reason' => 'edited']), fn () => DB::table('fin_corrections')->where('status', 'approved')->update(['decision_note' => 'edited']), fn () => DB::table('fin_corrections')->delete(), fn () => DB::table('fin_correction_lines')->update(['base_minor' => 0]), fn () => DB::table('fin_correction_lines')->delete()] as $change) {
            try {
                $change();
                self::fail('A correction was changed.');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_an_approved_correction_is_in_the_reports_of_its_effective_period_and_the_booked_day_stays(): void
    {
        $c = $this->ask();
        $this->actAs($this->approver);
        $this->get('/finance/revenue?from=2026-10-01&to=2026-10-03')->assertInertia(fn (Assert $page) => $page->where('report.corrections.count', 0));
        $this->approve($c['id']);

        $this->actAs($this->approver);
        $this->get('/finance/revenue?from=2026-10-01&to=2026-10-03')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.totals.base_minor', 12_000_000)
            ->where('report.corrections.count', 1)
            ->where('report.corrections.totals.base_minor', -1_000_000)
            ->where('report.corrections.totals.total_minor', -1_210_000)
            ->where('report.corrections.totals.received_minor', -300_000)
            ->where('report.corrections.revenue.0.outlet_code', 'rooms'));
        $this->get('/finance/revenue?from=2026-10-01&to=2026-10-02')->assertInertia(fn (Assert $page) => $page->where('report.corrections.count', 0));
        $this->get('/finance/revenue/2026-10-01')->assertInertia(fn (Assert $page) => $page->where('day.total_minor', 14_520_000)->where('day.corrections.0.number', 'COR-000001')->where('day.corrections.0.status', 'approved')->where('day.corrections.0.effective_date', '2026-10-03'));

        $this->actAs($this->reporter);
        $this->get('/finance/pnl?from=2026-10-01&to=2026-10-03')->assertInertia(fn (Assert $page) => $page->where('report.totals.revenue_minor', 11_000_000)->where('report.totals.service_charge_minor', 1_100_000));
        $this->get('/finance/pnl?from=2026-10-01&to=2026-10-02')->assertInertia(fn (Assert $page) => $page->where('report.totals.revenue_minor', 12_000_000));
        $this->get('/finance/cashflow?from=2026-10-01&to=2026-10-03')->assertInertia(fn (Assert $page) => $page->where('report.totals.in.cash', 8_000_000)->where('report.totals.in.bank', 5_200_000));
        $this->get('/finance/cashflow?from=2026-10-01&to=2026-10-02')->assertInertia(fn (Assert $page) => $page->where('report.totals.in.cash', 8_500_000)->where('report.totals.in.bank', 5_000_000));

        $this->actAs($this->exporter);
        $csv = $this->get('/finance/export/corrections?from=2026-10-01&to=2026-10-03')->assertOk()->getContent();
        self::assertStringContainsString('"2026-10-03","COR-000001","2026-10-01","revenue","rooms","","-10000.00","-1000.00","-1100.00","-12100.00","0.00","Rooms charged twice for one guest"', $csv);
        self::assertStringContainsString('"payment","","cash","0.00","0.00","0.00","0.00","-5000.00"', $csv);
    }

    public function test_a_rejected_or_pending_correction_changes_no_report(): void
    {
        $c = $this->ask();
        $this->actAs($this->approver);
        $this->postJson("/finance/corrections/{$c['id']}/decide", ['decision' => 'reject', 'note' => 'No', 'lock_version' => 0])->assertOk();
        $this->actAs($this->reporter);
        $this->get('/finance/pnl?from=2026-10-01&to=2026-10-03')->assertInertia(fn (Assert $page) => $page->where('report.totals.revenue_minor', 12_000_000));

        $this->actAs($this->viewer);
        $this->get('/finance/corrections')->assertOk()->assertInertia(fn (Assert $page) => $page->component('finance/pages/corrections')->has('overview.corrections', 1)->where('overview.may.request', false)->where('overview.outlets.0.code', 'other'));
        $this->get('/finance/corrections?status=nope')->assertStatus(422);
        $this->get("/finance/corrections/{$c['id']}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('finance/pages/correction')->where('correction.status', 'rejected')->where('correction.may_decide', false));
        $nobody = UserRecord::factory()->create();
        $this->grant($nobody, self::A, []);
        $this->actAs($nobody);
        $this->get('/finance/corrections')->assertForbidden();
        $this->get('/finance/exceptions')->assertForbidden();
    }

    public function test_exceptions_are_raised_and_reconciled_by_someone_else_without_editing_the_origin(): void
    {
        $this->actAs($this->viewer);
        $body = ['kind' => 'chargeback', 'business_date' => '2026-10-01', 'amount_minor' => 750_000, 'method' => 'card', 'reference' => 'CB-4411', 'folio_ref' => 'FOL-000123', 'description' => 'Guest disputed the room charge with the bank'];
        $this->postJson('/finance/exceptions', $body, $this->key())->assertForbidden();

        $this->actAs($this->maker);
        $post = fn (array $change) => $this->postJson('/finance/exceptions', [...$body, ...$change], $this->key());
        $post(['kind' => 'gift'])->assertStatus(422);
        $post(['amount_minor' => 0])->assertStatus(422);
        $post(['method' => 'barter'])->assertStatus(422);
        $post(['reference' => 'bad ref <script>'])->assertStatus(422);
        $post(['business_date' => '2026-10-04'])->assertStatus(422);
        $post(['description' => '  '])->assertStatus(422);
        self::assertSame(0, DB::table('fin_exceptions')->count());

        $headers = $this->key();
        $e = $this->postJson('/finance/exceptions', $body, $headers)->assertCreated()->json('exception');
        $this->postJson('/finance/exceptions', $body, $headers);
        self::assertSame(1, DB::table('fin_exceptions')->count());
        self::assertSame(['EXC-000001', 'open', 'chargeback', 750_000, false], [$e['number'], $e['status'], $e['kind'], $e['amount_minor'], $e['may_reconcile']]);

        $url = "/finance/exceptions/{$e['id']}/reconcile";
        $this->postJson($url, ['status' => 'waived', 'resolution' => 'Own', 'lock_version' => 0])->assertForbidden();
        $this->actAs($this->dual);
        $this->postJson($url, ['status' => 'lost', 'resolution' => 'x', 'lock_version' => 0])->assertStatus(422);
        $this->postJson($url, ['status' => 'matched', 'resolution' => ' ', 'lock_version' => 0])->assertStatus(422);
        $this->postJson($url, ['status' => 'adjusted', 'resolution' => 'Adjusted', 'lock_version' => 0])->assertStatus(422);
        $this->postJson($url, ['status' => 'adjusted', 'resolution' => 'Adjusted', 'correction_number' => 'COR-999999', 'lock_version' => 0])->assertStatus(422);
        $this->postJson($url, ['status' => 'matched', 'resolution' => 'Matched to the bank statement of 4 October, reference CB-4411', 'lock_version' => 3])->assertStatus(409);
        $done = $this->postJson($url, ['status' => 'matched', 'resolution' => 'Matched to the bank statement of 4 October, reference CB-4411', 'lock_version' => 0])->assertOk()->json('exception');
        self::assertSame(['matched', false], [$done['status'], $done['may_reconcile']]);
        $this->postJson($url, ['status' => 'waived', 'resolution' => 'again', 'lock_version' => 1])->assertStatus(409);

        foreach ([fn () => DB::table('fin_exceptions')->update(['amount_minor' => 1]), fn () => DB::table('fin_exceptions')->update(['resolution' => 'edited']), fn () => DB::table('fin_exceptions')->delete()] as $change) {
            try {
                $change();
                self::fail('An exception was changed.');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        }

        self::assertSame(1, (int) DB::table('fin_revenue_days')->count(), 'the day the exception belongs to was never edited');
    }

    public function test_an_exception_can_be_adjusted_by_a_correction_and_holds_the_day_until_it_is_reconciled(): void
    {
        $this->actAs($this->maker);
        $e = $this->postJson('/finance/exceptions', ['kind' => 'refund', 'business_date' => '2026-10-01', 'amount_minor' => 300_000, 'description' => 'Refund paid in cash at the desk, not in the folio'], $this->key())->assertCreated()->json('exception');

        $this->postJson('/finance/revenue/2026-10-01/verify', [])->assertStatus(409);
        $this->get('/finance/revenue/2026-10-01')->assertInertia(fn (Assert $page) => $page->where('day.blockers.exceptions', 1)->where('day.may_verify', false));

        $c = $this->ask(['lines' => [['kind' => 'payment', 'method' => 'cash', 'received_minor' => -300_000]]]);
        $this->actAs($this->dual);
        $adjusted = $this->postJson("/finance/exceptions/{$e['id']}/reconcile", ['status' => 'adjusted', 'resolution' => 'Booked as a negative cash payment', 'correction_number' => $c['number'], 'lock_version' => 0])->assertOk()->json('exception');
        self::assertSame(['adjusted', 'COR-000001'], [$adjusted['status'], $adjusted['correction_number']]);

        $this->get('/finance/exceptions?status=adjusted')->assertOk()->assertInertia(fn (Assert $page) => $page->component('finance/pages/exceptions')->has('overview.exceptions', 1)->where('overview.open_count', 0));
        $this->get('/finance/exceptions?status=nope')->assertStatus(422);
        $this->postJson('/finance/revenue/2026-10-01/verify', [])->assertOk()->assertJsonPath('day.status', 'verified');
    }

    public function test_the_dashboard_warns_of_open_exceptions(): void
    {
        $this->actAs($this->maker);
        $this->postJson('/finance/exceptions', ['kind' => 'unknown_payment', 'amount_minor' => 100_000, 'description' => 'Transfer of unknown origin'], $this->key())->assertCreated();
        $this->actAs($this->boss);
        $alerts = collect($this->get('/dashboard')->viewData('page')['props']['snapshot']['alerts'])->keyBy('code');

        self::assertSame(1, $alerts->get('finance_exceptions_open')['count']);
        self::assertSame('EXC-000001', $alerts->get('finance_exceptions_open')['items'][0]);
        self::assertSame('/finance/exceptions', $alerts->get('finance_exceptions_open')['href']);
    }

    public function test_the_audit_trail_shows_who_changed_which_figure_with_the_figures_and_the_reason(): void
    {
        $c = $this->ask();
        $this->approve($c['id']);
        $this->actAs($this->maker);
        $this->postJson('/finance/exceptions', ['kind' => 'refund', 'amount_minor' => 1_000, 'description' => 'Small refund'], $this->key())->assertCreated();

        $this->actAs($this->viewer);
        $this->get('/finance/audit')->assertForbidden();

        $this->actAs($this->auditor);
        $this->get('/finance/audit?from=2026-10-01&to=2026-10-31')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('finance/pages/audit')
            ->where('trail.group', 'finance')
            ->where('trail.total', 3)
            ->where('trail.rows.0.action', 'fin_exception.raised')
            ->where('trail.rows.1.action', 'correction.approved')
            ->where('trail.rows.1.after.effective_date', '2026-10-03')
            ->where('trail.rows.2.action', 'correction.requested')
            ->where('trail.rows.2.reason', 'Rooms charged twice for one guest')
            ->where('trail.rows.2.after.revenue_minor', -1_210_000));
        $this->get('/finance/audit?from=2026-10-01&to=2026-10-31&action=correction.requested')->assertInertia(fn (Assert $page) => $page->where('trail.total', 1));
        $this->get('/finance/audit?from=2026-10-01&to=2026-10-31&user='.$this->maker->getKey())->assertInertia(fn (Assert $page) => $page->where('trail.total', 2));
        $this->get('/finance/audit?group=front_office')->assertInertia(fn (Assert $page) => $page->where('trail.total', 0));
        $this->get('/finance/audit?group=everything')->assertStatus(422);
        $this->get('/finance/audit?from=2026-01-01&to=2026-10-03')->assertStatus(422);
        $this->get('/finance/audit?action=Bad%20Action')->assertStatus(422);
    }
}
