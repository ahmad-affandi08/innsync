<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting;

use App\Modules\Reporting\Application\ReportService;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\BuildsHotel;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-RPT-002: the reports filter by date range, outlet, department and person, where they have that dimension, and refuse a filter they do not take. */
final class ReportFiltersTest extends TestCase
{
    use BuildsHotel;
    use RefreshDatabase;
    use SignsInToProperty;

    private const REST = '01arz3ndektsv4rrffq69g5fo1';

    private const BAR = '01arz3ndektsv4rrffq69g5fo2';

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
        $i = 0;

        // The restaurant sold 100,000 (settled by the manager) and the bar 40,000 (by nobody in particular) in cash on the business date.
        foreach ([[self::REST, 'REST', 'Restaurant', 100_000, $this->managerId], [self::BAR, 'BAR', 'Bar', 40_000, null]] as [$id, $code, $name, $base, $actor]) {
            $i++;
            DB::table('fnb_outlets')->insert(['id' => $id, 'property_id' => self::PROPERTY, 'code' => $code, 'name' => $name, 'kind' => 'restaurant', 'is_active' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('fin_pos_sales')->insert([
                'id' => '01arz3ndektsv4rrffq69g5fs'.$i, 'property_id' => self::PROPERTY, 'bill_id' => '01arz3ndektsv4rrffq69g5fb'.$i, 'bill_number' => 'B-'.$i, 'outlet_id' => $id, 'outlet_code' => $code,
                'source' => 'pos_'.strtolower($code), 'business_date' => '2026-10-01', 'currency' => 'IDR', 'base_minor' => $base, 'service_charge_minor' => 0, 'tax_minor' => 0, 'total_minor' => $base,
                'cash_minor' => $base, 'room_minor' => 0, 'event_id' => '01arz3ndektsv4rrffq69g5fe'.$i, 'actor_id' => $actor, 'occurred_at' => now(), 'created_at' => now(),
            ]);
        }
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    private function reports(): ReportService
    {
        return app(ReportService::class);
    }

    private function property(): PropertyId
    {
        return PropertyId::fromString(self::PROPERTY);
    }

    /** @param array<string, ?string> $filters @return array<string, mixed> */
    private function sales(array $filters = []): array
    {
        return $this->reports()->sales($this->property(), $this->analystId, 'custom', '2026-10-01', '2026-10-01', $filters);
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

    public function test_sales_by_outlet_filter_by_outlet_department_and_person_and_the_filters_narrow_one_another(): void
    {
        $all = $this->sales();
        self::assertSame(['bar', 'rest'], array_map('strtolower', array_column($all['rows'], 'code')));
        self::assertSame(140_000, $all['totals']['total']);
        self::assertSame(['filters' => ['user', 'department', 'outlet']], ['filters' => $all['options']['filters']]);
        self::assertSame(['BAR', 'REST'], array_column($all['options']['outlets'], 'code'));

        $rest = $this->sales(['outlet' => self::REST]);
        self::assertSame(['rest'], array_map('strtolower', array_column($rest['rows'], 'code')));
        self::assertSame(100_000, $rest['totals']['total']);
        self::assertSame(['outlet' => 'REST'], $rest['meta']['filters']);

        self::assertSame(140_000, $this->sales(['department' => 'fnb'])['totals']['total']);
        self::assertSame(0, $this->sales(['department' => 'front_office'])['totals']['total']);
        self::assertSame([], $this->sales(['department' => 'laundry'])['rows']);

        $mine = $this->sales(['user' => $this->managerId]);
        self::assertSame(100_000, $mine['totals']['total'], 'only what that person settled');
        self::assertSame(['rest'], array_map('strtolower', array_column($mine['rows'], 'code')));

        // Filters narrow one another: the bar is an F&B outlet, but not the front office's.
        self::assertSame(40_000, $this->sales(['outlet' => self::BAR, 'department' => 'fnb'])['totals']['total']);
        self::assertSame(0, $this->sales(['outlet' => self::BAR, 'department' => 'front_office'])['totals']['total']);
        self::assertSame(0, $this->sales(['outlet' => self::BAR, 'user' => $this->managerId])['totals']['total']);

        // An empty value is no filter.
        self::assertSame(140_000, $this->sales(['outlet' => '', 'department' => null])['totals']['total']);
    }

    public function test_a_filter_that_is_not_valid_or_that_a_report_does_not_take_is_refused(): void
    {
        $this->assertRefused(422, fn () => $this->sales(['outlet' => '01arz3ndektsv4rrffq69g5fo9']));
        $this->assertRefused(422, fn () => $this->sales(['department' => 'spa']));
        $this->assertRefused(422, fn () => $this->sales(['user' => '01arz3ndektsv4rrffq69g5fu9']));
        $this->assertRefused(422, fn () => $this->reports()->flash($this->property(), $this->analystId, 'today', null, null, ['outlet' => self::REST]));
        $this->assertRefused(422, fn () => $this->reports()->payments($this->property(), $this->analystId, 'today', null, null, ['department' => 'fnb']));
        $this->assertRefused(403, fn () => $this->reports()->sales($this->property(), $this->supervisorId, 'today', null, null, []));
    }

    public function test_the_flash_report_filters_its_notable_events_by_department_and_person(): void
    {
        $audit = app(AuditTrail::class);
        $p = $this->property()->toString();
        $audit->record(new AuditEntry($p, $this->managerId, 'fnb_bill.cancelled', 'fnb_bill', '01arz3ndektsv4rrffq69g5faa', null, ['x' => 1]));
        $audit->record(new AuditEntry($p, $this->supervisorId, 'fnb_bill.cancelled', 'fnb_bill', '01arz3ndektsv4rrffq69g5fab', null, ['x' => 1]));
        $audit->record(new AuditEntry($p, $this->managerId, 'work_order.escalated', 'work_order', '01arz3ndektsv4rrffq69g5fac', null, ['x' => 1]));
        $flash = fn (array $filters) => $this->reports()->flash($this->property(), $this->analystId, 'custom', '2026-09-01', '2026-10-31', $filters)['events'];

        self::assertSame([['action' => 'fnb_bill.cancelled', 'count' => 2], ['action' => 'work_order.escalated', 'count' => 1]], $flash([]));
        self::assertSame([['action' => 'fnb_bill.cancelled', 'count' => 2]], $flash(['department' => 'fnb']));
        self::assertSame([['action' => 'work_order.escalated', 'count' => 1]], $flash(['department' => 'maintenance']));
        self::assertSame([], $flash(['department' => 'laundry']));
        self::assertSame([['action' => 'fnb_bill.cancelled', 'count' => 1], ['action' => 'work_order.escalated', 'count' => 1]], $flash(['user' => $this->managerId]));
        self::assertSame([['action' => 'fnb_bill.cancelled', 'count' => 1]], $flash(['user' => $this->managerId, 'department' => 'fnb']));
    }

    public function test_the_reports_that_take_a_person_run_with_it_and_say_so(): void
    {
        $who = ['user' => $this->managerId];
        $name = $this->reports()->sales($this->property(), $this->analystId, 'today', null, null, $who)['meta']['filters']['user'];
        self::assertNotSame('', $name);

        foreach ([
            fn () => $this->reports()->payments($this->property(), $this->analystId, 'today', null, null, $who),
            fn () => $this->reports()->laundry($this->property(), $this->analystId, 'today', null, null, $who),
            fn () => $this->reports()->housekeeping($this->property(), $this->analystId, 'today', null, null, $who),
            fn () => $this->reports()->registrations($this->property(), $this->analystId, 'today', null, null, null, false, $who),
            fn () => $this->reports()->registrations($this->property(), $this->analystId, 'today', null, null, null, true, $who),
        ] as $report) {
            $r = $report();
            self::assertSame($name, $r['meta']['filters']['user']);
            self::assertSame(['user'], $r['options']['filters']);
            self::assertContains($this->managerId, array_column($r['options']['staff'], 'id'));
        }

        // The export carries the filter into the file's meta and is audited like any export.
        $file = $this->reports()->exportSales($this->property(), $this->analystId, 'custom', '2026-10-01', '2026-10-01', ['outlet' => self::REST]);
        self::assertStringContainsString('Restaurant', $file['contents']);
        self::assertStringNotContainsString('Bar', $file['contents']);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'report.exported')->count());
    }
}
