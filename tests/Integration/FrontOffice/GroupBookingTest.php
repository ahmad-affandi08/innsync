<?php

declare(strict_types=1);

namespace Tests\Integration\FrontOffice;

use App\Modules\FrontOffice\Application\BookingRefused;
use App\Modules\FrontOffice\Application\Folios\FolioRepository;
use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Groups\GroupBookingService;
use App\Modules\FrontOffice\Application\NightAudit\NightAuditService;
use App\Modules\FrontOffice\Application\Stays\CheckInRequest;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
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

/** FR-FO-006: a simple group booking, one booker with several rooms, optionally one master folio. */
final class GroupBookingTest extends TestCase
{
    use BuildsHotel;
    use RefreshDatabase;
    use SignsInToProperty;

    private int $key = 0;

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
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    private function bookings(): GroupBookingService
    {
        return app(GroupBookingService::class);
    }

    private function key(): IdempotencyKey
    {
        return IdempotencyKey::fromString(sprintf('group-test-key-%06d', ++$this->key));
    }

    private function refused(callable $do, int $status): void
    {
        try {
            $do();
            self::fail('Expected a refusal');
        } catch (Refusal $e) {
            self::assertSame($status, $e->status());
        }
    }

    private function soldOut(callable $do): void
    {
        try {
            $do();
            self::fail('Expected the booking to be refused');
        } catch (BookingRefused $e) {
            self::assertSame('no_availability', $e->reason);
        }
    }

    /** @return array<string, mixed> */
    private function data(string $mode = 'master', array $extra = []): array
    {
        return [...['name' => 'Wedding Rahma', 'booker_name' => 'Rahma Putri', 'booker_phone' => '+62 812 0000', 'booker_email' => 'rahma@example.com', 'source' => 'phone', 'arrival' => '2026-10-01', 'departure' => '2026-10-03', 'billing_mode' => $mode, 'route_extras' => false, 'notes' => 'Family', 'status' => 'confirmed'], ...$extra];
    }

    /** @return list<array<string, mixed>> */
    private function rooms(int $count, ?string $firstGuest = null): array
    {
        $rooms = [];

        for ($i = 0; $i < $count; $i++) {
            $rooms[] = ['room_type_id' => $this->typeId, 'rate_plan_id' => $this->planId, 'adults' => 2, 'children' => 0, 'guest_name' => $i === 0 ? $firstGuest : null];
        }

        return $rooms;
    }

    /** @return list<array{id: string, label: string, window: int, balance: int, closed: bool}> */
    private function folios(string $reservation): array
    {
        return array_map(static fn ($f): array => ['id' => $f->id, 'label' => $f->label, 'window' => $f->window, 'balance' => $f->balance->amountMinor, 'closed' => $f->isClosed], app(FolioRepository::class)->byReservation($this->property(), $reservation));
    }

    /** @return array<string, mixed> */
    private function checkIn(string $reservation, int $room): array
    {
        return app(StayService::class)->checkIn($this->property(), $this->managerId, new CheckInRequest($reservation, $this->roomIds[$room], 'Guest '.($room + 1), 'ID', 'ktp', sprintf('31740101019%05d', $room + 1), null, null, 'Jl. Merdeka 1', 2, 0), IdempotencyKey::fromString(sprintf('group-checkin-%05d', ++$this->key)));
    }

    private function closeDay(): void
    {
        $this->clock->advance('+13 hours');
        app(NightAuditService::class)->run($this->property(), $this->managerId, [], IdempotencyKey::fromString(sprintf('group-audit-%06d', ++$this->key)));
    }

    public function test_a_group_makes_one_reservation_per_room_with_a_master_folio_on_the_first(): void
    {
        $view = $this->bookings()->create($this->property(), $this->groupManagerId, $this->data(), $this->rooms(2, 'Siti Aminah'), $this->key());

        self::assertSame(['GRP-000001', 'Wedding Rahma', 'master', 2], [$view['group']['number'], $view['group']['name'], $view['group']['billing_mode'], $view['totals']['rooms']]);
        self::assertSame([1, 2], array_column($view['members'], 'line'));
        self::assertSame(['Siti Aminah', 'Rahma Putri'], array_column($view['members'], 'guest_name'));
        self::assertSame(['confirmed', 'confirmed'], array_column($view['members'], 'status'));
        self::assertGreaterThan(0, $view['totals']['stay_minor']);

        $lead = $view['members'][0]['reservation_id'];
        self::assertSame([['Guest', 1], ['GRP-000001', 2]], array_map(static fn (array $f): array => [$f['label'], $f['window']], $this->folios($lead)));
        self::assertSame([], $this->folios($view['members'][1]['reservation_id']));
        self::assertSame($this->folios($lead)[1]['id'], $view['master']['folio_id']);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'group.created')->where('aggregate_id', $view['group']['id'])->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'frontoffice.group.created')->where('aggregate_id', $view['group']['id'])->count());
        self::assertSame('Group GRP-000001 · Wedding Rahma', DB::table('reservations')->where('id', $lead)->value('notes'));

        $on = $this->bookings()->forReservation($this->property(), $this->groupViewerId, $view['members'][1]['reservation_id']);
        self::assertSame(['GRP-000001', 'master', $view['master']['folio_id']], [$on['number'], $on['billing_mode'], $on['master_folio_id']]);
        self::assertNull($this->bookings()->forReservation($this->property(), $this->managerId, $lead));
    }

    public function test_with_a_bill_per_room_there_is_no_master_folio(): void
    {
        $view = $this->bookings()->create($this->property(), $this->groupManagerId, $this->data('per_room', ['route_extras' => true]), $this->rooms(2), $this->key());

        self::assertNull($view['master']);
        self::assertFalse($view['group']['route_extras']);
        self::assertSame([], $this->folios($view['members'][0]['reservation_id']));
        self::assertSame(0, DB::table('group_master_folios')->count());
    }

    public function test_it_is_validated_and_needs_the_right(): void
    {
        $this->refused(fn () => $this->bookings()->create($this->property(), $this->groupViewerId, $this->data(), $this->rooms(1), $this->key()), 403);
        $this->refused(fn () => $this->bookings()->create($this->property(), $this->managerId, $this->data(), $this->rooms(1), $this->key()), 403);
        $this->refused(fn () => $this->bookings()->create($this->property(), $this->groupManagerId, $this->data('shared'), $this->rooms(1), $this->key()), 422);
        $this->refused(fn () => $this->bookings()->create($this->property(), $this->groupManagerId, $this->data('master', ['name' => ' ']), $this->rooms(1), $this->key()), 422);
        $this->refused(fn () => $this->bookings()->create($this->property(), $this->groupManagerId, $this->data('master', ['arrival' => '2026-10-03', 'departure' => '2026-10-03']), $this->rooms(1), $this->key()), 422);
        $this->refused(fn () => $this->bookings()->create($this->property(), $this->groupManagerId, $this->data('master', ['status' => 'checked_in']), $this->rooms(1), $this->key()), 422);
        $this->refused(fn () => $this->bookings()->create($this->property(), $this->groupManagerId, $this->data('master', ['booker_email' => 'nope']), $this->rooms(1), $this->key()), 422);
        $this->refused(fn () => $this->bookings()->create($this->property(), $this->groupManagerId, $this->data(), [], $this->key()), 422);
        $this->refused(fn () => $this->bookings()->create($this->property(), $this->groupManagerId, $this->data(), $this->rooms(GroupBookingService::MAX_ROOMS + 1), $this->key()), 422);
        $bad = $this->rooms(1);
        $bad[0]['adults'] = 0;
        $this->refused(fn () => $this->bookings()->create($this->property(), $this->groupManagerId, $this->data(), $bad, $this->key()), 422);
        self::assertSame(0, DB::table('reservation_groups')->count());

        $this->refused(fn () => $this->bookings()->overview($this->property(), $this->managerId), 403);
        $this->refused(fn () => $this->bookings()->view($this->property(), $this->groupViewerId, str_repeat('0', 26)), 404);
    }

    public function test_the_whole_group_is_made_or_none_of_it(): void
    {
        // Only three Deluxe rooms exist.
        $this->soldOut(fn () => $this->bookings()->create($this->property(), $this->groupManagerId, $this->data(), $this->rooms(4), $this->key()));

        self::assertSame([0, 0, 0, 0], [DB::table('reservation_groups')->count(), DB::table('reservation_group_members')->count(), DB::table('reservations')->count(), DB::table('folios')->count()]);
    }

    public function test_a_retry_with_the_same_key_makes_the_group_once(): void
    {
        $key = $this->key();
        $a = $this->bookings()->create($this->property(), $this->groupManagerId, $this->data(), $this->rooms(2), $key);
        $b = $this->bookings()->create($this->property(), $this->groupManagerId, $this->data(), $this->rooms(2), $key);

        self::assertSame($a['group']['id'], $b['group']['id']);
        self::assertSame([1, 2], [DB::table('reservation_groups')->count(), DB::table('reservations')->count()]);
    }

    public function test_rooms_can_be_added_later_for_the_dates_of_the_group(): void
    {
        $view = $this->bookings()->create($this->property(), $this->groupManagerId, $this->data(), $this->rooms(1), $this->key());
        $this->refused(fn () => $this->bookings()->addRooms($this->property(), $this->groupViewerId, $view['group']['id'], $this->rooms(1), $this->key()), 403);
        $this->refused(fn () => $this->bookings()->addRooms($this->property(), $this->groupManagerId, str_repeat('0', 26), $this->rooms(1), $this->key()), 404);

        $key = $this->key();
        $more = $this->bookings()->addRooms($this->property(), $this->groupManagerId, $view['group']['id'], $this->rooms(2, 'Budi'), $key);
        $again = $this->bookings()->addRooms($this->property(), $this->groupManagerId, $view['group']['id'], $this->rooms(2, 'Budi'), $key);

        self::assertSame([1, 2, 3], array_column($more['members'], 'line'));
        self::assertSame(3, count($again['members']));
        self::assertSame(['2026-10-01', '2026-10-01', '2026-10-01'], array_column($more['members'], 'arrival'));
        // The third room of the group is the last Deluxe room: a fourth is refused and changes nothing.
        $this->soldOut(fn () => $this->bookings()->addRooms($this->property(), $this->groupManagerId, $view['group']['id'], $this->rooms(1), $this->key()));
        self::assertSame(3, DB::table('reservation_group_members')->count());
        self::assertSame(1, DB::table('audit_entries')->where('action', 'group.rooms_added')->count());
    }

    public function test_the_room_charges_of_every_room_go_to_the_master_folio(): void
    {
        $view = $this->bookings()->create($this->property(), $this->groupManagerId, $this->data(), $this->rooms(2), $this->key());
        [$one, $two] = array_column($view['members'], 'reservation_id');
        $this->checkIn($one, 0);
        $this->checkIn($two, 1);
        $this->closeDay();

        [$guest, $master] = $this->folios($one);
        self::assertSame(0, $guest['balance']);
        self::assertGreaterThan(0, $master['balance']);
        // Two rooms for one night each: twice the price of one night, nothing on the other room's own folio.
        self::assertSame(2, DB::table('folio_postings')->where('folio_id', $master['id'])->where('source', 'night_audit')->count());
        self::assertSame($master['balance'], $this->bookings()->view($this->property(), $this->groupViewerId, $view['group']['id'])['master']['balance_minor']);
        $second = $this->folios($two);
        self::assertSame([0], array_column($second, 'balance'));
    }

    public function test_extras_follow_the_master_only_when_the_group_says_so(): void
    {
        app(ChargeSchemeService::class)->define($this->property(), $this->adminId, 'laundry', '2026-10-01', '10', '10', true, 'Regional regulation');
        $plain = $this->bookings()->create($this->property(), $this->groupManagerId, $this->data('master', ['route_extras' => false]), $this->rooms(1), $this->key());
        $all = $this->bookings()->create($this->property(), $this->groupManagerId, $this->data('master', ['name' => 'Tour', 'route_extras' => true]), $this->rooms(1), $this->key());
        $a = $plain['members'][0]['reservation_id'];
        $b = $all['members'][0]['reservation_id'];
        $this->checkIn($a, 0);
        $this->checkIn($b, 1);

        app(FolioService::class)->postGuestCharge($this->property(), $this->managerId, $a, 'laundry', 'LAUNDRY', 'Laundry', 50_000_000, 'laundry', 'ldy:group-a');
        app(FolioService::class)->postGuestCharge($this->property(), $this->managerId, $b, 'laundry', 'LAUNDRY', 'Laundry', 50_000_000, 'laundry', 'ldy:group-b');

        [$aGuest, $aMaster] = $this->folios($a);
        [$bGuest, $bMaster] = $this->folios($b);
        self::assertSame([true, 0], [$aGuest['balance'] > 0, $aMaster['balance']]);
        self::assertSame([0, true], [$bGuest['balance'], $bMaster['balance'] > 0]);
    }

    public function test_the_master_folio_may_stay_open_with_a_balance_after_the_guests_leave(): void
    {
        $view = $this->bookings()->create($this->property(), $this->groupManagerId, $this->data(), $this->rooms(2), $this->key());
        [$one, $two] = array_column($view['members'], 'reservation_id');
        $stayOne = $this->checkIn($one, 0)['id'];
        $this->checkIn($two, 1);
        $this->closeDay();
        $owed = $this->folios($one)[1]['balance'];

        app(StayService::class)->checkOut($this->property(), $this->managerId, $stayOne, 0);

        [$guest, $master] = $this->folios($one);
        self::assertSame([true, 0], [$guest['closed'], $guest['balance']]);
        self::assertSame([false, $owed], [$master['closed'], $master['balance']]);

        app(FolioService::class)->pay($this->property(), $this->managerId, $master['id'], 'bank_transfer', $owed, 'TRF-1', 'settlement');
        self::assertSame(0, $this->folios($one)[1]['balance']);
    }

    public function test_a_guest_folio_balance_still_blocks_checkout_in_a_group(): void
    {
        $view = $this->bookings()->create($this->property(), $this->groupManagerId, $this->data(), $this->rooms(1), $this->key());
        $one = $view['members'][0]['reservation_id'];
        $stay = $this->checkIn($one, 0)['id'];
        app(FolioService::class)->charge($this->property(), $this->managerId, $this->folios($one)[0]['id'], 'MINIBAR', 'Minibar', 10_000_000, false);

        $this->refused(fn () => app(StayService::class)->checkOut($this->property(), $this->managerId, $stay, 0), 409);
    }

    public function test_the_group_records_cannot_be_rewritten(): void
    {
        $this->bookings()->create($this->property(), $this->groupManagerId, $this->data(), $this->rooms(1), $this->key());

        foreach (['reservation_groups' => 'name', 'reservation_group_members' => 'line', 'group_master_folios' => 'created_at'] as $table => $column) {
            foreach ([fn () => DB::table($table)->update([$column => $column === 'line' ? 9 : ($column === 'created_at' ? now() : 'x')]), fn () => DB::table($table)->delete()] as $do) {
                try {
                    $do();
                    self::fail("{$table} must be append-only");
                } catch (QueryException $e) {
                    self::assertStringContainsString('cannot be', $e->getMessage());
                }
            }
        }
    }
}
