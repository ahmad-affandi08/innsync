<?php

declare(strict_types=1);

namespace Tests\Integration\Housekeeping;

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

/** FR-HK-013, FR-HK-016, FR-HK-017: guest requests with a deadline, discrepancies between the front desk and housekeeping, service flags. */
final class HousekeepingFlagsTest extends TestCase
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
        $reservation = $this->book('2026-10-01', '2026-10-03', 'confirmed');
        $this->stayId = app(StayService::class)->checkIn(
            $this->property(), $this->managerId,
            new CheckInRequest($reservation->id, $this->roomIds[0], 'Budi Santoso', 'ID', 'ktp', '3174010101900001', null, null, 'Jl. Merdeka 1', 2, 0),
            IdempotencyKey::fromString('hkflags-checkin-0001'),
        )['id'];
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    private function hk(): HousekeepingService
    {
        return app(HousekeepingService::class);
    }

    private function room(string $number = '101'): array
    {
        return array_column($this->hk()->board($this->property(), $this->hkSupervisorId)['rooms'], null, 'number')[$number];
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

    public function test_a_guest_request_shows_on_the_room_with_its_deadline_and_turns_overdue(): void
    {
        app(GuestRequestService::class)->open($this->property(), $this->requestStaffId, $this->stayId, 'housekeeping', 'Two extra towels', 'Bathroom', true, null, 30);
        app(GuestRequestService::class)->open($this->property(), $this->requestStaffId, $this->stayId, 'maintenance', 'Noisy AC', null, false, null, 30);

        $requests = $this->room()['requests'];
        self::assertSame(['Two extra towels'], array_column($requests, 'title'), 'only housekeeping requests');
        self::assertSame(['urgent', false], [$requests[0]['priority'], $requests[0]['overdue']]);
        self::assertSame('2026-10-01T03:30:00Z', $requests[0]['due_at']);

        $this->clock->advance('+31 minutes');
        self::assertTrue($this->room()['requests'][0]['overdue']);

        $this->refused(fn () => app(GuestRequestService::class)->open($this->property(), $this->requestStaffId, $this->stayId, 'housekeeping', 'x', null, false, null, 2), 422);
        $task = $this->hk()->myTasks($this->property(), $this->attendantId);
        self::assertSame([], $task);
    }

    public function test_service_flags_are_recorded_with_times_and_never_change_occupancy_or_the_cleaning_status(): void
    {
        $roomId = $this->roomIds[0];
        $before = $this->room()['status'];

        $dnd = $this->hk()->raiseFlag($this->property(), $this->attendantId, $roomId, 'dnd', 'Sleeping late');
        self::assertSame(['dnd', 'Sleeping late', null], [$dnd['kind'], $dnd['note'], $dnd['ended_at']]);
        self::assertSame([true, $before], [$this->room()['occupied'], $this->room()['status']]);
        self::assertSame(['dnd'], array_column($this->room()['flags'], 'kind'));
        $this->refused(fn () => $this->hk()->raiseFlag($this->property(), $this->attendantId, $roomId, 'dnd', null), 409);

        $refused = $this->hk()->raiseFlag($this->property(), $this->attendantId, $roomId, 'refused_service', 'Guest declined the make-up');
        self::assertSame($refused['started_at'], $refused['ended_at'], 'refused service is a moment');
        self::assertSame(['dnd'], array_column($this->room()['flags'], 'kind'), 'it does not stay open');

        $ended = $this->hk()->endFlag($this->property(), $this->attendantId, $dnd['id'], 0);
        self::assertNotNull($ended['ended_at']);
        self::assertSame([], $this->room()['flags']);
        $this->refused(fn () => $this->hk()->endFlag($this->property(), $this->attendantId, $dnd['id'], 1), 409);

        $history = $this->hk()->flagHistory($this->property(), $this->hkSupervisorId, $roomId);
        self::assertSame(['refused_service', 'dnd'], array_column($history, 'kind'));
        self::assertSame(2, DB::table('outbox_messages')->where('event_type', 'housekeeping.room.flag_raised')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'housekeeping.room.flag_ended')->count());

        try {
            DB::table('room_service_flags')->where('id', $dnd['id'])->update(['note' => 'edited']);
            self::fail('A flag was rewritten');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }

    public function test_do_not_disturb_keeps_housekeepers_out_until_it_ends(): void
    {
        $roomId = $this->roomIds[0];
        $task = $this->hk()->requestService($this->property(), $this->hkSupervisorId, $roomId, 'stayover', 'Stay-over service', $this->attendantId);
        $this->hk()->raiseFlag($this->property(), $this->hkSupervisorId, $roomId, 'privacy', 'Working in the room');

        $this->refused(fn () => $this->hk()->start($this->property(), $this->attendantId, $task['id'], $task['lock_version']), 409);

        $flag = array_column(array_column($this->hk()->board($this->property(), $this->hkSupervisorId)['rooms'], null, 'number')['101']['flags'], null, 'kind')['privacy'];
        $this->hk()->endFlag($this->property(), $this->hkSupervisorId, $flag['id'], $flag['lock_version']);
        self::assertSame('in_progress', $this->hk()->start($this->property(), $this->attendantId, $task['id'], $task['lock_version'])['status']);
    }

    public function test_a_make_up_request_asks_housekeeping_to_service_the_room(): void
    {
        $this->hk()->raiseFlag($this->property(), $this->attendantId, $this->roomIds[0], 'make_up_room', 'Please make up the room');

        self::assertSame(['request'], DB::table('housekeeping_tasks')->where('room_id', $this->roomIds[0])->pluck('kind')->all());
        self::assertSame('dirty', $this->room()['status']);
    }

    public function test_flags_are_for_rooms_with_a_guest_and_for_people_who_service_rooms(): void
    {
        $this->refused(fn () => $this->hk()->raiseFlag($this->property(), $this->attendantId, $this->roomIds[1], 'dnd', null), 409);
        $this->refused(fn () => $this->hk()->raiseFlag($this->property(), $this->attendantId, $this->roomIds[0], 'nap', null), 422);
        $this->refused(fn () => $this->hk()->raiseFlag($this->property(), $this->attendantId, $this->roomIds[0], 'dnd', str_repeat('x', 201)), 422);
        $this->refused(fn () => $this->hk()->raiseFlag($this->property(), $this->managerId, $this->roomIds[0], 'dnd', null), 403);
        $this->refused(fn () => $this->hk()->raiseFlag($this->property(), $this->attendantId, '01arz3ndektsv4rrffq69g5fax', 'dnd', null), 404);
        self::assertSame(0, DB::table('room_service_flags')->count());
    }

    public function test_the_board_points_out_where_the_front_desk_and_housekeeping_disagree(): void
    {
        $board = $this->hk()->board($this->property(), $this->hkSupervisorId);
        self::assertSame([], $board['discrepancies']);

        // Housekeeping works on the occupied room 101 as if it were vacant, and treats vacant 102 as if a guest were in it.
        $vacant = $this->hk()->requestService($this->property(), $this->hkSupervisorId, $this->roomIds[0], 'vacant', 'Mistaken task');
        $this->hk()->requestService($this->property(), $this->hkSupervisorId, $this->roomIds[1], 'stayover', 'Mistaken stay-over');

        $found = array_column($this->hk()->board($this->property(), $this->hkSupervisorId)['discrepancies'], null, 'number');
        self::assertSame(['101' => 'occupied_with_vacant_task', '102' => 'vacant_with_stayover_task'], array_map(static fn (array $d): string => $d['rule'], $found));
        self::assertSame($vacant['id'], $found['101']['task_id']);

        $this->hk()->cancelTask($this->property(), $this->hkSupervisorId, $vacant['id'], 'Guest is in the room', $found['101']['task_lock_version']);
        self::assertSame(['102'], array_column($this->hk()->board($this->property(), $this->hkSupervisorId)['discrepancies'], 'number'));
    }
}
