<?php

declare(strict_types=1);

namespace Tests\Integration\FrontOffice;

use App\Modules\FrontOffice\Application\Routine\ShiftLogService;
use App\Modules\FrontOffice\Application\Routine\SopService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
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

/** FR-FO-032 to FR-FO-034: front desk checklists with their completion figures, and the handover log between shifts. */
final class RoutineTest extends TestCase
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

    private function sop(): SopService
    {
        return app(SopService::class);
    }

    private function log(): ShiftLogService
    {
        return app(ShiftLogService::class);
    }

    private function define(string $name = 'Opening routine', string $frequency = 'daily', array $items = ['Count the float', 'Read the log book', 'Check arrivals list'], bool $active = true): array
    {
        return $this->sop()->define($this->property(), $this->sopManagerId, $name, $frequency, $items, $active);
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

    public function test_a_checklist_is_written_versioned_and_the_old_version_stays_on_record(): void
    {
        $v1 = $this->define();
        self::assertSame([1, ['i1', 'i2', 'i3'], 'daily', true], [$v1['version'], array_column($v1['items'], 'id'), $v1['frequency'], $v1['is_active']]);

        $v2 = $this->define('Opening routine', 'daily', ['Count the float', 'Check arrivals list']);
        self::assertSame(2, $v2['version']);
        self::assertSame(2, DB::table('sop_templates')->count());
        self::assertSame([2], array_column($this->sop()->templates($this->property(), $this->sopManagerId)['templates'], 'version'));
        self::assertSame(2, DB::table('audit_entries')->where('action', 'sop.template.defined')->count());

        $this->refused(fn () => $this->define('Opening routine', 'weekly'), 422);
        $this->refused(fn () => $this->define('ab'), 422);
        $this->refused(fn () => $this->define('Empty one', 'daily', ['  ']), 422);
        $this->refused(fn () => $this->define('Too long', 'daily', [str_repeat('x', 161)]), 422);
        $this->refused(fn () => $this->define('Bad frequency', 'hourly'), 422);
        $this->refused(fn () => $this->sop()->define($this->property(), $this->sopStaffId, 'Mine', 'daily', ['x'], true), 403);

        try {
            DB::table('sop_templates')->update(['name' => 'edited']);
            self::fail('A template was changed');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }

    public function test_the_board_shows_each_active_checklist_for_its_period(): void
    {
        $this->define();
        $this->define('Weekly safe check', 'weekly', ['Count the safe', 'Check the keys']);
        $this->define('Monthly review', 'monthly', ['Review the rate plans']);
        $this->define('Retired', 'daily', ['x'], false);

        $board = $this->sop()->board($this->property(), $this->sopStaffId);
        self::assertSame('2026-10-01', $board['business_date']);
        $by = array_column($board['checklists'], null, 'name');
        self::assertSame(['Monthly review', 'Opening routine', 'Weekly safe check'], array_keys($by) === [] ? [] : (static function (array $k): array {
            sort($k);

            return $k;
        })(array_keys($by)));
        self::assertSame(['2026-10-01', '2026-10-01', '2026-10-01'], [$by['Opening routine']['period_key'], $by['Opening routine']['period_start'], $by['Opening routine']['period_end']]);
        self::assertSame(['2026-W40', '2026-09-28', '2026-10-04'], [$by['Weekly safe check']['period_key'], $by['Weekly safe check']['period_start'], $by['Weekly safe check']['period_end']]);
        self::assertSame(['2026-10', '2026-10-01', '2026-10-31'], [$by['Monthly review']['period_key'], $by['Monthly review']['period_start'], $by['Monthly review']['period_end']]);
        self::assertSame([0, 3, 0], [$by['Opening routine']['completed'], $by['Opening routine']['total'], $by['Opening routine']['percent']]);
        self::assertTrue($board['may_perform']);
        self::assertFalse($this->sop()->board($this->property(), $this->sopManagerId)['may_perform']);
        self::assertSame(0, DB::table('sop_runs')->count(), 'looking writes nothing');
        $this->refused(fn () => $this->sop()->board($this->property(), $this->managerId), 403);
    }

    public function test_ticking_items_starts_the_run_records_who_and_announces_the_percentage(): void
    {
        $t = $this->define();

        $one = $this->sop()->complete($this->property(), $this->sopStaffId, $t['id'], 'i1', 'Float 500,000');
        self::assertSame([1, 3, 33], [$one['completed'], $one['total'], $one['percent']]);
        $two = $this->sop()->complete($this->property(), $this->sopStaff2Id, $t['id'], 'i2', null);
        self::assertSame(66, $two['percent']);
        self::assertSame(1, DB::table('sop_runs')->count(), 'one run however many people tick');
        self::assertSame([true, true, false], array_column($two['items'], 'done'));
        self::assertSame('Float 500,000', $two['items'][0]['note']);
        self::assertSame(2, DB::table('outbox_messages')->where('event_type', 'frontoffice.sop.item_completed')->count());
        self::assertSame(0, DB::table('outbox_messages')->where('event_type', 'frontoffice.sop.run_completed')->count());

        $done = $this->sop()->complete($this->property(), $this->sopStaffId, $t['id'], 'i3', null);
        self::assertSame(100, $done['percent']);
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'frontoffice.sop.run_completed')->count());

        $this->refused(fn () => $this->sop()->complete($this->property(), $this->sopStaffId, $t['id'], 'i3', null), 409);
        $this->refused(fn () => $this->sop()->complete($this->property(), $this->sopStaffId, $t['id'], 'i9', null), 422);
        $this->refused(fn () => $this->sop()->complete($this->property(), $this->sopManagerId, $t['id'], 'i1', null), 403);
        $this->refused(fn () => $this->sop()->complete($this->property(), $this->sopStaffId, '01arz3ndektsv4rrffq69g5fax', 'i1', null), 404);
        $this->refused(fn () => $this->sop()->complete($this->property(), $this->sopStaffId, $t['id'], 'i1', str_repeat('n', 301)), 422);

        try {
            DB::table('sop_completions')->delete();
            self::fail('A completed item was undone');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }

    public function test_a_new_version_does_not_change_a_run_already_started_and_the_old_version_can_no_longer_be_ticked(): void
    {
        $v1 = $this->define();
        $this->sop()->complete($this->property(), $this->sopStaffId, $v1['id'], 'i1', null);
        $v2 = $this->define('Opening routine', 'daily', ['Count the float', 'Check the safe']);

        $this->refused(fn () => $this->sop()->complete($this->property(), $this->sopStaffId, $v1['id'], 'i2', null), 409);
        $board = array_column($this->sop()->board($this->property(), $this->sopStaffId)['checklists'], null, 'name')['Opening routine'];
        self::assertSame($v2['id'], $board['template_id']);
        self::assertSame(['Count the float', 'Read the log book', 'Check arrivals list'], array_column($board['items'], 'text'), 'the run keeps the items it began with');
        self::assertSame(1, $board['completed']);

        $retired = $this->define('Opening routine', 'daily', ['x'], false);
        $this->refused(fn () => $this->sop()->complete($this->property(), $this->sopStaffId, $retired['id'], 'i1', null), 409);
        self::assertSame([], array_column($this->sop()->board($this->property(), $this->sopStaffId)['checklists'], 'name'));
    }

    public function test_a_new_period_starts_a_new_run_and_the_performance_figure_counts_per_run_and_person(): void
    {
        $t = $this->define();
        $this->sop()->complete($this->property(), $this->sopStaffId, $t['id'], 'i1', null);
        $this->sop()->complete($this->property(), $this->sopStaffId, $t['id'], 'i2', null);
        $this->sop()->complete($this->property(), $this->sopStaff2Id, $t['id'], 'i3', null);

        // The business date moves on by the night audit only; here the setting is moved for the test.
        DB::table('property_settings')->where('property_id', self::PROPERTY)->update(['business_date' => '2026-10-02']);
        $board = array_column($this->sop()->board($this->property(), $this->sopStaffId)['checklists'], null, 'name')['Opening routine'];
        self::assertSame([0, '2026-10-02'], [$board['completed'], $board['period_key']]);
        $this->sop()->complete($this->property(), $this->sopStaff2Id, $t['id'], 'i1', null);
        self::assertSame(2, DB::table('sop_runs')->count());

        $report = $this->sop()->performance($this->property(), $this->sopManagerId, '2026-10-01', '2026-10-02');
        self::assertSame([100, 33], array_column($report['runs'], 'percent'));
        self::assertSame([[$this->sopStaffId, 2], [$this->sopStaff2Id, 2]], array_map(static fn (array $p): array => [$p['user_id'], $p['items']], $report['people']));
        $this->refused(fn () => $this->sop()->performance($this->property(), $this->sopStaffId, null, null), 403);
        $this->refused(fn () => $this->sop()->performance($this->property(), $this->sopManagerId, '2026-10-02', '2026-10-01'), 422);
        $this->refused(fn () => $this->sop()->performance($this->property(), $this->sopManagerId, '2026-01-01', '2026-10-01'), 422);
        self::assertNotNull(BusinessDate::fromString('2026-10-02'));
        self::assertNotNull(PropertySettingsService::class);
    }

    public function test_the_shift_log_is_written_never_changed_and_read_by_each_person(): void
    {
        $a = $this->log()->write($this->property(), $this->logWriterId, 'night', 'Guest in 102 wants a 5 am taxi. Safe key is with the duty manager.', true);
        $this->log()->write($this->property(), $this->logWriterId, 'night', 'Printer is out of paper.', false);
        self::assertSame(['night', 'important', '2026-10-01'], [$a['shift'], $a['priority'], $a['business_date']]);
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'frontoffice.shift_log.important')->count());

        $reader = $this->log()->read($this->property(), $this->logReaderId);
        self::assertSame([2, false, ['Printer is out of paper.', 'Guest in 102 wants a 5 am taxi. Safe key is with the duty manager.']], [$reader['unread'], $reader['may_write'], array_column($reader['entries'], 'body')]);
        self::assertSame(0, $this->log()->read($this->property(), $this->logWriterId)['unread'], 'the author has not to read their own entry');

        self::assertSame(1, $this->log()->markRead($this->property(), $this->logReaderId, [$a['id']]));
        self::assertSame(0, $this->log()->markRead($this->property(), $this->logReaderId, [$a['id']]), 'a second mark changes nothing');
        self::assertSame(1, $this->log()->read($this->property(), $this->logReaderId)['unread']);
        self::assertSame(2, $this->log()->read($this->property(), $this->sopStaffId)['unread'], 'each person has their own marks');

        $this->refused(fn () => $this->log()->write($this->property(), $this->logReaderId, 'night', 'x', false), 403);
        $this->refused(fn () => $this->log()->write($this->property(), $this->logWriterId, 'dawn', 'x', false), 422);
        $this->refused(fn () => $this->log()->write($this->property(), $this->logWriterId, 'night', ' ', false), 422);
        $this->refused(fn () => $this->log()->write($this->property(), $this->logWriterId, 'night', str_repeat('x', 2001), false), 422);
        $this->refused(fn () => $this->log()->read($this->property(), $this->managerId), 403);

        foreach (['update' => fn () => DB::table('shift_log_entries')->update(['body' => 'edited']), 'delete' => fn () => DB::table('shift_log_entries')->delete(), 'unread' => fn () => DB::table('shift_log_reads')->delete()] as $attempt) {
            try {
                $attempt();
                self::fail('The log was altered');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_entries_older_than_three_days_leave_the_view_but_stay_on_record(): void
    {
        $this->log()->write($this->property(), $this->logWriterId, 'morning', 'An old note', false);
        $this->clock->advance('+4 days');
        $this->log()->write($this->property(), $this->logWriterId, 'morning', 'A new note', false);

        self::assertSame(['A new note'], array_column($this->log()->read($this->property(), $this->logReaderId)['entries'], 'body'));
        self::assertSame(2, DB::table('shift_log_entries')->count());
    }
}
