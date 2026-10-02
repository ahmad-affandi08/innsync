<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting;

use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Stays\CheckInRequest;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\Reporting\Application\ReportBuilderService;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\BuildsHotel;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-RPT-008: the simple report builder, with chosen columns, filters and sort over datasets that hold no personal data. */
final class ReportBuilderTest extends TestCase
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
        $a = $this->book('2026-10-01', '2026-10-03', 'confirmed');
        $this->book('2026-10-05', '2026-10-06', 'tentative');
        $this->book('2026-12-01', '2026-12-02', 'confirmed');
        app(StayService::class)->checkIn($this->property(), $this->managerId, new CheckInRequest($a->id, $this->roomIds[0], 'Budi Santoso', 'ID', 'ktp', '3174010101900001', null, null, 'Jl. Merdeka 1', 2, 0), IdempotencyKey::fromString('builder-checkin-0001'));
        $folio = DB::table('folios')->where('reservation_id', $a->id)->value('id');
        app(FolioService::class)->charge($this->property(), $this->managerId, $folio, 'MINIBAR', 'Minibar', 10_000_000, false);
        app(FolioService::class)->pay($this->property(), $this->managerId, $folio, 'cash', 3_000_000, null, 'deposit');
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    private function builder(): ReportBuilderService
    {
        return app(ReportBuilderService::class);
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

    /** @param list<string> $columns @param array<string, string> $filters */
    private function read(string $dataset, array $columns, array $filters = [], ?string $from = '2026-10-01', ?string $to = '2026-12-31', ?string $sort = null, string $dir = 'asc', ?string $actor = null): array
    {
        return $this->builder()->run($this->property(), $actor ?? $this->builderId, $dataset, $columns, $filters, $from, $to, $sort, $dir);
    }

    public function test_it_needs_its_own_right(): void
    {
        $this->refused(fn () => $this->builder()->catalogue($this->property(), $this->analystId), 403);
        $this->refused(fn () => $this->read('reservations', ['number'], actor: $this->managerId), 403);
        $catalogue = $this->builder()->catalogue($this->property(), $this->builderId);
        self::assertSame(['reservations', 'postings', 'laundry', 'tasks'], array_keys($catalogue['datasets']));
        self::assertSame(['2026-10-01', 500], [$catalogue['business_date'], $catalogue['view_limit']]);
    }

    public function test_the_datasets_hold_no_personal_data(): void
    {
        foreach (ReportBuilderService::DATASETS as $name => $set) {
            foreach (array_keys($set['columns']) as $column) {
                self::assertDoesNotMatchRegularExpression('/name|phone|email|address|identity|id_number|guest|birth|contact|notes/i', $column, "{$name}.{$column} must not carry personal data");
            }
        }
    }

    public function test_reservations_are_read_with_the_chosen_columns_filters_and_sort(): void
    {
        $all = $this->read('reservations', ['number', 'arrival', 'status', 'nights'], sort: 'arrival', dir: 'desc');
        self::assertSame(['number', 'arrival', 'status', 'nights'], $all['columns']);
        self::assertSame(['2026-12-01', '2026-10-05', '2026-10-01'], array_column($all['rows'], 'arrival'));
        self::assertSame([1, 1, 2], array_column($all['rows'], 'nights'));
        self::assertFalse($all['truncated']);
        self::assertSame(['text', 'date', 'text', 'number'], array_values($all['types']));

        // The first booking has been checked in, so only the December one is still just confirmed.
        self::assertCount(1, $this->read('reservations', ['number'], ['status' => 'confirmed'])['rows']);
        self::assertCount(1, $this->read('reservations', ['number'], ['status' => 'checked_in'])['rows']);
        // The range is on the arrival date: October only.
        $october = $this->read('reservations', ['arrival'], [], '2026-10-01', '2026-10-31');
        self::assertCount(2, $october['rows']);
        self::assertSame([], array_diff(array_keys($october['rows'][0]), ['arrival']));
    }

    public function test_postings_show_money_in_minor_units_and_can_be_filtered_by_a_source(): void
    {
        $postings = $this->read('postings', ['business_date', 'entry_type', 'code', 'total_minor', 'payment_method'], sort: 'total_minor', dir: 'desc');
        self::assertSame(['charge', 'payment'], array_column($postings['rows'], 'entry_type'));
        self::assertSame([12_100_000, -3_000_000], array_column($postings['rows'], 'total_minor'));
        self::assertSame([null, 'cash'], array_column($postings['rows'], 'payment_method'));

        self::assertCount(1, $this->read('postings', ['code'], ['source' => 'front_office', 'entry_type' => 'charge'])['rows']);
        self::assertCount(0, $this->read('postings', ['code'], ['source' => 'laundry'])['rows']);
    }

    public function test_housekeeping_tasks_use_the_calendar_dates_of_the_property(): void
    {
        $this->read('tasks', ['room', 'kind', 'status', 'minutes']);
        DB::table('housekeeping_tasks')->count();
        $rows = $this->read('tasks', ['room', 'kind', 'status', 'priority'], [], '2026-09-01', '2026-09-30')['rows'];
        self::assertSame([], $rows);
    }

    public function test_laundry_orders_are_listed_with_their_flags(): void
    {
        $rows = $this->read('laundry', ['number', 'express', 'has_discrepancy'])['rows'];
        self::assertSame([], $rows);
    }

    public function test_the_choices_are_checked_against_the_catalogue(): void
    {
        $this->refused(fn () => $this->read('guests', ['name']), 422);
        $this->refused(fn () => $this->read('reservations', []), 422);
        $this->refused(fn () => $this->read('reservations', ['guest_name']), 422);
        $this->refused(fn () => $this->read('reservations', ['number; DROP TABLE reservations']), 422);
        $this->refused(fn () => $this->read('reservations', ['number'], sort: 'guest_name'), 422);
        $this->refused(fn () => $this->read('reservations', ['number'], dir: 'sideways'), 422);
        $this->refused(fn () => $this->read('reservations', ['number'], ['guest_name' => 'x']), 422);
        $this->refused(fn () => $this->read('reservations', ['number'], ['status' => 'maybe']), 422);
        $this->refused(fn () => $this->read('postings', ['code'], ['source' => "x' OR '1'='1"]), 422);
        $this->refused(fn () => $this->read('reservations', ['number'], from: '2026-13-01'), 422);
        $this->refused(fn () => $this->read('reservations', ['number'], from: '2026-10-05', to: '2026-10-01'), 422);
        $this->refused(fn () => $this->read('reservations', ['number'], from: '2024-01-01', to: '2026-12-31'), 422);
        self::assertSame(3, DB::table('reservations')->count());
    }

    public function test_a_large_result_is_cut_and_says_so(): void
    {
        $base = DB::table('reservations')->first();

        for ($i = 0; $i < ReportBuilderService::VIEW_LIMIT; $i++) {
            DB::table('reservations')->insert([...(array) $base, 'id' => strtolower((string) Str::ulid()), 'number' => sprintf('RSV-9%05d', $i)]);
        }

        $report = $this->read('reservations', ['number']);
        self::assertSame([ReportBuilderService::VIEW_LIMIT, true], [count($report['rows']), $report['truncated']]);
    }

    public function test_an_export_is_a_csv_with_flags_as_numbers_and_is_audited(): void
    {
        $file = $this->builder()->export($this->property(), $this->builderId, 'reservations', ['number', 'oversold', 'total_minor'], ['status' => 'confirmed'], '2026-10-01', '2026-12-31', 'number', 'asc');

        self::assertSame('builder-reservations-2026-10-01-2026-12-31.csv', $file['filename']);
        $lines = array_values(array_filter(explode("\r\n", ltrim($file['contents'], "\xEF\xBB\xBF"))));
        self::assertSame('"number","oversold","total_minor"', $lines[0]);
        self::assertCount(2, $lines);
        self::assertSame('"RSV-000003","0","121000000"', $lines[1]);
        $audit = DB::table('audit_entries')->where('action', 'report.exported')->first();
        self::assertSame($this->builderId, $audit->actor_id);
        $after = json_decode((string) $audit->after_state, true);
        self::assertSame(['builder:reservations', 1, false, ['status' => 'confirmed']], [$after['report'], $after['rows'], $after['personal_data'], $after['filters']]);
        $this->refused(fn () => $this->builder()->export($this->property(), $this->managerId, 'reservations', ['number'], [], null, null, null, 'asc'), 403);
    }
}
