<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting;

use App\Modules\FrontOffice\Application\NightAudit\NightAuditService;
use App\Modules\FrontOffice\Application\Stays\CheckInRequest;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\Reporting\Application\ObligationService;
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

/** FR-DSH-013, FR-DSH-014: tax and service charge collected per month, the date the tax is reported by, whether it was, and the employees' share. */
final class ObligationTest extends TestCase
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
        // One night of one room, charged by the night audit of 1 October: 1,000,000.00 base, 10 percent service charge, 10 percent tax on both.
        $reservation = $this->book('2026-10-01', '2026-10-02', 'confirmed');
        app(StayService::class)->checkIn($this->property(), $this->managerId, new CheckInRequest($reservation->id, $this->roomIds[0], 'Budi Santoso', 'ID', 'ktp', '3174010101900001', null, null, 'Jl. Merdeka 1', 2, 0), IdempotencyKey::fromString('obligation-checkin-01'));
        $this->clock->advance('+13 hours');
        app(NightAuditService::class)->run($this->property(), $this->supervisorId, [], IdempotencyKey::fromString('obligation-audit-01'));
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    private function obligations(): ObligationService
    {
        return app(ObligationService::class);
    }

    private function at(string $businessDate): void
    {
        DB::table('property_settings')->where('property_id', self::PROPERTY)->update(['business_date' => $businessDate]);
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

    /** @return array<string, mixed> */
    private function month(string $month, int $months = 3): array
    {
        return array_column($this->obligations()->timeline($this->property(), $this->analystId, $months)['rows'], null, 'month')[$month];
    }

    public function test_the_month_shows_tax_and_service_charge_by_source_and_stays_open_until_it_ends(): void
    {
        $october = $this->month('2026-10');
        self::assertSame('open', $october['status']);
        self::assertSame($october['tax']['total'], $october['tax']['room']);
        self::assertSame([0, 0], [$october['tax']['laundry'], $october['tax']['other']]);
        self::assertGreaterThan(0, $october['tax']['total']);
        self::assertGreaterThan(0, $october['service_charge']['total']);
        self::assertSame(intdiv($october['service_charge']['total'] * 6_000, 10_000), $october['employee_estimate_minor'], 'the baseline share is 60 percent');
        self::assertSame($october['tax']['total'], array_sum(DB::table('folio_postings')->where('source', 'night_audit')->pluck('tax_minor')->map(fn ($v): int => (int) $v)->all()));
        self::assertFalse($this->obligations()->timeline($this->property(), $this->analystId)['settings']['configured']);
        self::assertSame(3, count($this->obligations()->timeline($this->property(), $this->analystId, 3)['rows']));
    }

    public function test_a_closed_month_is_due_then_overdue_and_is_reported_once_with_the_amount_shown(): void
    {
        $this->refused(fn () => $this->obligations()->markReported($this->property(), $this->analystId, '2026-10', '2026-11-05', 'SPTPD-1'), 403);
        $this->at('2026-11-02');
        self::assertSame(['due', '2026-11-15'], [$this->month('2026-10')['status'], $this->month('2026-10')['due_date']]);
        self::assertSame('nothing', $this->month('2026-09')['status']);
        $this->at('2026-11-16');
        self::assertSame('overdue', $this->month('2026-10')['status']);

        $this->refused(fn () => $this->obligations()->markReported($this->property(), $this->financeId, '2026-10', '2026-10-31', 'SPTPD-1'), 422);
        $this->refused(fn () => $this->obligations()->markReported($this->property(), $this->financeId, '2026-10', '2026-12-01', 'SPTPD-1'), 422);
        $this->refused(fn () => $this->obligations()->markReported($this->property(), $this->financeId, '2026-10', '2026-11-10', ' '), 422);
        $this->refused(fn () => $this->obligations()->markReported($this->property(), $this->financeId, '2026-09', '2026-11-10', 'SPTPD-0'), 409);
        $this->refused(fn () => $this->obligations()->markReported($this->property(), $this->financeId, '2026-11', '2026-11-16', 'SPTPD-N'), 409);
        $tax = $this->month('2026-10')['tax']['total'];

        $filing = $this->obligations()->markReported($this->property(), $this->financeId, '2026-10', '2026-11-12', 'SPTPD-2026-10');
        self::assertSame([$tax, 'SPTPD-2026-10'], [$filing['tax_minor'], $filing['reference']]);
        $this->refused(fn () => $this->obligations()->markReported($this->property(), $this->financeId, '2026-10', '2026-11-12', 'SPTPD-AGAIN'), 409);
        $october = $this->month('2026-10');
        self::assertSame(['reported', '2026-11-12'], [$october['status'], $october['filing']['reported_on']]);
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'reporting.tax.reported')->count());
        self::assertSame(1, DB::table('audit_entries')->where('action', 'obligation.tax.reported')->count());

        foreach ([fn () => DB::table('tax_filings')->update(['reference' => 'x']), fn () => DB::table('tax_filings')->delete()] as $attempt) {
            try {
                $attempt();
                self::fail('Expected the database to refuse');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_the_day_and_the_share_are_settings_changed_with_a_reason_and_a_version(): void
    {
        $this->at('2026-11-20');
        $saved = $this->obligations()->saveSettings($this->property(), $this->financeId, 10, 7_500, null, 'Regional regulation confirmed by the tax consultant');
        self::assertSame([10, 7_500, 0], [$saved['tax_report_day'], $saved['service_employee_share_bp'], $saved['lock_version']]);
        $october = $this->month('2026-10');
        self::assertSame('2026-11-10', $october['due_date']);
        self::assertSame(intdiv($october['service_charge']['total'] * 7_500, 10_000), $october['employee_estimate_minor']);
        self::assertTrue($this->obligations()->timeline($this->property(), $this->analystId)['settings']['configured']);

        $this->refused(fn () => $this->obligations()->saveSettings($this->property(), $this->financeId, 12, 7_500, null, 'Again as new'), 409);
        $this->refused(fn () => $this->obligations()->saveSettings($this->property(), $this->financeId, 12, 7_500, 5, 'Stale'), 409);
        $this->refused(fn () => $this->obligations()->saveSettings($this->property(), $this->financeId, 29, 7_500, 0, 'Bad day'), 422);
        $this->refused(fn () => $this->obligations()->saveSettings($this->property(), $this->financeId, 12, 10_001, 0, 'Bad share'), 422);
        $this->refused(fn () => $this->obligations()->saveSettings($this->property(), $this->financeId, 12, 7_000, 0, ' '), 422);
        $this->refused(fn () => $this->obligations()->saveSettings($this->property(), $this->analystId, 12, 7_000, 0, 'No right'), 403);
        self::assertSame(12, $this->obligations()->saveSettings($this->property(), $this->financeId, 12, 7_000, 0, 'Moved')['tax_report_day']);
        self::assertSame(2, DB::table('audit_entries')->where('action', 'obligation.settings.changed')->count());
        $this->refused(fn () => $this->obligations()->timeline($this->property(), $this->analystId, 0), 422);
        $this->refused(fn () => $this->obligations()->timeline($this->property(), $this->clerkId), 403);
    }
}
