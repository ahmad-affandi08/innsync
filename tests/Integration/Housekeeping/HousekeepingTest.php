<?php

declare(strict_types=1);

namespace Tests\Integration\Housekeeping;

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

final class HousekeepingTest extends TestCase
{
    use BuildsHotel;
    use RefreshDatabase;
    use SignsInToProperty;

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

    /** Checks a guest into room `$room` and out again, which leaves the room dirty with a departure task. @return string the room id */
    private function vacate(int $room = 0): string
    {
        $reservation = $this->book('2026-10-01', '2026-10-02', 'confirmed');
        $stay = app(StayService::class)->checkIn(
            $this->property(),
            $this->managerId,
            new CheckInRequest($reservation->id, $this->roomIds[$room], 'Budi Santoso', 'ID', 'ktp', sprintf('31740101019%05d', ++$this->n), null, null, 'Jl. Merdeka 1', 2, 0),
            IdempotencyKey::fromString(sprintf('hk-checkin-%07d', $this->n)),
        );
        app(StayService::class)->checkOut($this->property(), $this->managerId, $stay['id'], $stay['lock_version']);

        return $this->roomIds[$room];
    }

    /** @return array<string, mixed> */
    private function taskOf(string $roomId): array
    {
        $row = DB::table('housekeeping_tasks')->where('room_id', $roomId)->whereIn('status', ['open', 'assigned', 'in_progress'])->first();

        return ['id' => $row->id, 'lock_version' => (int) $row->lock_version, 'kind' => $row->kind, 'status' => $row->status, 'assigned_to' => $row->assigned_to];
    }

    private function roomStatus(string $roomId): string
    {
        return (string) DB::table('housekeeping_rooms')->where('room_id', $roomId)->value('status');
    }

    private function assertRefused(int $status, callable $action): void
    {
        try {
            $action();
            self::fail('Expected a refusal.');
        } catch (Refusal $e) {
            self::assertSame($status, $e->status());
        }
    }

    /** Runs a room from dirty to clean with the given attendant. */
    private function clean(string $roomId, ?string $attendant = null): void
    {
        $attendant ??= $this->attendantId;
        $task = $this->taskOf($roomId);

        if ($task['status'] === 'open') {
            $task = $this->hk()->assign($this->property(), $this->hkSupervisorId, $task['id'], $attendant, $task['lock_version']);
        }

        $started = $this->hk()->start($this->property(), $attendant, $task['id'], $task['lock_version']);
        $this->hk()->finish($this->property(), $attendant, $started['id'], $started['lock_version']);
    }

    public function test_check_out_makes_the_room_dirty_with_a_departure_task_once(): void
    {
        $room = $this->vacate();

        self::assertSame('dirty', $this->roomStatus($room));
        $task = $this->taskOf($room);
        self::assertSame('departure', $task['kind']);
        self::assertSame('open', $task['status']);
        self::assertSame(2, (int) DB::table('housekeeping_tasks')->value('priority'));
        self::assertSame(1, DB::table('housekeeping_status_log')->where('room_id', $room)->where('to_status', 'dirty')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'housekeeping.room.status_changed')->count());

        // The same hand-over delivered again changes nothing, even after the room was cleaned meanwhile.
        $stayId = (string) DB::table('stays')->value('id');
        $this->clean($room);
        $this->hk()->vacated($this->property(), $room, $stayId, $this->managerId);
        self::assertSame('clean', $this->roomStatus($room));
        self::assertSame(1, DB::table('housekeeping_tasks')->count());
    }

    public function test_a_room_that_is_not_ready_cannot_be_given_to_a_guest_until_it_is_inspected(): void
    {
        $room = $this->vacate();
        $next = $this->book('2026-10-01', '2026-10-02', 'confirmed')->id;
        $form = fn () => app(StayService::class)->checkIn($this->property(), $this->managerId, new CheckInRequest($next, $room, 'Siti', 'ID', 'ktp', '3174010101900099', null, null, 'Jl. Sudirman 2', 1, 0), IdempotencyKey::fromString(sprintf('hk-gate-%09d', ++$this->n)));

        $offered = app(StayService::class)->availableRooms($this->property(), $this->managerId, $next);
        self::assertSame(['101' => false, '102' => true, '103' => true], array_column(array_map(static fn (array $r): array => ['n' => $r['number'], 'r' => $r['ready']], $offered), 'r', 'n'));

        foreach (['dirty', 'cleaning', 'clean'] as $stage) {
            $this->assertRefused(409, $form);

            if ($stage === 'dirty') {
                $task = $this->taskOf($room);
                $task = $this->hk()->assign($this->property(), $this->hkSupervisorId, $task['id'], $this->attendantId, $task['lock_version']);
                $started = $this->hk()->start($this->property(), $this->attendantId, $task['id'], $task['lock_version']);
                self::assertSame('cleaning', $this->roomStatus($room));
            } elseif ($stage === 'cleaning') {
                $done = $this->hk()->finish($this->property(), $this->attendantId, $started['id'], $started['lock_version']);
                self::assertSame('clean', $done['room_status']);
            }
        }

        $result = $this->hk()->inspect($this->property(), $this->hkSupervisorId, $room, true, [], null);
        self::assertSame('ready', $result['room_status']);
        self::assertSame('Siti', $form()['guest']['full_name']);
    }

    public function test_the_attendant_cycle_records_who_when_and_for_how_long(): void
    {
        $room = $this->vacate();
        $task = $this->taskOf($room);

        // Someone else may not start a task assigned to another attendant; a supervisor may not clean.
        $assigned = $this->hk()->assign($this->property(), $this->hkSupervisorId, $task['id'], $this->attendantId, $task['lock_version']);
        $this->assertRefused(409, fn () => $this->hk()->start($this->property(), $this->attendant2Id, $task['id'], $assigned['lock_version']));
        $this->assertRefused(403, fn () => $this->hk()->start($this->property(), $this->hkSupervisorId, $task['id'], $assigned['lock_version']));
        $this->assertRefused(409, fn () => $this->hk()->start($this->property(), $this->attendantId, $task['id'], 0));

        $this->clock->advance('+5 minutes');
        $started = $this->hk()->start($this->property(), $this->attendantId, $task['id'], $assigned['lock_version']);
        self::assertSame('in_progress', $started['status']);
        $this->assertRefused(409, fn () => $this->hk()->finish($this->property(), $this->attendant2Id, $task['id'], $started['lock_version']));
        $this->clock->advance('+25 minutes');
        $done = $this->hk()->finish($this->property(), $this->attendantId, $task['id'], $started['lock_version']);

        self::assertSame(1500, $done['duration_seconds']);
        self::assertSame('clean', $this->roomStatus($room));
        self::assertSame(['dirty', 'cleaning', 'clean'], DB::table('housekeeping_status_log')->where('room_id', $room)->orderBy('occurred_at')->orderBy('id')->pluck('to_status')->all());
        // A finished task cannot be started again, reassigned or cancelled.
        $this->assertRefused(409, fn () => $this->hk()->start($this->property(), $this->attendantId, $task['id'], $done['lock_version']));
        $this->assertRefused(409, fn () => $this->hk()->cancelTask($this->property(), $this->hkSupervisorId, $task['id'], 'Mistake', $done['lock_version']));
        self::assertSame(1, DB::table('audit_entries')->where('action', 'housekeeping.task.finished')->count());
    }

    public function test_an_open_task_is_taken_by_whoever_starts_it(): void
    {
        $room = $this->vacate();
        $task = $this->taskOf($room);

        $started = $this->hk()->start($this->property(), $this->attendant2Id, $task['id'], $task['lock_version']);

        self::assertSame($this->attendant2Id, $started['assigned_to']);
        $this->assertRefused(409, fn () => $this->hk()->start($this->property(), $this->attendantId, $task['id'], $started['lock_version']));
    }

    public function test_without_required_inspection_a_finished_room_is_ready_at_once(): void
    {
        $this->hk()->setInspectionRequired($this->property(), $this->hkSupervisorId, false, 0, 'Small property, the supervisor checks by eye');
        $room = $this->vacate();

        $this->clean($room);

        self::assertSame('ready', $this->roomStatus($room));
        self::assertSame(1, DB::table('audit_entries')->where('action', 'housekeeping.settings.changed')->count());
        $this->assertRefused(409, fn () => $this->hk()->setInspectionRequired($this->property(), $this->hkSupervisorId, true, 0, 'Stale'));
        $this->assertRefused(403, fn () => $this->hk()->setInspectionRequired($this->property(), $this->attendantId, true, 1, 'No'));
    }

    public function test_a_failed_inspection_sends_the_room_to_rework_and_it_needs_every_mandatory_finding_closed(): void
    {
        $room = $this->vacate();
        $this->clean($room);

        $this->assertRefused(422, fn () => $this->hk()->inspect($this->property(), $this->hkSupervisorId, $room, false, [], null));
        $this->assertRefused(422, fn () => $this->hk()->inspect($this->property(), $this->hkSupervisorId, $room, true, [['description' => 'x']], null));
        $this->assertRefused(403, fn () => $this->hk()->inspect($this->property(), $this->attendantId, $room, true, [], null));

        $failed = $this->hk()->inspect($this->property(), $this->hkSupervisorId, $room, false, [['description' => 'Hair in the shower'], ['description' => 'Dusty shelf', 'mandatory' => false]], 'Second floor');
        self::assertSame('rework', $failed['room_status']);
        self::assertSame('rework', $this->roomStatus($room));
        $rework = $this->taskOf($room);
        self::assertSame('rework', $rework['kind']);
        self::assertSame('assigned', $rework['status']);
        self::assertSame($this->attendantId, $rework['assigned_to']);
        self::assertSame(1, (int) DB::table('housekeeping_tasks')->where('id', $rework['id'])->value('priority'));
        // A room in rework is not inspected again until it has been cleaned again.
        $this->assertRefused(409, fn () => $this->hk()->inspect($this->property(), $this->hkSupervisorId, $room, true, [], null));

        $mine = $this->hk()->myTasks($this->property(), $this->attendantId);
        self::assertCount(1, $mine);
        self::assertSame(['Hair in the shower', 'Dusty shelf'], array_column($mine[0]['findings'], 'description'));

        $started = $this->hk()->start($this->property(), $this->attendantId, $rework['id'], $rework['lock_version']);
        $this->hk()->finish($this->property(), $this->attendantId, $rework['id'], $started['lock_version']);
        self::assertSame('clean', $this->roomStatus($room));

        // Passing needs the mandatory finding resolved or waived by someone allowed to.
        $this->assertRefused(409, fn () => $this->hk()->inspect($this->property(), $this->hkSupervisorId, $room, true, [], null));
        $findings = $this->hk()->room($this->property(), $this->hkSupervisorId, $room)['open_findings'];
        $mandatory = array_values(array_filter($findings, static fn (array $f): bool => $f['mandatory']))[0];
        $this->assertRefused(403, fn () => $this->hk()->waiveFinding($this->property(), $this->hkSupervisorId, $mandatory['id'], 'Accepted'));
        $this->assertRefused(422, fn () => $this->hk()->waiveFinding($this->property(), $this->hkChiefId, $mandatory['id'], '  '));
        $this->hk()->waiveFinding($this->property(), $this->hkChiefId, $mandatory['id'], 'Guest arrives late, fixed before then');
        $this->assertRefused(409, fn () => $this->hk()->waiveFinding($this->property(), $this->hkChiefId, $mandatory['id'], 'Again'));

        $passed = $this->hk()->inspect($this->property(), $this->hkSupervisorId, $room, true, [], null);
        self::assertSame('ready', $passed['room_status']);
        // The not-mandatory finding was closed with the pass; nothing stays open.
        self::assertSame([], $this->hk()->room($this->property(), $this->hkSupervisorId, $room)['open_findings']);
        self::assertSame(2, DB::table('room_inspections')->where('room_id', $room)->count());
        self::assertSame(['waived' => 2], DB::table('inspection_findings')->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status')->all());
    }

    public function test_an_attendant_can_mark_a_finding_resolved_and_a_supervisor_still_decides(): void
    {
        $room = $this->vacate();
        $this->clean($room);
        $this->hk()->inspect($this->property(), $this->hkSupervisorId, $room, false, [['description' => 'Stain on the carpet']], null);
        $finding = $this->hk()->room($this->property(), $this->hkSupervisorId, $room)['open_findings'][0];

        $this->assertRefused(403, fn () => $this->hk()->resolveFinding($this->property(), $this->hkSupervisorId, $finding['id']));
        $this->hk()->resolveFinding($this->property(), $this->attendantId, $finding['id']);
        $this->assertRefused(409, fn () => $this->hk()->resolveFinding($this->property(), $this->attendantId, $finding['id']));
        self::assertSame('resolved', DB::table('inspection_findings')->value('status'));
        self::assertSame('rework', $this->roomStatus($room));
    }

    public function test_one_unfinished_task_per_room_and_the_database_agrees(): void
    {
        $room = $this->vacate();

        $this->assertRefused(409, fn () => $this->hk()->requestService($this->property(), $this->hkSupervisorId, $room, 'vacant', 'Found dirty'));
        $this->assertRefused(422, fn () => $this->hk()->requestService($this->property(), $this->hkSupervisorId, $room, 'departure', 'x'));
        $this->assertRefused(422, fn () => $this->hk()->requestService($this->property(), $this->hkSupervisorId, $room, 'vacant', ''));
        $this->assertRefused(422, fn () => $this->hk()->requestService($this->property(), $this->hkSupervisorId, $this->roomIds[1], 'vacant', 'x', $this->managerId));
        $this->assertRefused(403, fn () => $this->hk()->requestService($this->property(), $this->attendantId, $this->roomIds[1], 'vacant', 'x'));

        try {
            DB::table('housekeeping_tasks')->insert([
                'id' => '01arz3ndektsv4rrffq69g5fe1', 'property_id' => self::PROPERTY, 'room_id' => $room, 'kind' => 'vacant', 'status' => 'open', 'priority' => 3,
                'source' => 'x', 'created_at' => now(), 'updated_at' => now(),
            ]);
            self::fail('A second unfinished task was accepted.');
        } catch (QueryException $e) {
            self::assertStringContainsString('hk_tasks_one_active_per_room', $e->getMessage());
        }

        // A ready room found dirty gets a task and becomes dirty; a cancelled task frees the room for another request.
        $other = $this->roomIds[1];
        $created = $this->hk()->markDirty($this->property(), $this->hkSupervisorId, $other, 'Guest spilled coffee');
        self::assertSame('dirty', $this->roomStatus($other));
        $this->hk()->cancelTask($this->property(), $this->hkSupervisorId, $created['id'], 'Cleaned by the guest', 0);
        self::assertSame('dirty', $this->roomStatus($other));
        $again = $this->hk()->requestService($this->property(), $this->hkSupervisorId, $other, 'request', 'Extra towels', $this->attendantId);
        self::assertSame('assigned', $again['status']);
    }

    public function test_the_board_shows_both_dimensions_in_work_order(): void
    {
        $departed = $this->vacate(0);
        $reservation = $this->book('2026-10-01', '2026-10-03', 'confirmed');
        app(StayService::class)->checkIn(
            $this->property(),
            $this->managerId,
            new CheckInRequest($reservation->id, $this->roomIds[1], 'Siti', 'ID', 'ktp', '3174010101900055', null, null, 'Jl. Sudirman 2', 1, 0),
            IdempotencyKey::fromString('hk-board-checkin-1'),
        );
        $this->hk()->requestService($this->property(), $this->hkSupervisorId, $this->roomIds[1], 'stayover', 'Daily service', $this->attendantId);

        $board = $this->hk()->board($this->property(), $this->hkSupervisorId);
        $rows = array_column($board['rooms'], null, 'number');

        self::assertSame('dirty', $rows['101']['status']);
        self::assertFalse($rows['101']['occupied']);
        self::assertSame('departure', $rows['101']['task']['kind']);
        self::assertSame('dirty', $rows['102']['status']);
        self::assertTrue($rows['102']['occupied']);
        self::assertSame('2026-10-03', $rows['102']['expected_departure']);
        self::assertSame('stayover', $rows['102']['task']['kind']);
        self::assertNotNull($rows['102']['task']['assigned_name']);
        self::assertSame('ready', $rows['103']['status']);
        self::assertNull($rows['103']['task']);
        // Work comes first by urgency: the departure room before the stay-over room, rooms without work last.
        self::assertSame(['101', '102', '103'], array_column($board['rooms'], 'number'));
        self::assertContains($this->attendantId, array_column($board['staff'], 'id'));
        self::assertNotContains($this->managerId, array_column($board['staff'], 'id'));
        self::assertTrue($board['may']['manage']);
        self::assertFalse($board['may']['waive']);
        self::assertSame($departed, $this->roomIds[0]);
    }

    public function test_permissions_scope_and_history_cannot_be_rewritten(): void
    {
        $room = $this->vacate();
        $this->assertRefused(403, fn () => $this->hk()->board($this->property(), $this->attendantId));
        $this->assertRefused(403, fn () => $this->hk()->myTasks($this->property(), $this->hkSupervisorId));
        $this->assertRefused(403, fn () => $this->hk()->assign($this->property(), $this->attendantId, $this->taskOf($room)['id'], $this->attendantId, 0));
        $this->assertRefused(422, fn () => $this->hk()->assign($this->property(), $this->hkSupervisorId, $this->taskOf($room)['id'], $this->managerId, 0));
        $this->assertRefused(404, fn () => $this->hk()->start($this->property(), $this->attendantId, '01arz3ndektsv4rrffq69g5fe9', 0));

        $this->clean($room);
        $this->hk()->inspect($this->property(), $this->hkSupervisorId, $room, false, [['description' => 'Dust']], null);

        foreach ([
            fn () => DB::table('housekeeping_status_log')->update(['reason' => 'x']),
            fn () => DB::table('housekeeping_status_log')->delete(),
            fn () => DB::table('room_inspections')->update(['notes' => 'x']),
            fn () => DB::table('room_inspections')->delete(),
            fn () => DB::table('inspection_findings')->update(['description' => 'changed']),
            fn () => DB::table('inspection_findings')->delete(),
            fn () => DB::table('housekeeping_tasks')->update(['room_id' => $this->roomIds[2]]),
            fn () => DB::table('housekeeping_tasks')->delete(),
            fn () => DB::table('housekeeping_rooms')->update(['status' => 'bogus']),
        ] as $mutation) {
            try {
                $mutation();
                self::fail('A guarded row changed.');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        }
    }
}
