<?php

declare(strict_types=1);

namespace Tests\Integration\FrontOffice;

use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Reservations\ReservationRequest;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\FrontOffice\Domain\Reservations\Reservation;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Policies\BookingPolicyReader;
use App\Modules\Property\Application\Policies\BookingPolicyService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Rates\RatePlanService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Time\Clock;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\AdjustableClock;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-FO-009: versioned booking policies, the snapshot a reservation keeps, deposits, guarantees and penalties. */
final class BookingPolicyTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private string $admin;

    private string $manager;

    private string $waiver;

    private string $viewer;

    private string $planId;

    private string $typeId;

    private static int $keys = 0;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Integration tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(Clock::class, new AdjustableClock('2026-10-01 03:00:00'));
        Context::add('correlation_id', '01arz3ndektsv4rrffq69g5fat');
        $this->createProperty(self::A, 'A');

        $admin = UserRecord::factory()->create();
        $manager = UserRecord::factory()->create();
        $waiver = UserRecord::factory()->create();
        $viewer = UserRecord::factory()->create();
        $this->grant($admin, self::A, [RoomCatalogService::MANAGE_PERMISSION, RatePlanService::MANAGE_PERMISSION, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION, BookingPolicyService::MANAGE_PERMISSION]);
        $this->grant($manager, self::A, [ReservationService::MANAGE_PERMISSION, FolioService::MANAGE_PERMISSION]);
        $this->grant($waiver, self::A, [ReservationService::MANAGE_PERMISSION, ReservationService::WAIVE_PENALTY_PERMISSION, ReservationService::GUARANTEE_OVERRIDE_PERMISSION]);
        $this->grant($viewer, self::A, [BookingPolicyService::VIEW_PERMISSION, ReservationService::VIEW_PERMISSION]);
        [$this->admin, $this->manager, $this->waiver, $this->viewer] = array_map(static fn (UserRecord $u): string => strtolower((string) $u->getKey()), [$admin, $manager, $waiver, $viewer]);
        app(PropertyContext::class)->activateFromString(self::A);

        $this->typeId = app(RoomCatalogService::class)->createType($this->a(), $this->admin, 'DLX', 'Deluxe', null, 2, 1, 0, 'setup')->id;

        foreach (['101', '102', '103'] as $number) {
            app(RoomCatalogService::class)->createRoom($this->a(), $this->admin, $number, $this->typeId, '1', 'setup');
        }

        app(ChargeSchemeService::class)->define($this->a(), $this->admin, 'rooms', '2026-01-01', '10', '10', true, 'Regional regulation');
        $plans = app(RatePlanService::class);
        $this->planId = $plans->createPlan($this->a(), $this->admin, 'BAR', 'Best Available', 'public', null, false, 'setup')->id;
        $plans->addPrice($this->a(), $this->admin, $this->planId, $this->typeId, '2026-10-01', '2027-12-31', 127, 100_000_000, 'Season');
        app(PropertySettingsService::class)->initializeBusinessDate($this->a(), $this->admin, '2026-10-01', 0, 'Go-live');
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    private function a(): PropertyId
    {
        return PropertyId::fromString(self::A);
    }

    private function policies(): BookingPolicyService
    {
        return app(BookingPolicyService::class);
    }

    private function define(?string $plan = null, ?string $source = null, string $from = '2026-10-01', array $o = []): array
    {
        $o = ['guarantee' => false, 'basis' => 'first_night', 'value' => 0, 'due' => 7, 'free' => 3, 'cancel' => 'first_night', 'cancelValue' => 0, 'noshow' => 'all_nights', 'noshowValue' => 0, ...$o];

        return $this->policies()->define($this->a(), $this->admin, $plan, $source, $from, $o['guarantee'], $o['basis'], $o['value'], $o['due'], $o['free'], $o['cancel'], $o['cancelValue'], $o['noshow'], $o['noshowValue'], 'Owner baseline');
    }

    private function book(string $arrival = '2026-10-10', string $departure = '2026-10-12', string $source = 'direct', string $status = 'confirmed'): Reservation
    {
        $request = new ReservationRequest($source, 'Budi Santoso', null, null, $arrival, $departure, 2, 0, $this->typeId, $this->planId, null, $status, false, null);

        return app(ReservationService::class)->create($this->a(), $this->manager, $request, IdempotencyKey::fromString(sprintf('key-%016d', ++self::$keys)));
    }

    private function reservations(): ReservationService
    {
        return app(ReservationService::class);
    }

    private function refusal(callable $do): Refusal
    {
        try {
            $do();
        } catch (Refusal $e) {
            return $e;
        }

        self::fail('Expected a refusal');
    }

    public function test_without_a_policy_nothing_is_assumed(): void
    {
        $r = $this->book();

        self::assertNull($r->policy);
        self::assertSame(0, $r->depositRequiredMinor);
        self::assertNull($r->depositDueDate);
        self::assertNull($this->reservations()->policyView($this->a(), $this->manager, $r->id));

        $cancelled = $this->reservations()->cancel($this->a(), $this->manager, $r->id, 'Guest asked', $r->lockVersion);
        self::assertSame('cancelled', $cancelled->status->value);
        self::assertSame(0, DB::table('folio_postings')->count());
    }

    public function test_a_policy_is_validated_versioned_and_never_starts_in_the_past(): void
    {
        self::assertSame(422, $this->refusal(fn () => $this->define(from: '2026-09-30'))->status());
        self::assertSame(422, $this->refusal(fn () => $this->define(o: ['guarantee' => true, 'basis' => 'none', 'due' => 0]))->status());
        self::assertSame(422, $this->refusal(fn () => $this->define(o: ['basis' => 'percent', 'value' => 10_001]))->status());
        self::assertSame(422, $this->refusal(fn () => $this->define(o: ['cancel' => 'first_night', 'cancelValue' => 5]))->status());
        self::assertSame(422, $this->refusal(fn () => $this->define(source: 'carrier-pigeon'))->status());

        $this->define();
        self::assertSame(422, $this->refusal(fn () => $this->define())->status(), 'one version per scope and date');
        $this->define(from: '2026-11-01', o: ['basis' => 'fixed', 'value' => 50_000_000]);
        self::assertSame(2, DB::table('booking_policies')->count());
        self::assertSame(2, DB::table('audit_entries')->where('action', 'booking_policy.defined')->count());

        $view = $this->policies()->overview($this->a(), $this->viewer);
        self::assertCount(2, $view['policies']);
        self::assertSame(403, $this->refusal(fn () => $this->policies()->define($this->a(), $this->viewer, null, null, '2026-12-01', false, 'none', 0, 0, 0, 'none', 0, 'none', 0, 'x'))->status());
        self::assertSame(403, $this->refusal(fn () => $this->policies()->overview($this->a(), $this->manager))->status());

        try {
            DB::table('booking_policies')->where('property_id', self::A)->update(['cancel_free_days' => 9]);
            self::fail('A policy version was rewritten');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }

    public function test_the_most_specific_policy_in_force_wins(): void
    {
        $this->define(o: ['free' => 1]);
        $this->define(source: 'ota', o: ['free' => 5]);
        $this->define(plan: $this->planId, o: ['free' => 7]);
        $this->define(plan: $this->planId, from: '2026-10-20', o: ['free' => 14]);

        $on = fn (string $source, string $date): int => $this->policies()->policyFor($this->a(), $this->planId, $source, BusinessDate::fromString($date))['cancellation']['free_days_before_arrival'];

        self::assertSame(7, $on('direct', '2026-10-05'));
        self::assertSame(7, $on('ota', '2026-10-05'), 'the plan counts more than the source');
        self::assertSame(14, $on('direct', '2026-10-25'));
        self::assertNull($this->policies()->policyFor($this->a(), $this->planId, 'direct', BusinessDate::fromString('2026-09-30')));
        self::assertSame(1, app(BookingPolicyReader::class)->policyFor($this->a(), '01arz3ndektsv4rrffq69g5fax', 'direct', BusinessDate::fromString('2026-10-05'))['cancellation']['free_days_before_arrival']);
    }

    public function test_a_reservation_keeps_the_policy_and_deposit_it_was_given_even_when_the_policy_changes(): void
    {
        $this->define(o: ['basis' => 'percent', 'value' => 3_000, 'due' => 7, 'free' => 3]);
        $first = $this->book('2026-10-20', '2026-10-22');

        self::assertSame(72_600_000, $first->depositRequiredMinor, '30% of 242,000,000');
        self::assertSame('2026-10-13', $first->depositDueDate?->toString());

        $this->define(from: '2026-10-02', o: ['basis' => 'fixed', 'value' => 10_000_000, 'free' => 30]);
        $second = $this->book('2026-10-20', '2026-10-22');
        self::assertSame(72_600_000, $this->reservations()->find($this->a(), $this->manager, $first->id)->depositRequiredMinor);
        $snapshot = fn (string $id): array => json_decode((string) DB::table('reservations')->where('id', $id)->value('policy_snapshot'), true);
        self::assertSame(['percent', 3], [$snapshot($first->id)['deposit']['basis'], $snapshot($first->id)['cancellation']['free_days_before_arrival']]);
        self::assertSame(['percent', 3, 72_600_000], [$snapshot($second->id)['deposit']['basis'], $snapshot($second->id)['cancellation']['free_days_before_arrival'], $second->depositRequiredMinor], 'the business date has not reached the new version yet');
        self::assertSame('fixed', $this->policies()->policyFor($this->a(), $this->planId, 'direct', BusinessDate::fromString('2026-10-02'))['deposit']['basis']);

        try {
            DB::table('reservations')->where('id', $first->id)->update(['policy_snapshot' => json_encode(['version' => 1])]);
            self::fail('A reservation policy snapshot was rewritten');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        try {
            DB::table('reservations')->where('id', $first->id)->update(['deposit_required_minor' => 1]);
            self::fail('A reservation deposit requirement was rewritten');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }

    public function test_the_deposit_basis_first_night_fixed_and_a_due_date_never_before_the_booking(): void
    {
        $this->define(plan: $this->planId, o: ['basis' => 'first_night', 'due' => 30]);
        $near = $this->book('2026-10-05', '2026-10-07');
        self::assertSame(121_000_000, $near->depositRequiredMinor);
        self::assertSame('2026-10-01', $near->depositDueDate?->toString(), 'booked inside the window: due now');

        $this->define(source: 'phone', from: '2026-10-01', o: ['basis' => 'fixed', 'value' => 999_000_000_000, 'due' => 0]);
        $phone = $this->book('2026-10-20', '2026-10-21', 'phone');
        self::assertSame(121_000_000, $phone->depositRequiredMinor, 'a fixed deposit is capped at the stay total... plan policy still wins');
    }

    public function test_a_booking_is_guaranteed_only_once_the_deposit_is_held_or_by_an_override_with_a_reason(): void
    {
        $this->define(o: ['guarantee' => true, 'basis' => 'first_night']);
        $r = $this->book();
        self::assertSame('confirmed', $r->status->value);

        $e = $this->refusal(fn () => $this->reservations()->guarantee($this->a(), $this->manager, $r->id, $r->lockVersion));
        self::assertSame(409, $e->status());

        $folio = app(FolioService::class)->open($this->a(), $this->manager, $r->id);
        app(FolioService::class)->pay($this->a(), $this->manager, $folio['id'], 'cash', 60_000_000, null, 'deposit');
        self::assertSame(409, $this->refusal(fn () => $this->reservations()->guarantee($this->a(), $this->manager, $r->id, $r->lockVersion))->status(), 'half a deposit is not a deposit');

        app(FolioService::class)->pay($this->a(), $this->manager, $folio['id'], 'cash', 61_000_000, null, 'deposit');
        $view = $this->reservations()->policyView($this->a(), $this->manager, $r->id);
        self::assertSame([121_000_000, true, true], [$view['deposit_held_minor'], $view['deposit_complete'], $view['may_guarantee']]);

        $guaranteed = $this->reservations()->guarantee($this->a(), $this->manager, $r->id, $r->lockVersion);
        self::assertSame('guaranteed', $guaranteed->status->value);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'reservation.guaranteed')->count());
    }

    public function test_guaranteeing_without_a_deposit_needs_the_override_privilege_and_a_reason(): void
    {
        $r = $this->book();

        self::assertSame(403, $this->refusal(fn () => $this->reservations()->guarantee($this->a(), $this->manager, $r->id, $r->lockVersion, 'Regular guest'))->status());
        self::assertSame(422, $this->refusal(fn () => $this->reservations()->guarantee($this->a(), $this->waiver, $r->id, $r->lockVersion))->status());

        $done = $this->reservations()->guarantee($this->a(), $this->waiver, $r->id, $r->lockVersion, 'Company letter of guarantee');
        self::assertSame('guaranteed', $done->status->value);
    }

    public function test_a_late_cancellation_posts_one_base_only_penalty_and_a_free_one_posts_nothing(): void
    {
        $this->define(o: ['free' => 3, 'cancel' => 'first_night']);

        $early = $this->book('2026-10-10', '2026-10-12');
        $preview = $this->reservations()->penaltyPreview($this->a(), $this->manager, $early->id, 'cancel');
        self::assertSame([0, true, '2026-10-07', false], [$preview['amount_minor'], $preview['free'], $preview['free_until'], $preview['may_waive']]);
        $this->reservations()->cancel($this->a(), $this->manager, $early->id, 'Plans changed', $early->lockVersion);
        self::assertSame(0, DB::table('folio_postings')->count());

        $late = $this->book('2026-10-03', '2026-10-05');
        $preview = $this->reservations()->penaltyPreview($this->a(), $this->manager, $late->id, 'cancel');
        self::assertSame([100_000_000, false], [$preview['amount_minor'], $preview['free']]);

        $this->reservations()->cancel($this->a(), $this->manager, $late->id, 'Plans changed', $late->lockVersion);
        $posting = DB::table('folio_postings')->first();
        self::assertSame(['PENALTY', 'charge', 'policy', 100_000_000, 0, 0, 100_000_000], [$posting->code, $posting->entry_type, $posting->source, (int) $posting->base_minor, (int) $posting->service_charge_minor, (int) $posting->tax_minor, (int) $posting->total_minor]);
        self::assertSame(1, DB::table('folio_postings')->count());
        self::assertSame('cancelled', DB::table('reservations')->where('id', $late->id)->value('status'));
        self::assertSame(100_000_000, (int) json_decode((string) DB::table('audit_entries')->where('action', 'reservation.cancelled')->where('aggregate_id', $late->id)->value('after_state'), true)['penalty_minor']);
    }

    public function test_a_no_show_costs_the_policy_penalty_and_a_waiver_needs_the_privilege(): void
    {
        $this->define(o: ['noshow' => 'all_nights']);
        $r = $this->book('2026-10-01', '2026-10-03');

        self::assertSame(403, $this->refusal(fn () => $this->reservations()->noShow($this->a(), $this->manager, $r->id, 'Did not come', $r->lockVersion, true))->status());
        self::assertSame(0, DB::table('folio_postings')->count());
        self::assertSame('confirmed', DB::table('reservations')->where('id', $r->id)->value('status'));

        $preview = $this->reservations()->penaltyPreview($this->a(), $this->waiver, $r->id, 'no_show');
        self::assertSame([200_000_000, true], [$preview['amount_minor'], $preview['may_waive']]);

        $this->reservations()->noShow($this->a(), $this->waiver, $r->id, 'Did not come', $r->lockVersion, true);
        self::assertSame(0, DB::table('folio_postings')->count(), 'waived: no fee');
        $audit = json_decode((string) DB::table('audit_entries')->where('action', 'reservation.no_show')->value('after_state'), true);
        self::assertSame([200_000_000, false], [$audit['penalty_minor'], $audit['penalty_charged']]);
    }

    public function test_a_no_show_penalty_is_charged_when_not_waived_and_a_stale_version_charges_nothing(): void
    {
        $this->define(o: ['noshow' => 'percent', 'noshowValue' => 5_000]);
        $r = $this->book('2026-10-01', '2026-10-03');

        self::assertSame(409, $this->refusal(fn () => $this->reservations()->noShow($this->a(), $this->manager, $r->id, 'Did not come', $r->lockVersion + 5))->status());
        self::assertSame(0, DB::table('folio_postings')->count());

        $this->reservations()->noShow($this->a(), $this->manager, $r->id, 'Did not come', $r->lockVersion);
        self::assertSame([100_000_000], DB::table('folio_postings')->pluck('total_minor')->map(static fn ($v): int => (int) $v)->all(), '50% of 200,000,000 base');
        self::assertSame('no_show', DB::table('reservations')->where('id', $r->id)->value('status'));
    }
}
