<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Reporting;

use App\Modules\Reporting\Domain\ReportScheduleCalendar;
use App\Shared\Domain\Time\PropertyTimeZone;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** FR-RPT-004: when a scheduled report runs next, on the property's wall clock. */
final class ReportScheduleCalendarTest extends TestCase
{
    private function utc(string $at): DateTimeImmutable
    {
        return new DateTimeImmutable($at, new DateTimeZone('UTC'));
    }

    private function next(string $cadence, ?int $weekday, ?int $monthDay, string $time, string $after, string $zone = 'Asia/Jakarta'): string
    {
        return ReportScheduleCalendar::next($cadence, $weekday, $monthDay, $time, PropertyTimeZone::fromIdentifier($zone), $this->utc($after))->format('Y-m-d H:i');
    }

    public function test_a_daily_run_is_the_next_moment_of_that_time_strictly_after_the_instant_in_the_property_clock(): void
    {
        // 07:00 in Jakarta is 00:00 UTC.
        self::assertSame('2026-10-04 00:00', $this->next('daily', null, null, '07:00', '2026-10-03 03:00:00'));
        self::assertSame('2026-10-04 00:00', $this->next('daily', null, null, '07:00', '2026-10-03 00:00:00'), 'the moment itself has passed');
        self::assertSame('2026-10-03 00:00', $this->next('daily', null, null, '07:00', '2026-10-02 23:59:59'));
        // 20:00 UTC is already 03:00 of the next day in Jakarta, so 06:30 that day is 23:30 UTC on the third.
        self::assertSame('2026-10-03 23:30', $this->next('daily', null, null, '06:30', '2026-10-03 20:00:00'));
    }

    public function test_a_weekly_run_waits_for_the_weekday_and_a_monthly_one_for_the_day_of_the_month(): void
    {
        // 3 October 2026 is a Saturday (6).
        self::assertSame('2026-10-04 00:00', $this->next('weekly', 7, null, '07:00', '2026-10-03 03:00:00'), 'Sunday');
        self::assertSame('2026-10-10 00:00', $this->next('weekly', 6, null, '07:00', '2026-10-03 03:00:00'), 'this Saturday at 07:00 is past, so the next one');
        self::assertSame('2026-10-05 00:00', $this->next('weekly', 1, null, '07:00', '2026-10-03 03:00:00'), 'Monday');
        self::assertSame('2026-10-28 00:00', $this->next('monthly', null, 28, '07:00', '2026-10-03 03:00:00'));
        self::assertSame('2026-11-01 00:00', $this->next('monthly', null, 1, '07:00', '2026-10-03 03:00:00'));
        self::assertSame('2027-01-01 00:00', $this->next('monthly', null, 1, '07:00', '2026-12-05 03:00:00'), 'across the year');
        self::assertSame('2027-03-28 00:00', $this->next('monthly', null, 28, '07:00', '2027-02-28 03:00:00'), 'the 28th exists in February too');
    }

    public function test_a_missed_run_is_found_once_from_now_not_once_for_every_missed_time(): void
    {
        // Down for a week: the next run after "now" is a single moment.
        self::assertSame('2026-10-11 00:00', $this->next('daily', null, null, '07:00', '2026-10-10 12:00:00'));
    }

    public function test_the_inputs_are_checked(): void
    {
        foreach ([['yearly', null, null, '07:00'], ['weekly', null, null, '07:00'], ['weekly', 8, null, '07:00'], ['monthly', null, 29, '07:00'], ['monthly', null, null, '07:00'], ['daily', null, null, '25:00'], ['daily', null, null, '7:00']] as [$c, $w, $m, $t]) {
            try {
                $this->next($c, $w, $m, $t, '2026-10-03 03:00:00');
                self::fail("{$c} {$w} {$m} {$t} was accepted");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_time_skipped_by_a_daylight_saving_gap_runs_at_the_first_moment_after_it(): void
    {
        // Europe/Berlin springs forward on 2027-03-28: 02:30 does not exist; it becomes 03:30 local, 01:30 UTC.
        self::assertSame('2027-03-28 01:30', $this->next('daily', null, null, '02:30', '2027-03-27 12:00:00', 'Europe/Berlin'));
    }
}
