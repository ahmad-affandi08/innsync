<?php

declare(strict_types=1);

namespace Tests\Integration\FrontOffice;

use App\Modules\FrontOffice\Application\BookingRefused;
use App\Modules\FrontOffice\Application\Folios\FolioRepository;
use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Inventory\InventoryAdminService;
use App\Modules\FrontOffice\Application\NightAudit\NightAuditService;
use App\Modules\FrontOffice\Application\Reservations\ReservationRequest;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\FrontOffice\Application\Stays\CheckInRequest;
use App\Modules\FrontOffice\Application\Stays\StayAmendmentService;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\FrontOffice\Domain\Reservations\Reservation;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Rates\RatePlanService;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\BuildsHotel;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class StayAmendmentTest extends TestCase
{
    use BuildsHotel;
    use RefreshDatabase;
    use SignsInToProperty;

    private array $stay;

    private string $reservationId;

    private int $n = 0;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Integration tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildHotel();
        $this->checkIn('2026-10-01', '2026-10-03', 0);
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    private function amend(): StayAmendmentService
    {
        return app(StayAmendmentService::class);
    }

    /** Checks a guest into a room of the booked type; the first one becomes `$this->stay`. @return array<string, mixed> */
    private function checkIn(string $arrival, string $departure, int $room, ?string $typeId = null): array
    {
        $reservation = $typeId === null ? $this->book($arrival, $departure, 'confirmed') : $this->bookType($typeId, $arrival, $departure);
        $stay = app(StayService::class)->checkIn(
            $this->property(), $this->managerId,
            new CheckInRequest($reservation->id, $this->roomIds[$room] ?? (string) DB::table('rooms')->orderByDesc('number')->value('id'), 'Budi Santoso', 'ID', 'ktp', sprintf('31740101019%05d', ++$this->n), null, null, 'Jl. Merdeka 1', 2, 0),
            IdempotencyKey::fromString(sprintf('amend-checkin-%05d', $this->n)),
        );

        if ($this->n === 1) {
            $this->stay = $stay;
            $this->reservationId = $reservation->id;
        }

        return $stay;
    }

    private function bookType(string $typeId, string $arrival, string $departure): Reservation
    {
        return app(ReservationService::class)->create(
            $this->property(), $this->managerId,
            new ReservationRequest('direct', 'Siti', null, null, $arrival, $departure, 2, 0, $typeId, $this->planId, null, 'confirmed'),
            IdempotencyKey::fromString(sprintf('amend-book-%06d', ++$this->bookingKey)),
        );
    }

    private function assertRefused(int $status, callable $action): void
    {
        try {
            $action();
            self::fail('Expected a refusal.');
        } catch (Refusal $e) {
            self::assertSame($status, $e->status());
        } catch (BookingRefused $e) {
            self::assertSame($status, $e->status());
        }
    }

    private function suiteType(): string
    {
        $catalog = app(RoomCatalogService::class);
        $type = $catalog->createType($this->property(), $this->adminId, 'STE', 'Suite', null, 4, 2, 0, 'setup');
        $catalog->createRoom($this->property(), $this->adminId, '201', $type->id, '2', 'setup');
        app(RatePlanService::class)->addPrice($this->property(), $this->adminId, $this->planId, $type->id, '2026-10-01', '2027-12-31', 127, 200_000_000, 'Suite price');

        return $type->id;
    }

    private function makeReady(string $roomId): void
    {
        DB::table('housekeeping_rooms')->updateOrInsert(['room_id' => $roomId], ['property_id' => self::PROPERTY, 'status' => 'ready', 'status_changed_at' => now(), 'lock_version' => 0]);
    }

    public function test_a_guest_moves_to_a_free_ready_room_of_the_same_type_with_the_folio_and_a_dirty_room_left_behind(): void
    {
        $folioId = app(FolioRepository::class)->byReservation($this->property(), $this->reservationId)[0]->id;
        app(FolioService::class)->charge($this->property(), $this->managerId, $folioId, 'MINIBAR', 'Minibar', 10_000_000, false);
        $balance = app(FolioRepository::class)->find($this->property(), $folioId)->balance->amountMinor;

        self::assertSame(['102', '103'], array_column($this->amend()->moveOptions($this->property(), $this->managerId, $this->stay['id']), 'number'));
        $moved = $this->amend()->moveRoom($this->property(), $this->managerId, $this->stay['id'], $this->roomIds[1], 'Noise from the lift', $this->stay['lock_version']);

        self::assertSame('102', $moved['room_number']);
        self::assertSame($this->roomIds[1], $moved['room_id']);
        self::assertSame($this->roomIds[1], DB::table('reservations')->where('id', $this->reservationId)->value('room_id'));
        // The balance is the guest's, not the room's.
        self::assertSame($balance, app(FolioRepository::class)->find($this->property(), $folioId)->balance->amountMinor);
        self::assertSame('dirty', DB::table('housekeeping_rooms')->where('room_id', $this->roomIds[0])->value('status'));
        self::assertSame(1, DB::table('housekeeping_tasks')->where('room_id', $this->roomIds[0])->where('source', 'stay_checkout')->count());
        self::assertSame(['Noise from the lift'], array_column($moved['moves'], 'reason'));
        self::assertSame([$this->roomIds[0], $this->roomIds[1]], [$moved['moves'][0]['from_room_id'], $moved['moves'][0]['to_room_id']]);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'stay.room_moved')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'frontoffice.stay.room_moved')->count());

        // The old room is free but dirty; the new one is occupied and cannot be given to anyone else.
        $next = $this->book('2026-10-01', '2026-10-02', 'confirmed');
        $offered = array_column(app(StayService::class)->availableRooms($this->property(), $this->managerId, $next->id), 'ready', 'number');
        self::assertSame(['101' => false, '103' => true], $offered);
        self::assertSame($this->roomIds[1], app(StayService::class)->view($this->property(), $this->managerId, $this->stay['id'])['room_id']);
    }

    public function test_a_move_is_refused_for_an_occupied_blocked_not_ready_or_unknown_room_and_needs_a_reason(): void
    {
        $this->checkIn('2026-10-01', '2026-10-02', 1);
        app(InventoryAdminService::class)->blockRoom($this->property(), $this->adminId, $this->roomIds[2], 'out_of_order', '2026-10-01', '2026-10-05', 'Leak');
        $lock = $this->stay['lock_version'];

        $this->assertRefused(409, fn () => $this->amend()->moveRoom($this->property(), $this->managerId, $this->stay['id'], $this->roomIds[1], 'x', $lock));
        $this->assertRefused(409, fn () => $this->amend()->moveRoom($this->property(), $this->managerId, $this->stay['id'], $this->roomIds[2], 'x', $lock));
        $this->assertRefused(422, fn () => $this->amend()->moveRoom($this->property(), $this->managerId, $this->stay['id'], $this->roomIds[0], 'x', $lock));
        $this->assertRefused(422, fn () => $this->amend()->moveRoom($this->property(), $this->managerId, $this->stay['id'], '01arz3ndektsv4rrffq69g5fb1', 'x', $lock));
        $this->assertRefused(422, fn () => $this->amend()->moveRoom($this->property(), $this->managerId, $this->stay['id'], $this->roomIds[2], '  ', $lock));
        $this->assertRefused(403, fn () => $this->amend()->moveRoom($this->property(), $this->viewerId, $this->stay['id'], $this->roomIds[2], 'x', $lock));
        self::assertSame($this->roomIds[0], DB::table('stays')->where('id', $this->stay['id'])->value('room_id'));

        // A room housekeeping has not made ready.
        app(InventoryAdminService::class)->releaseBlock($this->property(), $this->adminId, (string) DB::table('room_blocks')->value('id'), 'Fixed');
        DB::table('housekeeping_rooms')->insert(['room_id' => $this->roomIds[2], 'property_id' => self::PROPERTY, 'status' => 'dirty', 'status_changed_at' => now(), 'lock_version' => 0]);
        $this->assertRefused(409, fn () => $this->amend()->moveRoom($this->property(), $this->managerId, $this->stay['id'], $this->roomIds[2], 'x', $lock));
        $this->makeReady($this->roomIds[2]);
        $this->assertRefused(409, fn () => $this->amend()->moveRoom($this->property(), $this->managerId, $this->stay['id'], $this->roomIds[2], 'x', $lock + 5));
        self::assertSame('103', $this->amend()->moveRoom($this->property(), $this->managerId, $this->stay['id'], $this->roomIds[2], 'Ready now', $lock)['room_number']);
    }

    public function test_the_database_keeps_one_in_house_stay_per_room_through_a_move_and_the_history_is_append_only(): void
    {
        $other = $this->checkIn('2026-10-01', '2026-10-02', 1);
        $this->amend()->moveRoom($this->property(), $this->managerId, $this->stay['id'], $this->roomIds[2], 'Closer to the lift', $this->stay['lock_version']);

        try {
            DB::table('stays')->where('id', $other['id'])->update(['room_id' => $this->roomIds[2]]);
            self::fail('Two guests were put in one room.');
        } catch (QueryException $e) {
            self::assertStringContainsString('stays_one_in_house_per_room', $e->getMessage());
        }

        foreach ([fn () => DB::table('stay_room_moves')->update(['reason' => 'x']), fn () => DB::table('stay_room_moves')->delete()] as $mutation) {
            try {
                $mutation();
                self::fail('A move record changed.');
            } catch (QueryException $e) {
                self::assertStringContainsString('room move cannot be', $e->getMessage());
            }
        }
    }

    public function test_moving_to_a_room_of_another_type_shifts_the_inventory_and_keeps_the_agreed_price(): void
    {
        $suite = $this->suiteType();
        $suiteRoom = (string) DB::table('rooms')->where('number', '201')->value('id');
        $this->makeReady($suiteRoom);
        $nightsBefore = DB::table('reservation_nights')->where('reservation_id', $this->reservationId)->pluck('room_type_id')->unique()->all();
        self::assertSame([$this->typeId], $nightsBefore);

        $moved = $this->amend()->moveRoom($this->property(), $this->managerId, $this->stay['id'], $suiteRoom, 'Complimentary upgrade', $this->stay['lock_version']);

        self::assertSame('201', $moved['room_number']);
        self::assertSame([$suite], DB::table('reservation_nights')->where('reservation_id', $this->reservationId)->where('is_active', true)->pluck('room_type_id')->unique()->all());
        // The reservation keeps its booked type and price; night audit still charges what was agreed.
        self::assertSame($this->typeId, DB::table('reservations')->where('id', $this->reservationId)->value('room_type_id'));
        $this->clock->advance('+13 hours');
        app(NightAuditService::class)->run($this->property(), $this->managerId, [], IdempotencyKey::fromString('amend-audit-0000001'));
        $posting = DB::table('folio_postings')->where('source', 'night_audit')->first();
        self::assertSame(121_000_000, (int) $posting->total_minor);
        self::assertSame('Room 201, night of 2026-10-01', $posting->description);
    }

    public function test_a_move_to_a_type_that_is_sold_out_is_refused_and_leaves_everything_as_it_was(): void
    {
        $suite = $this->suiteType();
        $suiteRoom = (string) DB::table('rooms')->where('number', '201')->value('id');
        $this->makeReady($suiteRoom);
        $this->bookType($suite, '2026-10-02', '2026-10-03');

        $this->assertRefused(409, fn () => $this->amend()->moveRoom($this->property(), $this->managerId, $this->stay['id'], $suiteRoom, 'Upgrade', $this->stay['lock_version']));
        self::assertSame($this->roomIds[0], DB::table('stays')->where('id', $this->stay['id'])->value('room_id'));
        self::assertSame([$this->typeId], DB::table('reservation_nights')->where('reservation_id', $this->reservationId)->pluck('room_type_id')->unique()->all());
        self::assertSame(0, DB::table('stay_room_moves')->count());
    }

    public function test_an_extension_prices_only_the_added_nights_with_todays_rates_and_night_audit_charges_them(): void
    {
        $period = (string) DB::table('rate_periods')->value('id');
        app(RatePlanService::class)->repriceNight($this->property(), $this->adminId, $period, 150_000_000, 'Peak week');

        $quote = $this->amend()->quoteExtension($this->property(), $this->managerId, $this->stay['id'], '2026-10-05');
        self::assertTrue($quote['bookable']);
        self::assertSame(['2026-10-03', '2026-10-04'], array_column($quote['nights'], 'date'));
        self::assertSame(2 * 181_500_000, $quote['total_minor']);

        $extended = $this->amend()->extend($this->property(), $this->managerId, $this->stay['id'], '2026-10-05', 'The guest likes the hotel', $this->stay['lock_version']);

        self::assertSame('2026-10-05', $extended['expected_departure']);
        self::assertSame('2026-10-05', substr((string) DB::table('reservations')->where('id', $this->reservationId)->value('departure_date'), 0, 10));
        self::assertSame(4, DB::table('reservation_nights')->where('reservation_id', $this->reservationId)->where('is_active', true)->count());
        $amendment = DB::table('reservation_amendments')->first();
        self::assertSame([2 * 150_000_000, 2 * 15_000_000, 2 * 16_500_000, 2 * 181_500_000], [(int) $amendment->added_base_minor, (int) $amendment->added_service_charge_minor, (int) $amendment->added_tax_minor, (int) $amendment->added_total_minor]);
        // The booked price is untouched.
        self::assertSame(2 * 121_000_000, (int) DB::table('reservations')->where('id', $this->reservationId)->value('total_minor'));
        self::assertSame(1, DB::table('audit_entries')->where('action', 'stay.extended')->count());

        // Night audit charges the booked nights at the booked price and the added nights at the price they were given.
        foreach (range(1, 4) as $i) {
            $this->clock->advance($i === 1 ? '+13 hours' : '+24 hours');
            app(NightAuditService::class)->run($this->property(), $this->managerId, [], IdempotencyKey::fromString(sprintf('amend-audit-%07d', $i)));
        }

        self::assertSame([121_000_000, 121_000_000, 181_500_000, 181_500_000], DB::table('folio_postings')->where('source', 'night_audit')->orderBy('business_date')->pluck('total_minor')->map(fn ($v): int => (int) $v)->all());
    }

    public function test_extension_rules(): void
    {
        $lock = $this->stay['lock_version'];
        $this->assertRefused(422, fn () => $this->amend()->extend($this->property(), $this->managerId, $this->stay['id'], '2026-10-03', 'Same day', $lock));
        $this->assertRefused(422, fn () => $this->amend()->extend($this->property(), $this->managerId, $this->stay['id'], '2026-10-02', 'Earlier', $lock));
        $this->assertRefused(422, fn () => $this->amend()->extend($this->property(), $this->managerId, $this->stay['id'], 'tomorrow', 'x', $lock));
        $this->assertRefused(422, fn () => $this->amend()->extend($this->property(), $this->managerId, $this->stay['id'], '2026-10-04', '', $lock));
        $this->assertRefused(403, fn () => $this->amend()->extend($this->property(), $this->viewerId, $this->stay['id'], '2026-10-04', 'x', $lock));
        $this->assertRefused(409, fn () => $this->amend()->extend($this->property(), $this->managerId, $this->stay['id'], '2026-10-04', 'x', $lock + 3));
        // No price beyond the end of the price list.
        $this->assertRefused(422, fn () => $this->amend()->extend($this->property(), $this->managerId, $this->stay['id'], '2028-02-01', 'x', $lock));
        self::assertSame(0, DB::table('reservation_amendments')->count());

        $this->amend()->extend($this->property(), $this->managerId, $this->stay['id'], '2026-10-04', 'One more night', $lock);

        foreach ([fn () => DB::table('reservation_amendments')->update(['reason' => 'x']), fn () => DB::table('reservation_amendments')->delete()] as $mutation) {
            try {
                $mutation();
                self::fail('An amendment changed.');
            } catch (QueryException $e) {
                self::assertStringContainsString('amendment cannot be', $e->getMessage());
            }
        }
    }

    public function test_an_extension_is_refused_when_the_type_is_sold_out_or_the_room_is_blocked(): void
    {
        // Fill the type on the added night with other reservations.
        $this->book('2026-10-03', '2026-10-04', 'confirmed');
        $this->book('2026-10-03', '2026-10-04', 'confirmed');
        $this->book('2026-10-03', '2026-10-04', 'confirmed');
        $quote = $this->amend()->quoteExtension($this->property(), $this->managerId, $this->stay['id'], '2026-10-04');
        self::assertSame(['2026-10-03'], $quote['availability']);
        $this->assertRefused(409, fn () => $this->amend()->extend($this->property(), $this->managerId, $this->stay['id'], '2026-10-04', 'x', $this->stay['lock_version']));
        self::assertSame(0, DB::table('reservation_amendments')->count());
    }

    public function test_an_extension_is_refused_when_the_guests_room_is_out_of_service_for_the_added_nights(): void
    {
        app(InventoryAdminService::class)->blockRoom($this->property(), $this->adminId, $this->roomIds[0], 'out_of_service', '2026-10-03', '2026-10-06', 'Renovation');

        $this->assertRefused(409, fn () => $this->amend()->extend($this->property(), $this->managerId, $this->stay['id'], '2026-10-04', 'x', $this->stay['lock_version']));
        self::assertSame(0, DB::table('reservation_amendments')->count());
    }
}
