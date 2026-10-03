<?php

declare(strict_types=1);

namespace Tests\Integration\FrontOffice;

use App\Modules\FrontOffice\Application\Board\RoomBoardService;
use App\Modules\FrontOffice\Application\Requests\GuestRequestService;
use App\Modules\FrontOffice\Application\Stays\CheckInRequest;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\Housekeeping\Application\HousekeepingService;
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

/** FR-FO-030, FR-FO-016: what an in-house guest asks for, routed by department and shown on the room card. */
final class GuestRequestTest extends TestCase
{
    use BuildsHotel;
    use RefreshDatabase;
    use SignsInToProperty;

    private string $stayId;

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
        $this->stayId = $this->checkIn(0, 'Budi Santoso');
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    private function checkIn(int $room, string $name): string
    {
        $reservation = $this->book('2026-10-01', '2026-10-03', 'confirmed');

        return app(StayService::class)->checkIn(
            $this->property(), $this->managerId,
            new CheckInRequest($reservation->id, $this->roomIds[$room], $name, 'ID', 'ktp', '31740101019000'.$room.'1', null, null, 'Jl. Merdeka 1', 2, 0),
            IdempotencyKey::fromString('request-checkin-'.$room.'0001'),
        )['id'];
    }

    private function requests(): GuestRequestService
    {
        return app(GuestRequestService::class);
    }

    private function open(string $category = 'housekeeping', string $title = 'Two extra towels', bool $urgent = false, ?string $stay = null, ?string $who = null): array
    {
        return $this->requests()->open($this->property(), $who ?? $this->requestStaffId, $stay ?? $this->stayId, $category, $title, 'Room 101', $urgent);
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

    public function test_a_housekeeping_request_opens_the_rooms_housekeeping_task_and_is_announced(): void
    {
        $r = $this->open();

        self::assertSame(['REQ-000001', 'housekeeping', 'normal', 'open', 'open', '101'], [$r['number'], $r['category'], $r['priority'], $r['status'], $r['housekeeping_state'], $r['room']]);
        $task = DB::table('housekeeping_tasks')->where('id', $r['hk_task_id'])->first();
        self::assertSame(['request', 'guest_request', 'open'], [$task->kind, $task->source, $task->status]);
        self::assertSame('dirty', DB::table('housekeeping_rooms')->where('room_id', $this->roomIds[0])->value('status'));
        self::assertNotNull(DB::table('audit_entries')->where('action', 'guest_request.opened')->first());
        self::assertSame($r['id'], DB::table('outbox_messages')->where('event_type', 'frontoffice.guest_request.opened')->value('aggregate_id'));
    }

    public function test_a_second_request_for_the_same_room_joins_the_unfinished_task(): void
    {
        $first = $this->open();
        $second = $this->open('housekeeping', 'An extra pillow', true);

        self::assertSame($first['hk_task_id'], $second['hk_task_id']);
        self::assertSame(1, DB::table('housekeeping_tasks')->where('room_id', $this->roomIds[0])->count());
        self::assertSame(['REQ-000002', 'urgent'], [$second['number'], $second['priority']]);
    }

    public function test_requests_for_other_departments_are_followed_here_without_a_housekeeping_task(): void
    {
        $m = $this->open('maintenance', 'The air conditioner is noisy');
        $f = $this->open('food_beverage', 'Breakfast in the room at 6');

        self::assertSame([null, null], [$m['hk_task_id'], $f['hk_task_id']]);
        self::assertSame(0, DB::table('housekeeping_tasks')->count());
        self::assertEqualsCanonicalizing([$m['id'], $f['id']], DB::table('outbox_messages')->where('event_type', 'frontoffice.guest_request.opened')->pluck('aggregate_id')->all(), 'each request is announced for its department');
    }

    public function test_the_housekeepers_finishing_the_task_shows_the_request_as_done(): void
    {
        $r = $this->open();
        $hk = app(HousekeepingService::class);
        $task = $hk->start($this->property(), $this->attendantId, $r['hk_task_id'], 0);

        $now = $this->requests()->queue($this->property(), $this->requestViewerId, 'active', null, null, null)['requests'][0];
        self::assertSame(['in_progress', 'in_progress'], [$now['status'], $now['housekeeping_state']]);

        $hk->finish($this->property(), $this->attendantId, $r['hk_task_id'], $task['lock_version']);
        $done = $this->requests()->queue($this->property(), $this->requestViewerId, 'done', null, null, null)['requests'];
        self::assertSame(['done', 'open'], [$done[0]['status'], $done[0]['recorded_status']]);
        self::assertSame([], $this->requests()->queue($this->property(), $this->requestViewerId, 'active', null, null, null)['requests']);
    }

    public function test_a_request_is_started_completed_or_cancelled_by_staff_with_a_version_check(): void
    {
        $r = $this->open('maintenance', 'Leaking tap');

        $this->refused(fn () => $this->requests()->start($this->property(), $this->requestStaffId, $r['id'], 4), 409);
        $started = $this->requests()->start($this->property(), $this->requestStaffId, $r['id'], 0);
        self::assertSame(['in_progress', 1], [$started['status'], $started['lock_version']]);
        $this->refused(fn () => $this->requests()->start($this->property(), $this->requestStaffId, $r['id'], 1), 409);

        $done = $this->requests()->complete($this->property(), $this->requestStaffId, $r['id'], 1, 'Washer replaced');
        self::assertSame(['done', 'Washer replaced'], [$done['status'], $done['resolution']]);
        $this->refused(fn () => $this->requests()->complete($this->property(), $this->requestStaffId, $r['id'], 2, null), 409);
        $this->refused(fn () => $this->requests()->cancel($this->property(), $this->requestStaffId, $r['id'], 2, 'Too late'), 409);
        self::assertNotNull(DB::table('outbox_messages')->where('event_type', 'frontoffice.guest_request.done')->first());

        $c = $this->open('other', 'A taxi at 5');
        $this->refused(fn () => $this->requests()->cancel($this->property(), $this->requestStaffId, $c['id'], 0, ' '), 422);
        self::assertSame('cancelled', $this->requests()->cancel($this->property(), $this->requestStaffId, $c['id'], 0, 'The guest changed plans')['status']);

        try {
            DB::table('guest_requests')->where('id', $r['id'])->update(['resolution' => 'edited']);
            self::fail('A closed request was changed');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        try {
            DB::table('guest_requests')->where('id', $c['id'])->update(['title' => 'edited', 'status' => 'open']);
            self::fail('What the guest asked for was changed');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }

    public function test_only_in_house_guests_can_ask_and_input_is_checked(): void
    {
        $arriving = $this->book('2026-10-05', '2026-10-06', 'confirmed');
        $this->refused(fn () => $this->open('housekeeping', 'Towels', false, '01arz3ndektsv4rrffq69g5fax'), 404);
        $this->refused(fn () => $this->open('wizardry'), 422);
        $this->refused(fn () => $this->open('other', ' '), 422);
        $this->refused(fn () => $this->open('other', str_repeat('x', 121)), 422);
        $this->refused(fn () => $this->open('other', 'Taxi', false, null, $this->requestViewerId), 403);
        self::assertSame(0, DB::table('guest_requests')->count());
        self::assertNotNull($arriving->id);
    }

    public function test_the_queue_filters_by_room_category_and_status_and_the_viewer_cannot_act(): void
    {
        $other = $this->checkIn(1, 'John Smith');
        $a = $this->open('maintenance', 'Noisy AC', true);
        $this->open('housekeeping', 'Towels');
        $this->open('maintenance', 'Dripping tap', false, $other);

        $all = $this->requests()->queue($this->property(), $this->requestViewerId, 'active', null, null, null)['requests'];
        self::assertSame(['Noisy AC', 'Towels', 'Dripping tap'], array_column($all, 'title'), 'urgent first, then oldest');
        self::assertSame(['Noisy AC', 'Dripping tap'], array_column($this->requests()->queue($this->property(), $this->requestViewerId, 'active', 'maintenance', null, null)['requests'], 'title'));
        self::assertSame(['Dripping tap'], array_column($this->requests()->queue($this->property(), $this->requestViewerId, 'active', null, $this->roomIds[1], null)['requests'], 'title'));
        $this->refused(fn () => $this->requests()->queue($this->property(), $this->requestViewerId, 'weird', null, null, null), 422);
        $this->refused(fn () => $this->requests()->queue($this->property(), $this->managerId, 'active', null, null, null), 403);
        $this->refused(fn () => $this->requests()->start($this->property(), $this->requestViewerId, $a['id'], 0), 403);

        self::assertSame(['101', '102'], array_column($this->requests()->inHouse($this->property(), $this->requestViewerId), 'room'));
    }

    public function test_the_room_card_shows_the_open_requests_of_the_room(): void
    {
        $r = $this->open('maintenance', 'Noisy AC');
        $this->open('other', 'A taxi');

        $card = array_column(app(RoomBoardService::class)->board($this->property(), $this->managerId)['rooms'], null, 'number')['101'];
        self::assertSame(2, $card['open_requests']);
        self::assertSame(0, array_column(app(RoomBoardService::class)->board($this->property(), $this->managerId)['rooms'], null, 'number')['102']['open_requests']);

        $this->requests()->complete($this->property(), $this->requestStaffId, $r['id'], 0, null);
        self::assertSame(1, array_column(app(RoomBoardService::class)->board($this->property(), $this->managerId)['rooms'], null, 'number')['101']['open_requests']);
    }

    public function test_the_room_card_shows_what_the_guest_asked_for_and_why_a_room_cannot_be_sold(): void
    {
        $board = fn (): array => array_column(app(RoomBoardService::class)->board($this->property(), $this->managerId)['rooms'], null, 'number');
        self::assertSame([[], 'sellable'], [$board()['101']['service_flags'], $board()['101']['sellability']]);

        DB::table('room_service_flags')->insert(['id' => '01arz3ndektsv4rrffq69g5fe1', 'property_id' => $this->property()->toString(), 'room_id' => $this->roomIds[0], 'kind' => 'dnd', 'note' => null, 'started_at' => now(), 'started_by' => $this->managerId, 'ended_at' => null, 'ended_by' => null, 'lock_version' => 0]);
        $block = fn (string $id, string $room, string $kind): array => ['id' => $id, 'property_id' => '01arz3ndektsv4rrffq69g5fav', 'room_id' => $room, 'kind' => $kind, 'start_date' => '2026-10-01', 'end_date' => '2026-10-01', 'reason' => 'Leak', 'created_by' => $this->managerId, 'created_at' => now()];
        DB::table('room_blocks')->insert($block('01arz3ndektsv4rrffq69g5fb1', $this->roomIds[1], 'out_of_service'));

        self::assertSame(['dnd'], $board()['101']['service_flags']);
        self::assertSame('out_of_service', $board()['102']['sellability']);

        DB::table('room_blocks')->insert($block('01arz3ndektsv4rrffq69g5fb2', $this->roomIds[1], 'out_of_order'));
        self::assertSame('out_of_order', $board()['102']['sellability'], 'out of order wins over out of service');
    }
}
