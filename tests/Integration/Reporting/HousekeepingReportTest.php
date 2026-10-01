<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting;

use App\Modules\Housekeeping\Application\ChecklistService;
use App\Modules\Housekeeping\Application\HousekeepingService;
use App\Modules\Reporting\Application\ReportService;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\BuildsHotel;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-HK-015: rooms cleaned per person, time per room, inspection results and checklist completion. */
final class HousekeepingReportTest extends TestCase
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

    private function hk(): HousekeepingService
    {
        return app(HousekeepingService::class);
    }

    private function reports(): ReportService
    {
        return app(ReportService::class);
    }

    /** Services a room as `$attendant` and spends `$minutes` on it. */
    private function clean(string $roomId, string $attendant, string $kind, int $minutes): void
    {
        $task = $this->hk()->requestService($this->property(), $this->hkSupervisorId, $roomId, $kind, 'Report test', $attendant);
        $started = $this->hk()->start($this->property(), $attendant, $task['id'], $task['lock_version']);
        $this->clock->advance("+{$minutes} minutes");
        $this->hk()->finish($this->property(), $attendant, $task['id'], $started['lock_version']);
    }

    public function test_rooms_per_person_average_time_inspections_and_checklists(): void
    {
        $this->clean($this->roomIds[0], $this->attendantId, 'vacant', 20);
        $this->clean($this->roomIds[1], $this->attendantId, 'request', 40);
        $this->clean($this->roomIds[2], $this->attendant2Id, 'vacant', 30);
        $this->hk()->inspect($this->property(), $this->hkSupervisorId, $this->roomIds[0], true, [], null);
        $this->hk()->inspect($this->property(), $this->hkSupervisorId, $this->roomIds[1], true, [], null);
        $this->hk()->inspect($this->property(), $this->hkSupervisorId, $this->roomIds[2], false, [['description' => 'Dust on the shelf', 'mandatory' => true]], null);

        $list = app(ChecklistService::class);
        $t = $list->define($this->property(), $this->hkListManagerId, 'Daily room routine', 'daily', 'room', [], ['One', 'Two'], true);
        $list->complete($this->property(), $this->hkListStaffId, $t['id'], $this->roomIds[0], 'i1', null);

        $report = $this->reports()->housekeeping($this->property(), $this->analystId, 'today', null, null);
        self::assertSame([[2, 1800], [1, 1800]], array_map(static fn (array $s): array => [$s['rooms'], $s['average_seconds']], $report['staff']));
        self::assertSame([$this->attendantId, $this->attendant2Id], array_column($report['staff'], 'user_id'));
        self::assertNotNull($report['staff'][0]['name']);
        self::assertSame(['request' => [1, 2400], 'vacant' => [2, 1500]], array_column(array_map(static fn (array $k): array => [$k['kind'], [$k['rooms'], $k['average_seconds']]], $report['kinds']), 1, 0));
        self::assertSame([3, 1800], [$report['totals']['rooms'], $report['totals']['average_seconds']]);
        self::assertSame([3, 2, 6666], [$report['inspections']['inspected'], $report['inspections']['passed'], $report['inspections']['first_time_pass_bp']]);
        self::assertSame([1, 2, 1, 50], [$report['checklists']['runs'], $report['checklists']['items'], $report['checklists']['completed'], $report['checklists']['percent']]);
        self::assertSame('housekeeping', $report['meta']['report']);
    }

    public function test_an_empty_period_says_so_without_inventing_percentages(): void
    {
        $report = $this->reports()->housekeeping($this->property(), $this->analystId, 'today', null, null);

        self::assertSame([[], 0, null, null], [$report['staff'], $report['totals']['rooms'], $report['inspections']['first_time_pass_bp'], $report['checklists']['percent']]);
    }

    public function test_it_needs_its_own_permission_and_the_export_is_recorded(): void
    {
        try {
            $this->reports()->housekeeping($this->property(), $this->registrarId, 'today', null, null);
            self::fail('Expected a refusal');
        } catch (Refusal $e) {
            self::assertSame(403, $e->status());
        }

        $this->clean($this->roomIds[0], $this->attendantId, 'vacant', 10);
        $export = $this->reports()->exportHousekeeping($this->property(), $this->analystId, 'today', null, null);
        self::assertStringContainsString('Rooms cleaned', $export['contents']);
        self::assertStringStartsWith('housekeeping-', $export['filename']);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'report.exported')->count());
        self::assertContains('housekeeping', array_column($this->reports()->catalogue($this->property(), $this->analystId), 'code'));
        self::assertNotContains('housekeeping', array_column($this->reports()->catalogue($this->property(), $this->registrarId), 'code'));
    }
}
