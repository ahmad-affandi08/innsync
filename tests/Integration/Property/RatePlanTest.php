<?php

declare(strict_types=1);

namespace Tests\Integration\Property;

use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Rates\RatePlanService;
use App\Modules\Property\Application\Rates\RateQuoter;
use App\Modules\Property\Application\Rates\StayQuote;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\StayDates;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class RatePlanTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private const B = '01arz3ndektsv4rrffq69g5faw';

    private string $actor;

    private string $plain;

    private string $typeId;

    private string $planId;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Integration tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createProperty(self::A, 'A');
        $this->createProperty(self::B, 'B');
        $manager = UserRecord::factory()->create();
        $other = UserRecord::factory()->create();
        $this->grant($manager, self::A, [RatePlanService::MANAGE_PERMISSION, ChargeSchemeService::MANAGE_PERMISSION, RoomCatalogService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION]);
        $this->grant($other, self::A, ['front-office.reservation.view']);
        $this->actor = strtolower((string) $manager->getKey());
        $this->plain = strtolower((string) $other->getKey());
        app(PropertyContext::class)->activateFromString(self::A);

        $this->typeId = app(RoomCatalogService::class)->createType($this->a(), $this->actor, 'DLX', 'Deluxe', null, 2, 1, 0, 'setup')->id;
        $this->planId = $this->plans()->createPlan($this->a(), $this->actor, 'bar', 'Best Available', 'public', 'Breakfast for two', false, 'setup')->id;
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();

        parent::tearDown();
    }

    private function a(): PropertyId
    {
        return PropertyId::fromString(self::A);
    }

    private function plans(): RatePlanService
    {
        return app(RatePlanService::class);
    }

    private function stay(string $from, string $to): StayDates
    {
        return new StayDates(BusinessDate::fromString($from), BusinessDate::fromString($to));
    }

    private function scheme(string $from = '2026-01-01', string $sc = '10', string $tax = '10'): void
    {
        app(ChargeSchemeService::class)->define($this->a(), $this->actor, 'rooms', $from, $sc, $tax, true, 'Regional regulation');
    }

    private function price(int $rupiah, string $from = '2026-10-01', string $to = '2026-12-31', int $mask = 127): string
    {
        return $this->plans()->addPrice($this->a(), $this->actor, $this->planId, $this->typeId, $from, $to, $mask, $rupiah * 100, 'Season')->id;
    }

    /** @return list<string> */
    private function codes(StayQuote $quote): array
    {
        return array_map(static fn (array $v): string => $v['code'].($v['date'] === null ? '' : '@'.$v['date']), $quote->violations);
    }

    private function quote(string $from, string $to): StayQuote
    {
        return app(RateQuoter::class)->quote($this->a(), $this->planId, $this->typeId, $this->stay($from, $to));
    }

    public function test_plans_have_unique_normalized_codes_and_need_the_permission(): void
    {
        self::assertSame('BAR', DB::table('rate_plans')->value('code'));

        foreach ([[$this->actor, 'bar', 'public', 422], [$this->actor, 'x', 'public', 422], [$this->actor, 'CORP1', 'galaxy', 422], [$this->plain, 'CORP1', 'corporate', 403]] as [$actor, $code, $kind, $status]) {
            try {
                $this->plans()->createPlan($this->a(), $actor, $code, 'Name', $kind, null, false, 'x');
                self::fail('Accepted');
            } catch (Refusal $e) {
                self::assertSame($status, $e->status());
            }
        }

        self::assertSame(1, DB::table('rate_plans')->count());
        self::assertSame(1, DB::table('audit_entries')->where('action', 'rate_plan.created')->count());
    }

    public function test_overlapping_prices_are_refused_but_other_weekdays_types_and_dates_are_fine(): void
    {
        $this->price(1_000_000, '2026-10-01', '2026-10-31', 0b0011111); // Mon-Fri
        $this->price(1_300_000, '2026-10-01', '2026-10-31', 0b1100000); // Sat-Sun
        $this->price(1_100_000, '2026-11-01', '2026-11-30');
        $other = app(RoomCatalogService::class)->createType($this->a(), $this->actor, 'STD', 'Standard', null, 2, 0, 1, 'setup');
        $this->plans()->addPrice($this->a(), $this->actor, $this->planId, $other->id, '2026-10-01', '2026-10-31', 127, 80_000_000, 'Other type');

        foreach ([['2026-10-15', '2026-11-05', 0b0000001], ['2026-10-31', '2026-10-31', 127]] as [$from, $to, $mask]) {
            try {
                $this->plans()->addPrice($this->a(), $this->actor, $this->planId, $this->typeId, $from, $to, $mask, 1, 'dup');
                self::fail('Overlap accepted');
            } catch (Refusal $e) {
                self::assertSame(422, $e->status());
            }
        }

        self::assertSame(4, DB::table('rate_periods')->count());
    }

    public function test_bad_price_input_is_refused(): void
    {
        foreach ([['2026-10-10', '2026-10-01', 127, 100], ['2026-02-30', '2026-03-01', 127, 100], ['2026-10-01', '2026-10-02', 0, 100], ['2026-10-01', '2026-10-02', 128, 100], ['2026-10-01', '2026-10-02', 127, -1]] as [$from, $to, $mask, $amount]) {
            try {
                $this->plans()->addPrice($this->a(), $this->actor, $this->planId, $this->typeId, $from, $to, $mask, $amount, 'x');
                self::fail('Accepted');
            } catch (Refusal $e) {
                self::assertSame(422, $e->status());
            }
        }

        $this->expectException(Refusal::class);
        $this->plans()->addPrice($this->a(), $this->actor, $this->planId, '01arz3ndektsv4rrffq69g5fax', '2026-10-01', '2026-10-02', 127, 100, 'foreign type');
    }

    public function test_repricing_keeps_the_old_row_as_history_and_changes_are_audited(): void
    {
        $id = $this->price(1_000_000);
        $new = $this->plans()->repriceNight($this->a(), $this->actor, $id, 120_000_000, 'Peak season');

        self::assertSame(2, DB::table('rate_periods')->count());
        self::assertNotNull(DB::table('rate_periods')->where('id', $id)->value('superseded_at'));
        self::assertSame(120_000_000, (int) DB::table('rate_periods')->where('id', $new->id)->value('amount_minor'));
        self::assertCount(1, $this->plans()->listPeriods($this->a(), $this->actor, $this->planId));

        try {
            $this->plans()->repriceNight($this->a(), $this->actor, $id, 1, 'again');
            self::fail('Repriced a superseded row');
        } catch (Refusal $e) {
            self::assertSame(404, $e->status());
        }

        self::assertSame(['rate_period.added', 'rate_period.repriced'], DB::table('audit_entries')->whereIn('action', ['rate_period.added', 'rate_period.repriced'])->orderBy('occurred_at')->orderBy('id')->pluck('action')->all());
        $this->plans()->removePrice($this->a(), $this->actor, $new->id, 'Withdrawn');
        self::assertCount(0, $this->plans()->listPeriods($this->a(), $this->actor, $this->planId));
    }

    public function test_the_database_keeps_price_history_intact(): void
    {
        $id = $this->price(1_000_000);

        foreach ([
            fn () => DB::table('rate_periods')->where('id', $id)->update(['amount_minor' => 1]),
            fn () => DB::table('rate_periods')->where('id', $id)->delete(),
            fn () => DB::table('rate_periods')->where('id', $id)->update(['superseded_at' => now()]), // without who
        ] as $change) {
            try {
                $change();
                self::fail('History was altered');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->plans()->removePrice($this->a(), $this->actor, $id, 'ok');

        try {
            DB::table('rate_periods')->where('id', $id)->update(['superseded_at' => now()->addDay(), 'superseded_by' => $this->actor]);
            self::fail('A row was superseded twice');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_a_plus_plus_quote_adds_service_charge_then_tax_for_every_night_with_weekday_prices(): void
    {
        $this->scheme();
        $this->price(1_000_000, '2026-10-01', '2026-12-31', 0b0011111);
        $this->price(1_500_000, '2026-10-01', '2026-12-31', 0b1100000);

        // 2026-10-01 is a Thursday: nights Thu, Fri (weekday) and Sat (weekend).
        $quote = $this->quote('2026-10-01', '2026-10-04');

        self::assertTrue($quote->isBookable(), json_encode($quote->violations));
        self::assertSame([100_000_000, 100_000_000, 150_000_000], array_map(static fn (array $n): int => $n['quoted']->amountMinor, $quote->nights));
        self::assertSame(350_000_000, $quote->totalBase()->amountMinor);
        self::assertSame(423_500_000, $quote->total()->amountMinor); // 3.5M base + 10% service + 10% tax on the sum
        self::assertSame('IDR', $quote->currency);
    }

    public function test_a_nett_plan_derives_the_base_and_always_sums_to_the_quoted_price(): void
    {
        $this->scheme();
        $nett = $this->plans()->createPlan($this->a(), $this->actor, 'NETT', 'Nett', 'public', null, true, 'setup');
        $this->plans()->addPrice($this->a(), $this->actor, $nett->id, $this->typeId, '2026-10-01', '2026-10-31', 127, 121_000_000, 'Nett price');

        $quote = app(RateQuoter::class)->quote($this->a(), $nett->id, $this->typeId, $this->stay('2026-10-10', '2026-10-12'));

        self::assertTrue($quote->isBookable());
        self::assertSame(242_000_000, $quote->total()->amountMinor);
        self::assertSame(200_000_000, $quote->totalBase()->amountMinor);

        $this->plans()->addPrice($this->a(), $this->actor, $nett->id, $this->typeId, '2026-11-01', '2026-11-30', 127, 12_100_050, 'Not whole rupiah');
        self::assertSame(['price_not_rounded@2026-11-02'], $this->codes(app(RateQuoter::class)->quote($this->a(), $nett->id, $this->typeId, $this->stay('2026-11-02', '2026-11-03'))));
    }

    public function test_the_service_charge_and_tax_in_force_on_each_night_apply(): void
    {
        $this->scheme('2026-01-01', '10', '10');
        $this->scheme('2026-10-03', '5', '10');
        $this->price(1_000_000);

        $quote = $this->quote('2026-10-02', '2026-10-04'); // night 1 under the old scheme, night 2 under the new

        self::assertSame([121_000_000, 115_500_000], array_map(static fn (array $n): int => $n['breakdown']->total->amountMinor, $quote->nights));
    }

    public function test_a_stay_is_not_bookable_without_a_price_or_a_charge_scheme_and_never_guesses(): void
    {
        $this->price(1_000_000, '2026-10-01', '2026-10-02');

        self::assertSame(['no_price@2026-10-03', 'charges_not_configured'], $this->codes($this->quote('2026-10-02', '2026-10-04')));
        self::assertFalse($this->quote('2026-10-02', '2026-10-04')->isBookable());

        $this->scheme();
        self::assertSame(['no_price@2026-10-03'], $this->codes($this->quote('2026-10-02', '2026-10-04')));
    }

    public function test_restrictions_close_dates_and_set_minimum_and_maximum_stay(): void
    {
        $this->scheme();
        $this->price(1_000_000);
        $p = $this->plans();
        $r = $p->addRestriction($this->a(), $this->actor, $this->planId, null, '2026-10-10', '2026-10-10', null, null, true, false, false, 'No arrivals');
        $p->addRestriction($this->a(), $this->actor, $this->planId, $this->typeId, '2026-10-20', '2026-10-20', null, null, false, true, false, 'No departures');
        $p->addRestriction($this->a(), $this->actor, $this->planId, null, '2026-10-25', '2026-10-26', null, null, false, false, true, 'Sold out for the event');
        $p->addRestriction($this->a(), $this->actor, $this->planId, null, '2026-11-01', '2026-11-30', 3, 7, false, false, false, 'Season rules');
        $p->addRestriction($this->a(), $this->actor, $this->planId, null, '2026-11-01', '2026-11-30', 4, 6, false, false, false, 'Stricter');

        self::assertSame(['closed_to_arrival@2026-10-10'], $this->codes($this->quote('2026-10-10', '2026-10-12')));
        self::assertSame(['closed_to_departure@2026-10-20'], $this->codes($this->quote('2026-10-18', '2026-10-20')));
        self::assertSame(['stop_sell@2026-10-25'], $this->codes($this->quote('2026-10-24', '2026-10-26')), 'the night of the 25th is stopped; the departure day itself is not a night');
        self::assertSame(['stop_sell@2026-10-26'], $this->codes($this->quote('2026-10-26', '2026-10-27')), 'a stop-sell on the 26th blocks that night');
        self::assertTrue($this->quote('2026-10-27', '2026-10-28')->isBookable());
        self::assertSame(['min_stay@2026-11-02'], $this->codes($this->quote('2026-11-02', '2026-11-05')), 'the strictest minimum (4) wins');
        self::assertSame(['max_stay@2026-11-02'], $this->codes($this->quote('2026-11-02', '2026-11-09')), 'the strictest maximum (6) wins');
        self::assertTrue($this->quote('2026-11-02', '2026-11-07')->isBookable());

        $p->removeRestriction($this->a(), $this->actor, $r->id, 'Reopened');
        self::assertTrue($this->quote('2026-10-10', '2026-10-12')->isBookable());
    }

    public function test_an_inactive_plan_or_type_cannot_be_quoted(): void
    {
        $this->scheme();
        $this->price(1_000_000);
        $plan = $this->plans()->setPlanActive($this->a(), $this->actor, $this->planId, false, 0, 'Retired');

        self::assertSame(['rate_plan_unavailable'], $this->codes($this->quote('2026-10-02', '2026-10-03')));
        self::assertSame(1, $plan->lockVersion);

        try {
            $this->plans()->setPlanActive($this->a(), $this->actor, $this->planId, true, 0, 'stale');
            self::fail('Stale version accepted');
        } catch (Refusal $e) {
            self::assertSame(409, $e->status());
        }
    }

    public function test_charge_schemes_are_append_only_not_backdated_after_go_live_and_need_the_permission(): void
    {
        $service = app(ChargeSchemeService::class);
        $this->scheme('2026-01-01');
        app(PropertySettingsService::class)->initializeBusinessDate($this->a(), $this->actor, '2026-10-01', 0, 'Go-live');

        foreach ([['2026-09-30', 422], ['2026-01-01', 422]] as [$from, $status]) {
            try {
                $service->define($this->a(), $this->actor, 'rooms', $from, '10', '10', true, 'x');
                self::fail('Accepted');
            } catch (Refusal $e) {
                self::assertSame($status, $e->status());
            }
        }

        $service->define($this->a(), $this->actor, 'rooms', '2026-10-01', '10', '11', true, 'Regional change');
        self::assertSame(11 * 100, $service->schemeFor($this->a(), 'rooms', BusinessDate::fromString('2026-10-01'))?->tax->basisPoints);
        self::assertSame(10 * 100, $service->schemeFor($this->a(), 'rooms', BusinessDate::fromString('2026-09-30'))?->tax->basisPoints);
        self::assertNull($service->schemeFor($this->a(), 'rooms', BusinessDate::fromString('2025-12-31')));

        try {
            $service->define($this->a(), $this->plain, 'rooms', '2027-01-01', '10', '10', true, 'x');
            self::fail('Permission');
        } catch (Refusal $e) {
            self::assertSame(403, $e->status());
        }

        foreach (['10.555', '-1', 'ten', '1001'] as $bad) {
            try {
                $service->define($this->a(), $this->actor, 'rooms', '2027-02-01', $bad, '10', true, 'x');
                self::fail("{$bad} accepted");
            } catch (Refusal $e) {
                self::assertSame(422, $e->status());
            }
        }

        $this->expectException(QueryException::class);
        DB::table('charge_schemes')->update(['tax_bp' => 1]);
    }

    public function test_prices_are_scoped_to_the_active_property(): void
    {
        $this->expectException(PropertyScopeViolation::class);

        $this->plans()->listPlans(PropertyId::fromString(self::B), $this->actor);
    }
}
