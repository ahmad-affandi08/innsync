<?php

declare(strict_types=1);

namespace Tests\Integration\FrontOffice;

use App\Modules\FrontOffice\Application\BookingRefused;
use App\Modules\FrontOffice\Application\Inventory\AvailabilityService;
use App\Modules\FrontOffice\Application\Inventory\InventoryAdminService;
use App\Modules\FrontOffice\Application\Inventory\RoomBlock;
use App\Modules\FrontOffice\Application\Reservations\ReservationRepository;
use App\Modules\FrontOffice\Application\Reservations\ReservationRequest;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\FrontOffice\Domain\Reservations\Reservation;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Rates\RatePlanService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Documents\DocumentNumbers;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyConflict;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\AdjustableClock;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class ReservationTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private const B = '01arz3ndektsv4rrffq69g5faw';

    private AdjustableClock $clock;

    private string $manager;

    private string $viewer;

    private string $typeId;

    private string $planId;

    /** @var list<string> */
    private array $roomIds = [];

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Integration tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new AdjustableClock('2026-10-01 03:00:00');
        $this->app->instance(Clock::class, $this->clock);
        Context::add('correlation_id', '01arz3ndektsv4rrffq69g5fat');
        $this->createProperty(self::A, 'A');
        $this->createProperty(self::B, 'B');

        $admin = UserRecord::factory()->create();
        $manager = UserRecord::factory()->create();
        $viewer = UserRecord::factory()->create();
        $this->grant($admin, self::A, [
            RoomCatalogService::MANAGE_PERMISSION, RatePlanService::MANAGE_PERMISSION, ChargeSchemeService::MANAGE_PERMISSION,
            PropertySettingsService::MANAGE_PERMISSION, InventoryAdminService::OVERBOOKING_PERMISSION, InventoryAdminService::BLOCK_PERMISSION, InventoryAdminService::HOLD_PERMISSION,
        ]);
        $this->grant($manager, self::A, [ReservationService::MANAGE_PERMISSION]);
        $this->grant($viewer, self::A, [ReservationService::VIEW_PERMISSION]);
        $admin = strtolower((string) $admin->getKey());
        $this->manager = strtolower((string) $manager->getKey());
        $this->viewer = strtolower((string) $viewer->getKey());
        $this->adminId = $admin;
        app(PropertyContext::class)->activateFromString(self::A);

        $catalog = app(RoomCatalogService::class);
        $this->typeId = $catalog->createType($this->a(), $admin, 'DLX', 'Deluxe', null, 2, 1, 0, 'setup')->id;

        foreach (['101', '102', '103'] as $number) {
            $this->roomIds[] = $catalog->createRoom($this->a(), $admin, $number, $this->typeId, '1', 'setup')->id;
        }

        app(ChargeSchemeService::class)->define($this->a(), $admin, 'rooms', '2026-01-01', '10', '10', true, 'Regional regulation');
        $plans = app(RatePlanService::class);
        $this->planId = $plans->createPlan($this->a(), $admin, 'BAR', 'Best Available', 'public', null, false, 'setup')->id;
        $plans->addPrice($this->a(), $admin, $this->planId, $this->typeId, '2026-10-01', '2027-12-31', 127, 100_000_000, 'Season');
        app(PropertySettingsService::class)->initializeBusinessDate($this->a(), $admin, '2026-10-01', 0, 'Go-live');
    }

    private string $adminId;

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

    private function reservations(): ReservationService
    {
        return app(ReservationService::class);
    }

    private function request(string $arrival = '2026-10-10', string $departure = '2026-10-12', array $override = []): ReservationRequest
    {
        $data = ['source' => 'direct', 'guest' => 'Budi Santoso', 'phone' => '+62 812 3456 7890', 'email' => 'budi@example.com', 'adults' => 2, 'children' => 0, 'notes' => null, 'status' => 'tentative', 'ack' => false, 'oversell' => null, ...$override];

        return new ReservationRequest($data['source'], $data['guest'], $data['phone'], $data['email'], $arrival, $departure, $data['adults'], $data['children'], $this->typeId, $this->planId, $data['notes'], $data['status'], $data['ack'], $data['oversell']);
    }

    private static int $keyCounter = 0;

    private function book(string $arrival = '2026-10-10', string $departure = '2026-10-12', array $override = [], ?string $actor = null): Reservation
    {
        return $this->reservations()->create($this->a(), $actor ?? $this->manager, $this->request($arrival, $departure, $override), IdempotencyKey::fromString(sprintf('key-%016d', ++self::$keyCounter)));
    }

    private function availableOn(string $night): int
    {
        $calendar = app(AvailabilityService::class)->calendar($this->a(), $this->manager, $night, 1, null);

        return $calendar['types'][0]['nights'][0]['available'];
    }

    public function test_a_reservation_is_priced_numbered_snapshotted_audited_and_holds_inventory(): void
    {
        $r = $this->book();

        self::assertSame('RSV-000001', $r->number);
        self::assertSame(242_000_000, $r->total->amountMinor);
        self::assertSame('tentative', $r->status->value);
        self::assertSame(2, DB::table('reservation_nights')->where('reservation_id', $r->id)->where('is_active', true)->count());
        self::assertSame(['2026-10-10', '2026-10-11'], app(ReservationRepository::class)->activeNights($this->a(), $r->id));

        $row = DB::table('reservations')->where('id', $r->id)->first();
        self::assertSame([200_000_000, 20_000_000, 22_000_000], [(int) $row->total_base_minor, (int) $row->total_service_charge_minor, (int) $row->total_tax_minor]);
        $snapshot = json_decode($row->price_snapshot, true);
        self::assertSame(2, count($snapshot['nights']));
        self::assertEquals(['service_charge_bp' => 1000, 'tax_bp' => 1000, 'tax_on_service_charge' => true, 'prices_include_charges' => false, 'rounding_increment_minor' => 100, 'rounding_mode' => 'half_up'], $snapshot['nights'][0]['scheme']);

        $audit = DB::table('audit_entries')->where('action', 'reservation.created')->first();
        self::assertNotNull($audit);
        self::assertStringNotContainsString('budi@example.com', (string) $audit->after_state);
        self::assertStringNotContainsString('Budi', (string) $audit->after_state);
        $event = DB::table('outbox_messages')->where('event_type', 'frontoffice.reservation.created')->first();
        self::assertNotNull($event);
        self::assertSame(2, $this->availableOn('2026-10-10'));
        self::assertSame(2, $this->availableOn('2026-10-11'));
        self::assertSame(3, $this->availableOn('2026-10-12'), 'the departure day is not a night');
    }

    public function test_creating_is_idempotent_and_a_changed_payload_with_the_same_key_is_refused(): void
    {
        $key = IdempotencyKey::fromString('same-key-0000000001');
        $first = $this->reservations()->create($this->a(), $this->manager, $this->request(), $key);
        $second = $this->reservations()->create($this->a(), $this->manager, $this->request(), $key);

        self::assertSame($first->id, $second->id);
        self::assertSame(1, DB::table('reservations')->count());
        self::assertSame(2, DB::table('reservation_nights')->count());

        $this->expectException(IdempotencyConflict::class);
        $this->reservations()->create($this->a(), $this->manager, $this->request('2026-10-20', '2026-10-21'), $key);
    }

    public function test_the_last_room_cannot_be_sold_twice_and_the_calendar_shows_it(): void
    {
        foreach ([1, 2, 3] as $ignored) {
            $this->book();
        }

        self::assertSame(0, $this->availableOn('2026-10-10'));

        try {
            $this->book();
            self::fail('A fourth room was sold');
        } catch (BookingRefused $e) {
            self::assertSame(['no_availability', 409], [$e->reason, $e->status()]);
        }

        self::assertSame(3, DB::table('reservations')->count());
        // A stay that only partly overlaps the full nights is refused too, and an adjacent stay is fine.
        try {
            $this->book('2026-10-11', '2026-10-13');
            self::fail('An overlapping night was sold');
        } catch (BookingRefused $e) {
            self::assertSame('no_availability', $e->reason);
        }

        self::assertSame('2026-10-12', $this->book('2026-10-12', '2026-10-13')->stay->arrival->toString());
    }

    public function test_overbooking_needs_the_allowance_an_acknowledgement_a_privilege_and_a_reason_and_is_flagged(): void
    {
        foreach ([1, 2, 3] as $ignored) {
            $this->book();
        }

        $admin = app(InventoryAdminService::class);
        self::assertSame(0, $admin->setOverbookingAllowance($this->a(), $this->adminId, $this->typeId, 1, 0, 'Group policy: one room of tolerance'));

        try {
            $this->book();
            self::fail('An oversell was accepted without a warning');
        } catch (BookingRefused $e) {
            self::assertSame('oversell_warning', $e->reason);
        }

        // The administrator may book and override; the ordinary manager may not override.
        $this->grant(UserRecord::query()->findOrFail($this->adminId), self::A, [ReservationService::MANAGE_PERMISSION, ReservationService::OVERBOOKING_PERMISSION]);

        foreach ([['ack' => true, 'oversell' => 'Likely no-show', 'actor' => $this->manager, 'status' => 403], ['ack' => true, 'oversell' => ' ', 'actor' => $this->adminId, 'status' => 422]] as $case) {
            try {
                $this->book('2026-10-10', '2026-10-12', ['ack' => $case['ack'], 'oversell' => $case['oversell']], $case['actor']);
                self::fail('Accepted');
            } catch (Refusal|BookingRefused $e) {
                self::assertSame($case['status'], $e->status());
            }
        }

        $over = $this->book('2026-10-10', '2026-10-12', ['ack' => true, 'oversell' => 'Likely no-show'], $this->adminId);

        self::assertTrue($over->oversold);
        self::assertSame(-1, $this->availableOn('2026-10-10'));
        self::assertSame(1, DB::table('reservations')->where('oversold', true)->count());

        try {
            $this->book('2026-10-10', '2026-10-12', ['ack' => true, 'oversell' => 'Again'], $this->adminId);
            self::fail('Sold beyond the allowance');
        } catch (BookingRefused $e) {
            self::assertSame('no_availability', $e->reason);
        }
    }

    public function test_booking_rules_refuse_the_past_the_far_future_too_many_guests_missing_prices_and_bad_input(): void
    {
        $cases = [
            'past' => [fn () => $this->book('2026-09-30', '2026-10-02'), 'arrival_in_the_past', 422],
            'horizon' => [fn () => $this->book('2027-12-01', '2027-12-03'), 'beyond_horizon', 422],
            'occupancy' => [fn () => $this->book('2026-10-10', '2026-10-12', ['adults' => 3]), 'occupancy_exceeded', 422],
            'kids' => [fn () => $this->book('2026-10-10', '2026-10-12', ['children' => 2]), 'occupancy_exceeded', 422],
        ];

        foreach ($cases as $name => [$attempt, $reason, $status]) {
            try {
                $attempt();
                self::fail("{$name} accepted");
            } catch (BookingRefused $e) {
                self::assertSame([$reason, $status], [$e->reason, $e->status()], $name);
            }
        }

        foreach ([['source' => 'carrier_pigeon'], ['status' => 'guaranteed'], ['guest' => ' '], ['phone' => 'call me'], ['email' => 'not-an-email'], ['adults' => 0]] as $bad) {
            try {
                $this->book('2026-10-10', '2026-10-12', $bad);
                self::fail('Accepted '.json_encode($bad));
            } catch (Refusal $e) {
                self::assertSame(422, $e->status(), json_encode($bad));
            }
        }

        // A stay beyond the priced dates is not bookable and says why.
        try {
            $this->reservations()->create($this->a(), $this->manager, new ReservationRequest('direct', 'Budi', null, null, '2028-01-05', '2028-01-07', 2, 0, $this->typeId, $this->planId, null), IdempotencyKey::fromString('far-key-000000000001'));
            self::fail('Booked without a price');
        } catch (BookingRefused $e) {
            self::assertContains($e->reason, ['not_bookable', 'beyond_horizon']);
        }

        self::assertSame(0, DB::table('reservations')->count());
    }

    public function test_cancelling_frees_the_rooms_needs_a_reason_and_cannot_be_repeated_or_raced(): void
    {
        $r = $this->book();
        self::assertSame(2, $this->availableOn('2026-10-10'));

        foreach (['', str_repeat('x', 501)] as $reason) {
            try {
                $this->reservations()->cancel($this->a(), $this->manager, $r->id, $reason, 0);
                self::fail('Cancelled without a proper reason');
            } catch (Refusal $e) {
                self::assertSame(422, $e->status());
            }
        }

        try {
            $this->reservations()->cancel($this->a(), $this->manager, $r->id, 'Guest cancelled', 7);
            self::fail('Stale version accepted');
        } catch (Refusal $e) {
            self::assertSame(409, $e->status());
        }

        $cancelled = $this->reservations()->cancel($this->a(), $this->manager, $r->id, 'Guest cancelled', 0);
        self::assertSame(['cancelled', 'Guest cancelled', 1], [$cancelled->status->value, $cancelled->statusReason, $cancelled->lockVersion]);
        self::assertSame(3, $this->availableOn('2026-10-10'));
        self::assertSame(2, DB::table('reservation_nights')->where('reservation_id', $r->id)->where('is_active', false)->count(), 'history rows stay');

        try {
            $this->reservations()->cancel($this->a(), $this->manager, $r->id, 'Again', 1);
            self::fail('Cancelled twice');
        } catch (Refusal $e) {
            self::assertSame(409, $e->status());
        }

        self::assertNotNull(DB::table('audit_entries')->where('action', 'reservation.cancelled')->first());
    }

    public function test_no_show_is_only_possible_from_the_arrival_date_by_the_business_date(): void
    {
        $future = $this->book('2026-10-10', '2026-10-11');
        $today = $this->book('2026-10-01', '2026-10-02');

        try {
            $this->reservations()->noShow($this->a(), $this->manager, $future->id, 'Did not arrive', 0);
            self::fail('A future arrival was marked no-show');
        } catch (Refusal $e) {
            self::assertSame(409, $e->status());
        }

        $done = $this->reservations()->noShow($this->a(), $this->manager, $today->id, 'Did not arrive', 0);
        self::assertSame('no_show', $done->status->value);
        self::assertSame(3, $this->availableOn('2026-10-01'));
    }

    public function test_confirming_moves_a_tentative_reservation_once(): void
    {
        $r = $this->book();
        $confirmed = $this->reservations()->confirm($this->a(), $this->manager, $r->id, 0);
        self::assertSame('confirmed', $confirmed->status->value);

        $this->expectException(Refusal::class);
        $this->reservations()->confirm($this->a(), $this->manager, $r->id, 1);
    }

    public function test_blocking_a_room_removes_it_from_sale_and_reports_nights_that_become_oversold(): void
    {
        $inventory = app(InventoryAdminService::class);
        $this->grantAdminReservations();

        $result = $inventory->blockRoom($this->a(), $this->adminId, $this->roomIds[0], RoomBlock::OUT_OF_ORDER, '2026-10-10', '2026-10-14', 'Water damage');
        self::assertSame([], $result['oversold_nights']);
        self::assertSame(2, $this->availableOn('2026-10-12'));
        self::assertSame(3, $this->availableOn('2026-10-15'));

        foreach ([1, 2] as $ignored) {
            $this->book('2026-10-10', '2026-10-11');
        }

        self::assertSame(0, $this->availableOn('2026-10-10'));
        $second = $inventory->blockRoom($this->a(), $this->adminId, $this->roomIds[1], RoomBlock::OUT_OF_SERVICE, '2026-10-10', '2026-10-10', 'Painting');
        self::assertSame(['2026-10-10'], $second['oversold_nights']);

        try {
            $inventory->blockRoom($this->a(), $this->adminId, $this->roomIds[0], RoomBlock::OUT_OF_SERVICE, '2026-10-12', '2026-10-20', 'Overlap');
            self::fail('Overlapping block accepted');
        } catch (Refusal $e) {
            self::assertSame(422, $e->status());
        }

        $inventory->releaseBlock($this->a(), $this->adminId, $result['block']->id, 'Repaired');
        self::assertSame(3, $this->availableOn('2026-10-12'));
        self::assertSame(3, $this->availableOn('2026-10-13'));

        try {
            $inventory->releaseBlock($this->a(), $this->adminId, $result['block']->id, 'Again');
            self::fail('Released twice');
        } catch (Refusal $e) {
            self::assertSame(409, $e->status());
        }

        $this->expectException(QueryException::class);
        DB::table('room_blocks')->delete();
    }

    public function test_block_input_and_permissions_are_checked(): void
    {
        $inventory = app(InventoryAdminService::class);

        foreach ([
            [$this->manager, $this->roomIds[0], 'out_of_order', '2026-10-10', '2026-10-12', 'x', 403],
            [$this->adminId, $this->roomIds[0], 'broken', '2026-10-10', '2026-10-12', 'x', 422],
            [$this->adminId, $this->roomIds[0], 'out_of_order', '2026-10-12', '2026-10-10', 'x', 422],
            [$this->adminId, $this->roomIds[0], 'out_of_order', '2026-09-01', '2026-09-20', 'x', 422],
            [$this->adminId, $this->roomIds[0], 'out_of_order', '2026-10-10', '2026-10-12', '', 422],
            [$this->adminId, '01arz3ndektsv4rrffq69g5fax', 'out_of_order', '2026-10-10', '2026-10-12', 'x', 422],
        ] as [$actor, $room, $kind, $from, $to, $reason, $status]) {
            try {
                $inventory->blockRoom($this->a(), $actor, $room, $kind, $from, $to, $reason);
                self::fail('Accepted');
            } catch (Refusal $e) {
                self::assertSame($status, $e->status());
            }
        }

        self::assertSame(0, DB::table('room_blocks')->count());
    }

    public function test_holds_keep_rooms_back_refuse_more_than_is_free_and_expire_by_themselves(): void
    {
        $inventory = app(InventoryAdminService::class);
        $hold = $inventory->placeHold($this->a(), $this->adminId, $this->typeId, '2026-10-10', '2026-10-12', 2, 'Wedding group', '2026-10-05T00:00:00Z');

        self::assertSame(1, $this->availableOn('2026-10-11'));
        self::assertTrue($hold->isActive);

        try {
            $inventory->placeHold($this->a(), $this->adminId, $this->typeId, '2026-10-11', '2026-10-11', 2, 'Too many', null);
            self::fail('Held more than was free');
        } catch (Refusal $e) {
            self::assertSame(409, $e->status());
        }

        $this->clock->advance('+5 days');
        self::assertSame(3, $this->availableOn('2026-10-11'), 'the hold expired and the rooms are back on sale');

        $permanent = $inventory->placeHold($this->a(), $this->adminId, $this->typeId, '2026-11-01', '2026-11-01', 1, 'Partner allotment', null);
        self::assertSame(2, $this->availableOn('2026-11-01'));
        $inventory->releaseHold($this->a(), $this->adminId, $permanent->id, 'Partner released it');
        self::assertSame(3, $this->availableOn('2026-11-01'));

        foreach ([['2026-10-12', '2026-10-10', 1, 422], ['2026-10-10', '2026-10-12', 0, 422]] as [$from, $to, $rooms, $status]) {
            try {
                $inventory->placeHold($this->a(), $this->adminId, $this->typeId, $from, $to, $rooms, 'x', null);
                self::fail('Accepted');
            } catch (Refusal $e) {
                self::assertSame($status, $e->status());
            }
        }
    }

    public function test_guest_contact_details_are_hidden_from_viewers_never_in_lists_and_their_access_is_audited(): void
    {
        $r = $this->book();

        $asViewer = $this->reservations()->find($this->a(), $this->viewer, $r->id);
        self::assertNull($asViewer->guestPhone);
        self::assertNull($asViewer->guestEmail);
        self::assertSame('Budi Santoso', $asViewer->guestName);
        self::assertSame(0, DB::table('audit_entries')->where('action', 'pii.accessed')->count());

        $asManager = $this->reservations()->find($this->a(), $this->manager, $r->id);
        self::assertSame('budi@example.com', $asManager->guestEmail);
        $entry = DB::table('audit_entries')->where('action', 'pii.accessed')->first();
        self::assertSame(['fields' => ['guest_phone', 'guest_email']], json_decode($entry->after_state, true));

        foreach ($this->reservations()->search($this->a(), $this->manager, [], 10) as $listed) {
            self::assertNull($listed->guestPhone);
            self::assertNull($listed->guestEmail);
        }

        $this->expectException(Refusal::class);
        $this->reservations()->find($this->a(), '01arz3ndektsv4rrffq69g5fax', $r->id);
    }

    public function test_search_filters_by_status_dates_and_text_and_is_property_scoped(): void
    {
        $a = $this->book('2026-10-10', '2026-10-11');
        $b = $this->book('2026-11-10', '2026-11-11', ['guest' => 'Siti Rahma']);
        $this->reservations()->cancel($this->a(), $this->manager, $b->id, 'Changed plans', 0);

        $numbers = fn (array $f) => array_map(static fn ($r): string => $r->number, $this->reservations()->search($this->a(), $this->viewer, $f));

        self::assertSame([$a->number, $b->number], $numbers([]));
        self::assertSame([$b->number], $numbers(['status' => 'cancelled']));
        self::assertSame([$a->number], $numbers(['arrival_to' => '2026-10-31']));
        self::assertSame([$b->number], $numbers(['query' => 'siti']));
        self::assertSame([$a->number], $numbers(['query' => $a->number]));
        self::assertSame([], $numbers(['query' => '%']), 'LIKE wildcards are literal');

        $this->expectException(PropertyScopeViolation::class);
        $this->reservations()->search(PropertyId::fromString(self::B), $this->viewer, []);
    }

    public function test_the_database_keeps_reservations_and_their_prices_intact(): void
    {
        $r = $this->book();

        foreach ([
            fn () => DB::table('reservations')->where('id', $r->id)->delete(),
            fn () => DB::table('reservations')->where('id', $r->id)->update(['total_minor' => 1, 'total_base_minor' => 1]),
            fn () => DB::table('reservations')->where('id', $r->id)->update(['price_snapshot' => '{}']),
            fn () => DB::table('reservations')->where('id', $r->id)->update(['status' => 'cancelled']), // no reason
            fn () => DB::table('reservation_nights')->where('reservation_id', $r->id)->delete(),
            fn () => DB::table('reservations')->where('id', $r->id)->update(['oversold' => true]), // no reason
        ] as $change) {
            try {
                $change();
                self::fail('A reservation fact was altered');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_document_numbers_are_sequential_per_property_and_prefix_and_a_rollback_does_not_consume_one(): void
    {
        $numbers = app(DocumentNumbers::class);

        self::assertSame('RSV-000001', $numbers->next($this->a(), 'RSV'));
        self::assertSame('RSV-000002', $numbers->next($this->a(), 'RSV'));
        self::assertSame('FOL-000001', $numbers->next($this->a(), 'FOL'));
        self::assertSame('RSV-000001', $numbers->next(PropertyId::fromString(self::B), 'RSV'));

        try {
            DB::transaction(function () use ($numbers): void {
                $numbers->next($this->a(), 'RSV');

                throw new LogicException('abort');
            });
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        self::assertSame('RSV-000003', $numbers->next($this->a(), 'RSV'));

        $this->expectException(\InvalidArgumentException::class);
        $numbers->next($this->a(), 'rsv');
    }

    private function grantAdminReservations(): void
    {
        // The administrator also needs room master visibility to block rooms; the inventory service reads rooms through the catalogue reader.
        $this->grant(UserRecord::query()->findOrFail($this->adminId), self::A, [ReservationService::MANAGE_PERMISSION]);
    }
}
