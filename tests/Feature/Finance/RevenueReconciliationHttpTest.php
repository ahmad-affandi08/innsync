<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Modules\Finance\Application\FinanceAccess;
use App\Modules\Finance\Application\FrontOfficeRevenueConsumer;
use App\Modules\Property\Application\Settings\PropertySettingsService;
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

/** FR-FIN-001, -002, -003, -005, -006, -037: revenue booked from the night audit, the reports, the cash of closed shifts received, exceptions and the verified day. */
final class RevenueReconciliationHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private string $cashier;

    private string $reconciler;

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
        $this->cashier = $this->as([PropertySettingsService::MANAGE_PERMISSION]);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-03', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->reconciler = $this->as([FinanceAccess::RECONCILE]);
    }

    /** @param list<string> $permissions */
    private function as(array $permissions): string
    {
        $this->post('/logout');

        return (string) $this->signIn(self::A, $permissions)->getKey();
    }

    /** @param array<string, mixed> $data */
    private function deliver(string $type, array $data): void
    {
        $ids = app(IdentifierGenerator::class);
        $message = new OutboxMessage($ids->next(), new OutboxEvent(PropertyId::fromString(self::A), $type, $ids->next(), 1, $data), new DateTimeImmutable('now', new DateTimeZone('UTC')), $ids->next());
        DB::transaction(fn () => app(FrontOfficeRevenueConsumer::class)->consume($message));
    }

    /** A night audit of a day: 10,000,000 of rooms and 2,000,000 of restaurant, each with 10% service and 11% tax on the base, paid in cash and by card. */
    private function audited(string $date, string $auditId = '01arz3ndektsv4rrffq69g5fa1', int $rooms = 10_000_000): void
    {
        $line = static fn (string $source, int $base): array => ['source' => $source, 'base_minor' => $base, 'service_charge_minor' => intdiv($base, 10), 'tax_minor' => intdiv($base * 11, 100), 'total_minor' => $base + intdiv($base, 10) + intdiv($base * 11, 100)];

        $this->deliver(FrontOfficeRevenueConsumer::NIGHT_AUDIT_EVENT, [
            'night_audit_id' => $auditId, 'business_date' => $date, 'next_business_date' => date('Y-m-d', strtotime($date.' +1 day')), 'currency' => 'IDR', 'actor_id' => $this->cashier,
            'revenue_by_source' => [$line('night_audit', $rooms), $line('pos_restaurant', 2_000_000), $line('minibar', 100_000)],
            'payments' => [['method' => 'card', 'received_minor' => 5_000_000, 'paid_back_minor' => 0, 'count' => 2], ['method' => 'cash', 'received_minor' => 9_000_000, 'paid_back_minor' => 500_000, 'count' => 4]],
        ]);
    }

    private function shift(string $shiftId, string $cashier, int $cashNet, int $counted = 0, int $float = 1_000_000, int $drops = 0, string $date = '2026-10-03'): void
    {
        $this->deliver(FrontOfficeRevenueConsumer::SHIFT_EVENT, [
            'shift_id' => $shiftId, 'number' => 'SHF-'.substr($shiftId, -6), 'cashier_id' => $cashier, 'actor_id' => $cashier, 'closed_business_date' => $date, 'currency' => 'IDR', 'opening_float_minor' => $float,
            'expected_cash_minor' => $float + $cashNet - $drops, 'counted_cash_minor' => $counted === 0 ? $float + $cashNet - $drops : $counted, 'variance_minor' => $counted === 0 ? 0 : $counted - ($float + $cashNet - $drops),
            'drops_minor' => $drops, 'cash_net_minor' => $cashNet, 'receipts' => [],
        ]);
    }

    private function key(): array
    {
        return ['Idempotency-Key' => 'cash-'.(++$this->keys).'-'.str_repeat('x', 20)];
    }

    private function cashShiftId(string $shiftId): string
    {
        return (string) DB::table('fin_cash_shifts')->where('shift_id', $shiftId)->value('id');
    }

    public function test_a_night_audit_books_the_day_once_by_outlet_with_base_service_and_tax_apart(): void
    {
        DB::table('revenue_outlets')->insert(['id' => '01arz3ndektsv4rrffq69g5fb1', 'property_id' => self::A, 'code' => 'REST', 'name' => 'Restaurant', 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('revenue_outlet_sources')->insert(['property_id' => self::A, 'source' => 'pos_restaurant', 'outlet_id' => '01arz3ndektsv4rrffq69g5fb1', 'created_at' => now()]);

        $this->audited('2026-10-02');
        $this->audited('2026-10-02');

        self::assertSame(1, DB::table('fin_revenue_days')->count(), 'the same night audit is booked once');
        $day = (array) DB::table('fin_revenue_days')->first();
        self::assertSame(['2026-10-02', 'recorded', 'IDR', 13_500_000], [substr((string) $day['business_date'], 0, 10), $day['status'], $day['currency'], (int) $day['collected_minor']]);
        self::assertSame((int) DB::table('fin_revenue_lines')->sum('total_minor'), (int) $day['total_minor']);

        $lines = DB::table('fin_revenue_lines')->orderBy('source')->get()->keyBy('source');
        self::assertSame(['rooms', null], [$lines['night_audit']->outlet_code, $lines['night_audit']->outlet_name]);
        self::assertSame(['REST', 'Restaurant'], [$lines['pos_restaurant']->outlet_code, $lines['pos_restaurant']->outlet_name]);
        self::assertSame('other', $lines['minibar']->outlet_code);
        self::assertSame([2_000_000, 200_000, 220_000, 2_420_000], [(int) $lines['pos_restaurant']->base_minor, (int) $lines['pos_restaurant']->service_charge_minor, (int) $lines['pos_restaurant']->tax_minor, (int) $lines['pos_restaurant']->total_minor]);
        self::assertSame(2, DB::table('fin_payment_lines')->count());
        self::assertSame(self::A, (string) $day['property_id']);
        self::assertSame(strtolower($this->cashier), $day['actor_id']);
        self::assertNotEmpty($day['event_id']);
    }

    public function test_the_booked_figures_cannot_be_changed_or_removed(): void
    {
        $this->audited('2026-10-02');
        $this->shift('01arz3ndektsv4rrffq69g5fc1', strtolower($this->cashier), 500_000);

        foreach ([
            fn () => DB::table('fin_revenue_days')->update(['total_minor' => 1, 'base_minor' => 1, 'service_charge_minor' => 0, 'tax_minor' => 0]),
            fn () => DB::table('fin_revenue_days')->delete(),
            fn () => DB::table('fin_revenue_lines')->update(['base_minor' => 0]),
            fn () => DB::table('fin_revenue_lines')->delete(),
            fn () => DB::table('fin_payment_lines')->update(['received_minor' => 0]),
            fn () => DB::table('fin_cash_shifts')->update(['cash_net_minor' => 0]),
            fn () => DB::table('fin_cash_shifts')->delete(),
        ] as $change) {
            try {
                $change();
                self::fail('A booked figure was changed.');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_the_daily_report_totals_a_range_by_outlet_and_method_and_the_months_roll_up(): void
    {
        $this->audited('2026-09-30', '01arz3ndektsv4rrffq69g5fa1');
        $this->audited('2026-10-01', '01arz3ndektsv4rrffq69g5fa2', 20_000_000);
        $this->audited('2026-10-02', '01arz3ndektsv4rrffq69g5fa3');

        $this->get('/finance/revenue?from=2026-10-01&to=2026-10-02')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('finance/pages/revenue')
            ->where('report.totals.base_minor', 20_000_000 + 2_100_000 + 10_000_000 + 2_100_000)
            ->where('report.unverified', 2)
            ->has('report.days', 2)
            ->has('report.methods', 2)
            ->where('report.methods.0.method', 'card')
            ->where('report.methods.1.net_minor', 2 * 8_500_000)
            ->has('report.outlets', 2)
            ->where('report.outlets.0.code', 'other')
            ->where('monthly.year', 2026)
            ->where('monthly.months.8.days', 1)
            ->where('monthly.months.9.days', 2));

        $this->get('/finance/revenue?from=2026-10-02&to=2026-10-01')->assertStatus(422);
        $this->get('/finance/revenue?from=2026-01-01&to=2026-10-01')->assertStatus(422);
    }

    public function test_only_people_who_may_see_revenue_see_it(): void
    {
        $this->audited('2026-10-02');
        $this->as([]);
        $this->get('/finance/revenue')->assertForbidden();
        $this->get('/finance/revenue/2026-10-02')->assertForbidden();
        $this->get('/finance/cash')->assertForbidden();

        $this->as([FinanceAccess::REVENUE_VIEW]);
        $this->get('/finance/revenue/2026-10-02')->assertOk()->assertInertia(fn (Assert $page) => $page->where('day.may_verify', false)->where('day.may.verify', false));
        $this->postJson('/finance/revenue/2026-10-02/verify', [])->assertForbidden();
        $this->get('/finance/revenue/2026-10-09')->assertNotFound();
    }

    public function test_cash_that_matches_the_system_is_received_once_without_an_exception(): void
    {
        $this->shift('01arz3ndektsv4rrffq69g5fc1', strtolower($this->reconciler), 8_500_000, 0, 1_000_000, 2_000_000);
        $id = $this->cashShiftId('01arz3ndektsv4rrffq69g5fc1');

        // The person who worked the shift never receives its cash.
        $this->postJson("/finance/cash/shifts/{$id}/receive", ['deposited_minor' => 8_500_000], $this->key())->assertForbidden();

        $this->as([FinanceAccess::RECONCILE]);
        $this->postJson("/finance/cash/shifts/{$id}/receive", ['deposited_minor' => -1], $this->key())->assertStatus(422);
        $r = $this->postJson("/finance/cash/shifts/{$id}/receive", ['deposited_minor' => 8_500_000, 'note' => 'Counted in the office'], $this->key())->assertCreated();
        self::assertSame(['DEP-000001', 0, true], [$r->json('shift.deposit.number'), $r->json('shift.deposit.variance_minor'), $r->json('shift.received')]);
        self::assertSame(0, DB::table('fin_cash_exceptions')->count());

        $this->postJson("/finance/cash/shifts/{$id}/receive", ['deposited_minor' => 8_500_000], $this->key())->assertStatus(409);
        self::assertSame(1, DB::table('fin_cash_deposits')->count());
        self::assertNotNull(DB::table('audit_entries')->where('action', 'cash_deposit.recorded')->first());
        self::assertNotNull(DB::table('outbox_messages')->where('event_type', 'finance.cash.received')->first());
    }

    public function test_a_replayed_request_with_the_same_key_receives_the_cash_once(): void
    {
        $this->shift('01arz3ndektsv4rrffq69g5fc1', '01arz3ndektsv4rrffq69g5fc9', 3_000_000);
        $id = $this->cashShiftId('01arz3ndektsv4rrffq69g5fc1');
        $headers = $this->key();

        $first = $this->postJson("/finance/cash/shifts/{$id}/receive", ['deposited_minor' => 3_000_000], $headers)->assertCreated();
        $again = $this->postJson("/finance/cash/shifts/{$id}/receive", ['deposited_minor' => 3_000_000], $headers);

        self::assertContains($again->getStatusCode(), [200, 201]);
        self::assertSame($first->json('shift.deposit.number'), $again->json('shift.deposit.number'));
        self::assertSame(1, DB::table('fin_cash_deposits')->count());
    }

    public function test_a_difference_needs_a_reason_and_stays_an_exception_until_another_person_settles_it(): void
    {
        $this->shift('01arz3ndektsv4rrffq69g5fc1', '01arz3ndektsv4rrffq69g5fc9', 3_000_000);
        $id = $this->cashShiftId('01arz3ndektsv4rrffq69g5fc1');

        $this->postJson("/finance/cash/shifts/{$id}/receive", ['deposited_minor' => 2_950_000], $this->key())->assertStatus(422);
        self::assertSame(0, DB::table('fin_cash_deposits')->count(), 'nothing was recorded without the reason');

        $this->as([FinanceAccess::RECONCILE]);
        $r = $this->postJson("/finance/cash/shifts/{$id}/receive", ['deposited_minor' => 2_950_000, 'reason' => 'Two 20,000 notes were torn'], $this->key())->assertCreated();
        self::assertSame([-50_000, 'open'], [$r->json('shift.deposit.variance_minor'), $r->json('shift.deposit.exception_status')]);
        $exception = (array) DB::table('fin_cash_exceptions')->first();
        self::assertSame([-50_000, 'open', 0], [(int) $exception['variance_minor'], $exception['status'], (int) $exception['lock_version']]);

        // The receiver cannot settle their own difference.
        $this->postJson("/finance/cash/exceptions/{$exception['id']}/settle", ['status' => 'waived', 'resolution' => 'Written off', 'lock_version' => 0])->assertForbidden();

        $this->as([FinanceAccess::RECONCILE]);
        $url = "/finance/cash/exceptions/{$exception['id']}/settle";
        $this->postJson($url, ['status' => 'lost', 'resolution' => 'x', 'lock_version' => 0])->assertStatus(422);
        $this->postJson($url, ['status' => 'waived', 'resolution' => '   ', 'lock_version' => 0])->assertStatus(422);
        $this->postJson($url, ['status' => 'waived', 'resolution' => 'Written off by the finance manager', 'lock_version' => 5])->assertStatus(409);
        $done = $this->postJson($url, ['status' => 'waived', 'resolution' => 'Written off by the finance manager', 'lock_version' => 0])->assertOk();
        self::assertSame(['waived', 'Written off by the finance manager'], [$done->json('exception.status'), $done->json('exception.resolution')]);
        $this->postJson($url, ['status' => 'explained', 'resolution' => 'again', 'lock_version' => 1])->assertStatus(409);
        self::assertNotNull(DB::table('audit_entries')->where('action', 'cash_exception.settled')->first());

        try {
            DB::table('fin_cash_exceptions')->update(['resolution' => 'edited']);
            self::fail('A settled exception was edited.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        $this->get('/finance/cash')->assertOk()->assertInertia(fn (Assert $page) => $page->component('finance/pages/cash')->where('overview.open_exceptions', 0)->where('overview.exceptions.0.status', 'waived')->where('overview.shifts.0.received', true));
    }

    public function test_a_day_is_verified_only_when_its_cash_was_received_and_its_exceptions_settled(): void
    {
        $this->audited('2026-10-03');
        $this->shift('01arz3ndektsv4rrffq69g5fc1', '01arz3ndektsv4rrffq69g5fc9', 3_000_000);
        $this->shift('01arz3ndektsv4rrffq69g5fc2', '01arz3ndektsv4rrffq69g5fc8', 1_000_000, 0, 500_000, 0, '2026-10-02');
        $day = '/finance/revenue/2026-10-03';

        $this->postJson("{$day}/verify", [])->assertStatus(409);
        $this->get($day)->assertInertia(fn (Assert $page) => $page->where('day.blockers.waiting', 1)->where('day.blockers.open', 0)->where('day.may_verify', false));

        $one = $this->cashShiftId('01arz3ndektsv4rrffq69g5fc1');
        $this->postJson("/finance/cash/shifts/{$one}/receive", ['deposited_minor' => 2_900_000, 'reason' => 'Short'], $this->key())->assertCreated();
        $this->postJson("{$day}/verify", [])->assertStatus(409);
        $this->get($day)->assertInertia(fn (Assert $page) => $page->where('day.blockers.waiting', 0)->where('day.blockers.open', 1));

        $this->as([FinanceAccess::RECONCILE]);
        $exception = (string) DB::table('fin_cash_exceptions')->value('id');
        $this->postJson("/finance/cash/exceptions/{$exception}/settle", ['status' => 'recovered', 'resolution' => 'The cashier paid the difference back', 'lock_version' => 0])->assertOk();

        $this->get($day)->assertInertia(fn (Assert $page) => $page->where('day.may_verify', true));
        $this->postJson("{$day}/verify", ['note' => str_repeat('x', 301)])->assertStatus(422);
        $ok = $this->postJson("{$day}/verify", ['note' => 'Checked against the bank slip'])->assertOk();
        self::assertSame(['verified', 'Checked against the bank slip'], [$ok->json('day.status'), $ok->json('day.verification_note')]);
        $this->postJson("{$day}/verify", [])->assertStatus(409);
        self::assertNotNull(DB::table('audit_entries')->where('action', 'revenue_day.verified')->first());
        self::assertNotNull(DB::table('outbox_messages')->where('event_type', 'finance.revenue.day_verified')->first());

        try {
            DB::table('fin_revenue_days')->update(['verification_note' => 'edited']);
            self::fail('A verified day was edited.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        // A shift closed on another date does not hold this day back, and its day has no revenue booked yet.
        $this->get('/finance/revenue/2026-10-02')->assertNotFound();
    }

    public function test_the_cash_page_lists_waiting_shifts_with_what_was_declared(): void
    {
        $this->shift('01arz3ndektsv4rrffq69g5fc1', '01arz3ndektsv4rrffq69g5fc9', 3_000_000, 3_900_000, 1_000_000, 500_000);

        $this->get('/finance/cash?only=waiting')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('overview.waiting', 1)
            ->where('overview.shifts.0.cash_net_minor', 3_000_000)
            ->where('overview.shifts.0.declared_minor', 3_900_000 - 1_000_000 + 500_000)
            ->where('overview.shifts.0.shift_variance_minor', 3_900_000 - (1_000_000 + 3_000_000 - 500_000))
            ->where('overview.shifts.0.may_receive', true));
        $this->get('/finance/cash?only=received')->assertOk()->assertInertia(fn (Assert $page) => $page->has('overview.shifts', 0));
        $this->get('/finance/cash?only=nope')->assertStatus(422);
    }
}
