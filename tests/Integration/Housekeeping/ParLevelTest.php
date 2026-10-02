<?php

declare(strict_types=1);

namespace Tests\Integration\Housekeeping;

use App\Modules\FrontOffice\Application\Stays\CheckInRequest;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\Housekeeping\Application\HousekeepingService;
use App\Modules\Housekeeping\Application\LinenService;
use App\Modules\Housekeeping\Application\ParLevelService;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\BuildsHotel;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-HK-019: par levels of linen and amenities, what to bring to the floor and consumption against the standard by shift. */
final class ParLevelTest extends TestCase
{
    use BuildsHotel;
    use RefreshDatabase;
    use SignsInToProperty;

    private string $sheet;

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
        $this->sheet = $this->linen()->createItem($this->property(), $this->linenManagerId, 'sheet_q', 'Queen sheet', 'linen', 'pcs')['id'];
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    private function pars(): ParLevelService
    {
        return app(ParLevelService::class);
    }

    private function linen(): LinenService
    {
        return app(LinenService::class);
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

    private function move(string $from, string $to, int $quantity): void
    {
        $t = $this->linen()->send($this->property(), $this->linenManagerId, $this->sheet, $from, $to, $quantity, null);
        $this->linen()->receive($this->property(), $to === 'laundry' ? $this->linenLaundryId : $this->linenManager2Id, $t['id'], $quantity, null, null, $t['lock_version']);
    }

    /** Checks a guest into room `$room` and out again, then has an attendant clean it: one room service finished now. */
    private function serviceRoom(int $room): string
    {
        $reservation = $this->book('2026-10-01', '2026-10-02', 'confirmed');
        $stay = app(StayService::class)->checkIn($this->property(), $this->managerId, new CheckInRequest($reservation->id, $this->roomIds[$room], 'Budi Santoso', 'ID', 'ktp', sprintf('31740101019%05d', ++$this->n), null, null, 'Jl. Merdeka 1', 2, 0), IdempotencyKey::fromString(sprintf('par-checkin-%07d', $this->n)));
        app(StayService::class)->checkOut($this->property(), $this->managerId, $stay['id'], $stay['lock_version']);
        $hk = app(HousekeepingService::class);
        $row = DB::table('housekeeping_tasks')->where('room_id', $this->roomIds[$room])->whereIn('status', ['open', 'assigned', 'in_progress'])->first();
        $task = $hk->assign($this->property(), $this->hkSupervisorId, $row->id, $this->attendantId, (int) $row->lock_version);
        $started = $hk->start($this->property(), $this->attendantId, $task['id'], $task['lock_version']);
        $hk->finish($this->property(), $this->attendantId, $started['id'], $started['lock_version']);

        return $this->roomIds[$room];
    }

    public function test_par_levels_are_set_with_a_reason_by_someone_who_may_and_seen_by_housekeeping(): void
    {
        $this->refused(fn () => $this->pars()->save($this->property(), $this->managerId, $this->sheet, 'room_type', $this->typeId, 4, 2, null, 'Standard'), 403);
        $this->refused(fn () => $this->pars()->overview($this->property(), $this->clerkId), 403);
        self::assertTrue($this->pars()->overview($this->property(), $this->hkSupervisorId)['may']['manage'] === false);
        self::assertTrue($this->pars()->overview($this->property(), $this->linenManagerId)['may']['manage'] === false);

        $level = $this->pars()->save($this->property(), $this->parManagerId, $this->sheet, 'room_type', $this->typeId, 4, 2, null, 'Standard of the house');
        self::assertSame([4, 2, 0, 'SHEET_Q'], [$level['par_quantity'], $level['use_quantity'], $level['lock_version'], $level['item_code']]);
        $changed = $this->pars()->save($this->property(), $this->parManagerId, $this->sheet, 'room_type', $this->typeId, 5, 2, 0, 'More in circulation');
        self::assertSame([5, 1], [$changed['par_quantity'], $changed['lock_version']]);

        $this->refused(fn () => $this->pars()->save($this->property(), $this->parManagerId, $this->sheet, 'room_type', $this->typeId, 6, 2, 0, 'Stale'), 409);
        $this->refused(fn () => $this->pars()->save($this->property(), $this->parManagerId, $this->sheet, 'room_type', $this->typeId, 6, 2, null, 'Exists'), 409);
        $this->refused(fn () => $this->pars()->save($this->property(), $this->parManagerId, $this->sheet, 'area', 'Lobby', 6, 0, 3, 'Missing'), 409);

        $audit = DB::table('audit_entries')->where('action', 'par_level.saved')->orderBy('id')->get();
        self::assertCount(2, $audit);
    }

    public function test_the_par_level_is_validated(): void
    {
        $save = fn (array $o): array => $this->pars()->save($this->property(), $this->parManagerId, $o['item'] ?? $this->sheet, $o['kind'] ?? 'room_type', $o['ref'] ?? $this->typeId, $o['par'] ?? 4, $o['use'] ?? 2, null, $o['reason'] ?? 'Standard');

        $this->refused(fn () => $save(['item' => str_repeat('0', 26)]), 422);
        $this->refused(fn () => $save(['kind' => 'floor']), 422);
        $this->refused(fn () => $save(['ref' => str_repeat('0', 26)]), 422);
        $this->refused(fn () => $save(['kind' => 'area', 'ref' => ' ', 'use' => 0]), 422);
        $this->refused(fn () => $save(['kind' => 'area', 'ref' => str_repeat('x', 41), 'use' => 0]), 422);
        $this->refused(fn () => $save(['kind' => 'area', 'ref' => 'Lobby', 'use' => 1]), 422);
        $this->refused(fn () => $save(['par' => -1]), 422);
        $this->refused(fn () => $save(['par' => ParLevelService::MAX_PAR + 1]), 422);
        $this->refused(fn () => $save(['reason' => ' ']), 422);

        $this->linen()->setItemActive($this->property(), $this->linenManagerId, $this->sheet, false, 0);
        $this->refused(fn () => $save([]), 422);
        self::assertSame(0, DB::table('linen_par_levels')->count());
    }

    public function test_what_to_bring_to_the_floor_is_the_par_of_every_room_and_area_less_what_the_floor_holds(): void
    {
        $this->move('external', 'store', 100);
        $this->move('store', 'floor', 5);
        // Three Deluxe rooms at 4 each, and the lobby at 6: 18 on the floor, of which 5 are there.
        $this->pars()->save($this->property(), $this->parManagerId, $this->sheet, 'room_type', $this->typeId, 4, 2, null, 'Standard');
        $this->pars()->save($this->property(), $this->parManagerId, $this->sheet, 'area', 'Lobby', 6, 0, null, 'Standard');

        $view = $this->pars()->overview($this->property(), $this->hkSupervisorId);
        $row = $view['replenishment'][0];
        self::assertSame([18, 5, 13, 95, 13, 0], [$row['target'], $row['on_floor'], $row['need'], $row['in_store'], $row['from_store'], $row['to_obtain']]);
        self::assertSame(['Lobby'], $view['areas']);
        self::assertSame(3, array_column($view['room_types'], 'rooms', 'id')[$this->typeId]);

        // The store holds less than the need: the rest is to be obtained.
        $this->move('store', 'laundry', 90);
        $short = $this->pars()->overview($this->property(), $this->hkSupervisorId)['replenishment'][0];
        self::assertSame([13, 5, 5, 8], [$short['need'], $short['in_store'], $short['from_store'], $short['to_obtain']]);

        // An item with no par is not listed.
        $this->linen()->createItem($this->property(), $this->linenManagerId, 'soap', 'Soap', 'amenity', 'pcs');
        self::assertCount(1, $this->pars()->overview($this->property(), $this->hkSupervisorId)['replenishment']);
    }

    public function test_consumption_in_a_shift_is_set_against_the_standard_times_the_rooms_serviced(): void
    {
        $this->pars()->save($this->property(), $this->parManagerId, $this->sheet, 'room_type', $this->typeId, 4, 2, null, 'Standard');
        $one = $this->serviceRoom(0);
        $two = $this->serviceRoom(1);
        $this->linen()->recordUsage($this->property(), $this->attendantId, $one, $this->sheet, 2, null);
        $this->linen()->recordUsage($this->property(), $this->attendantId, $two, $this->sheet, 3, 'Stained, changed twice');

        // The clock is 10:00 in Jakarta on 1 October: the morning shift.
        $morning = $this->pars()->consumption($this->property(), $this->hkSupervisorId, null, 'morning');
        self::assertSame(['2026-10-01', 'morning', '2026-10-01T00:00:00Z', '2026-10-01T08:00:00Z'], [$morning['date'], $morning['shift'], $morning['from'], $morning['to']]);
        $row = $morning['rows'][0];
        self::assertSame(['SHEET_Q', 'DLX', 2, 2, 4, 5, 1], [$row['code'], $row['room_type'], $row['rooms_serviced'], $row['standard'], $row['expected'], $row['actual'], $row['variance']]);

        foreach (['afternoon', 'night'] as $shift) {
            $other = $this->pars()->consumption($this->property(), $this->hkSupervisorId, '2026-10-01', $shift);
            self::assertSame([0, 0, 0], [$other['rows'][0]['rooms_serviced'], $other['rows'][0]['actual'], $other['rows'][0]['variance']]);
        }

        self::assertSame('2026-10-01T16:00:00Z', $this->pars()->consumption($this->property(), $this->hkSupervisorId, '2026-10-01', 'night')['from']);
        self::assertSame('2026-10-02T00:00:00Z', $this->pars()->consumption($this->property(), $this->hkSupervisorId, '2026-10-01', 'night')['to']);
        // Yesterday's morning has nothing, and a standard of zero with nothing used is not listed.
        self::assertSame(0, $this->pars()->consumption($this->property(), $this->hkSupervisorId, '2026-09-30', 'morning')['rows'][0]['actual']);
    }

    public function test_an_item_used_without_a_standard_still_shows_what_was_used(): void
    {
        $this->pars()->save($this->property(), $this->parManagerId, $this->sheet, 'room_type', $this->typeId, 4, 0, null, 'Par only');
        $room = $this->serviceRoom(0);
        self::assertSame([], $this->pars()->consumption($this->property(), $this->hkSupervisorId, null, 'morning')['rows']);

        $this->linen()->recordUsage($this->property(), $this->attendantId, $room, $this->sheet, 3, null);
        $row = $this->pars()->consumption($this->property(), $this->hkSupervisorId, null, 'morning')['rows'][0];
        self::assertSame([0, 0, 3, 3], [$row['standard'], $row['expected'], $row['actual'], $row['variance']]);
    }

    public function test_the_shift_and_the_date_are_validated(): void
    {
        $this->refused(fn () => $this->pars()->consumption($this->property(), $this->hkSupervisorId, null, 'evening'), 422);
        $this->refused(fn () => $this->pars()->consumption($this->property(), $this->hkSupervisorId, '2026-13-45', 'morning'), 422);
        $this->refused(fn () => $this->pars()->consumption($this->property(), $this->clerkId, null, 'morning'), 403);
    }
}
