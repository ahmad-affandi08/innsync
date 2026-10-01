<?php

declare(strict_types=1);

namespace Tests\Integration\Housekeeping;

use App\Modules\Housekeeping\Application\ChecklistService;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Time\BusinessDate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\BuildsHotel;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-HK-005: daily, weekly and monthly housekeeping checklists per room and per public area, written by management as versioned templates. */
final class ChecklistTest extends TestCase
{
    use BuildsHotel;
    use RefreshDatabase;
    use SignsInToProperty;

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

    private function lists(): ChecklistService
    {
        return app(ChecklistService::class);
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

    private function roomList(array $items = ['Change the linen', 'Clean the bathroom', 'Check the minibar']): array
    {
        return $this->lists()->define($this->property(), $this->hkListManagerId, 'Daily room routine', 'daily', 'room', [], $items, true);
    }

    private function areaList(): array
    {
        return $this->lists()->define($this->property(), $this->hkListManagerId, 'Public area weekly', 'weekly', 'area', ['Lobby', 'Corridor floor 1'], ['Polish the floor', 'Wipe the glass'], true);
    }

    private function board(string $name): array
    {
        return array_column($this->lists()->board($this->property(), $this->hkListStaffId)['checklists'], null, 'name')[$name];
    }

    public function test_management_writes_versioned_templates_and_validation_is_enforced(): void
    {
        $v1 = $this->roomList();
        self::assertSame([1, 'room', 3], [$v1['version'], $v1['scope'], count($v1['items'])]);
        $v2 = $this->lists()->define($this->property(), $this->hkListManagerId, 'Daily room routine', 'daily', 'room', [], ['Change the linen', 'Clean the bathroom'], true);
        self::assertSame([2, 2], [$v2['version'], count($v2['items'])]);
        self::assertSame(2, DB::table('hk_checklist_templates')->count());
        self::assertSame(1, count($this->lists()->templates($this->property(), $this->hkListManagerId)['templates']));

        $this->refused(fn () => $this->lists()->define($this->property(), $this->hkListManagerId, 'Daily room routine', 'weekly', 'room', [], ['x'], true), 422);
        $this->refused(fn () => $this->lists()->define($this->property(), $this->hkListManagerId, 'Daily room routine', 'daily', 'area', ['Lobby'], ['x'], true), 422);
        $this->refused(fn () => $this->lists()->define($this->property(), $this->hkListManagerId, 'Hourly', 'hourly', 'room', [], ['x'], true), 422);
        $this->refused(fn () => $this->lists()->define($this->property(), $this->hkListManagerId, 'No items', 'daily', 'room', [], [' ', ''], true), 422);
        $this->refused(fn () => $this->lists()->define($this->property(), $this->hkListManagerId, 'Rooms with areas', 'daily', 'room', ['Lobby'], ['x'], true), 422);
        $this->refused(fn () => $this->lists()->define($this->property(), $this->hkListManagerId, 'Areas with none', 'daily', 'area', [], ['x'], true), 422);
        $this->refused(fn () => $this->lists()->define($this->property(), $this->hkListStaffId, 'Not mine', 'daily', 'room', [], ['x'], true), 403);
        $this->refused(fn () => $this->lists()->templates($this->property(), $this->hkListStaffId), 403);

        try {
            DB::table('hk_checklist_templates')->update(['name' => 'x']);
            self::fail('Templates cannot be changed');
        } catch (QueryException $e) {
            self::assertStringContainsString('cannot be changed', $e->getMessage());
        }
    }

    public function test_a_room_checklist_has_a_run_per_room_and_an_area_checklist_one_per_area(): void
    {
        $room = $this->roomList();
        $area = $this->areaList();
        $rooms = $this->board('Daily room routine')['targets'];
        self::assertSame(['101', '102', '103'], array_column($rooms, 'label'));
        self::assertSame([0, 0, 0], array_column($rooms, 'percent'));
        self::assertSame(['Lobby', 'Corridor floor 1'], array_column($this->board('Public area weekly')['targets'], 'label'));
        self::assertSame(0, DB::table('hk_checklist_runs')->count(), 'looking writes nothing');

        $first = $this->lists()->complete($this->property(), $this->hkListStaffId, $room['id'], $this->roomIds[0], 'i1', 'Two sheets torn');
        self::assertSame([1, 3, 33], [$first['completed'], $first['total'], $first['percent']]);
        self::assertSame('Two sheets torn', $first['items'][0]['note']);
        $this->lists()->complete($this->property(), $this->hkListStaff2Id, $room['id'], $this->roomIds[0], 'i2', null);
        $this->lists()->complete($this->property(), $this->hkListStaffId, $room['id'], $this->roomIds[1], 'i1', null);
        $this->lists()->complete($this->property(), $this->hkListStaffId, $area['id'], 'Lobby', 'i1', null);

        self::assertSame(3, DB::table('hk_checklist_runs')->count());
        $by = array_column($this->board('Daily room routine')['targets'], 'percent', 'label');
        self::assertSame(['101' => 66, '102' => 33, '103' => 0], $by);
        self::assertSame(['Lobby' => 50, 'Corridor floor 1' => 0], array_column($this->board('Public area weekly')['targets'], 'percent', 'label'));

        $detail = $this->lists()->detail($this->property(), $this->hkListViewerId, $room['id'], $this->roomIds[0]);
        self::assertSame([true, true, false], array_column($detail['items'], 'done'));
        self::assertNotNull($detail['items'][0]['by']);
    }

    public function test_a_ticked_item_is_a_fact_announced_with_its_share_and_a_finished_run_is_announced_too(): void
    {
        $t = $this->roomList(['Change the linen', 'Clean the bathroom']);
        $this->lists()->complete($this->property(), $this->hkListStaffId, $t['id'], $this->roomIds[0], 'i1', null);
        $this->refused(fn () => $this->lists()->complete($this->property(), $this->hkListStaff2Id, $t['id'], $this->roomIds[0], 'i1', null), 409);
        $this->lists()->complete($this->property(), $this->hkListStaff2Id, $t['id'], $this->roomIds[0], 'i2', null);

        $run = DB::table('hk_checklist_runs')->first();
        self::assertSame(2, DB::table('outbox_messages')->where('event_type', 'housekeeping.checklist.item_completed')->where('aggregate_id', $run->id)->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'housekeeping.checklist.run_completed')->where('aggregate_id', $run->id)->count());
        self::assertSame(2, DB::table('audit_entries')->where('action', 'hk.checklist.item_completed')->count());

        foreach ([fn () => DB::table('hk_checklist_completions')->update(['note' => 'x']), fn () => DB::table('hk_checklist_completions')->delete(), fn () => DB::table('hk_checklist_runs')->delete()] as $attempt) {
            try {
                $attempt();
                self::fail('Expected the database to refuse');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_refusals_on_ticking(): void
    {
        $t = $this->roomList();
        $this->refused(fn () => $this->lists()->complete($this->property(), $this->hkListStaffId, $t['id'], $this->roomIds[0], 'i9', null), 422);
        $this->refused(fn () => $this->lists()->complete($this->property(), $this->hkListStaffId, $t['id'], '01arz3ndektsv4rrffq69g5faa', 'i1', null), 404);
        $this->refused(fn () => $this->lists()->complete($this->property(), $this->hkListStaffId, $t['id'], $this->roomIds[0], 'i1', str_repeat('x', 301)), 422);
        $this->refused(fn () => $this->lists()->complete($this->property(), $this->hkListViewerId, $t['id'], $this->roomIds[0], 'i1', null), 403);
        $this->refused(fn () => $this->lists()->complete($this->property(), $this->hkListStaffId, '01arz3ndektsv4rrffq69g5faa', $this->roomIds[0], 'i1', null), 404);
        $this->refused(fn () => $this->lists()->detail($this->property(), $this->clerkId, $t['id'], $this->roomIds[0]), 403);
        $this->refused(fn () => $this->lists()->board($this->property(), $this->clerkId), 403);
    }

    public function test_a_new_version_leaves_a_started_run_alone_and_a_retired_checklist_cannot_be_ticked(): void
    {
        $old = $this->roomList(['One', 'Two']);
        $this->lists()->complete($this->property(), $this->hkListStaffId, $old['id'], $this->roomIds[0], 'i1', null);
        $new = $this->lists()->define($this->property(), $this->hkListManagerId, 'Daily room routine', 'daily', 'room', [], ['One', 'Two', 'Three', 'Four'], true);

        $this->refused(fn () => $this->lists()->complete($this->property(), $this->hkListStaffId, $old['id'], $this->roomIds[0], 'i2', null), 409);
        self::assertSame(2, $this->lists()->detail($this->property(), $this->hkListStaffId, $new['id'], $this->roomIds[0])['total'], 'the started run keeps its items');
        self::assertSame(4, $this->lists()->detail($this->property(), $this->hkListStaffId, $new['id'], $this->roomIds[1])['total'], 'another room begins with the new version');

        $this->lists()->define($this->property(), $this->hkListManagerId, 'Daily room routine', 'daily', 'room', [], ['One'], false);
        self::assertSame([], $this->lists()->board($this->property(), $this->hkListStaffId)['checklists']);
        $this->refused(fn () => $this->lists()->complete($this->property(), $this->hkListStaffId, $new['id'], $this->roomIds[1], 'i1', null), 409);
    }

    public function test_periods_are_a_business_date_an_iso_week_and_a_month(): void
    {
        self::assertSame('2026-10-01', ChecklistService::period('daily', BusinessDate::fromString('2026-10-01'))['key']);
        self::assertSame(['2026-W40', '2026-09-28', '2026-10-04'], array_values(ChecklistService::period('weekly', BusinessDate::fromString('2026-10-01'))));
        self::assertSame(['2026-10', '2026-10-01', '2026-10-31'], array_values(ChecklistService::period('monthly', BusinessDate::fromString('2026-10-15'))));
    }

    public function test_the_performance_figure_counts_per_room_per_run_and_per_person(): void
    {
        $t = $this->roomList(['One', 'Two']);
        $this->lists()->complete($this->property(), $this->hkListStaffId, $t['id'], $this->roomIds[0], 'i1', null);
        $this->lists()->complete($this->property(), $this->hkListStaffId, $t['id'], $this->roomIds[0], 'i2', null);
        $this->lists()->complete($this->property(), $this->hkListStaff2Id, $t['id'], $this->roomIds[1], 'i1', null);

        DB::table('property_settings')->where('property_id', self::PROPERTY)->update(['business_date' => '2026-10-02']);
        $this->lists()->complete($this->property(), $this->hkListStaff2Id, $t['id'], $this->roomIds[0], 'i1', null);

        $report = $this->lists()->performance($this->property(), $this->hkListViewerId, '2026-10-01', '2026-10-02');
        self::assertSame([['101', 100], ['102', 50], ['101', 50]], array_map(static fn (array $r): array => [$r['target'], $r['percent']], $report['runs']));
        self::assertSame(66, $report['percent']);
        self::assertSame([[$this->hkListStaffId, 2], [$this->hkListStaff2Id, 2]], array_map(static fn (array $p): array => [$p['user_id'], $p['items']], $report['people']));
        $this->refused(fn () => $this->lists()->performance($this->property(), $this->hkListStaffId, null, null), 403);
        $this->refused(fn () => $this->lists()->performance($this->property(), $this->hkListViewerId, '2026-10-02', '2026-10-01'), 422);
        $this->refused(fn () => $this->lists()->performance($this->property(), $this->hkListViewerId, '2026-01-01', '2026-10-01'), 422);
    }
}
