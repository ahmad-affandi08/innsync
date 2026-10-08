<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Time;

use App\Shared\Domain\Time\DateMath;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DateMathTest extends TestCase
{
    protected function tearDown(): void
    {
        date_default_timezone_set('UTC');
    }

    public function test_it_gives_the_same_answer_as_strtotime_in_utc_whatever_zone_the_server_is_set_to(): void
    {
        $cases = [
            ['Y-m-d', '2026-10-01 +7 days'],
            ['Y-m-d', '2026-03-29 +1 day'],
            ['Y-m-d', '2026-01-31 +1 month'],
            ['Y-m-t', '2026-02-01'],
            ['Y-m-01', '2026-10-01 -3 months'],
            ['Y-m', '2026-12-01 +1 month'],
        ];

        foreach (['UTC', 'Asia/Jakarta', 'Europe/Berlin', 'America/Sao_Paulo'] as $zone) {
            date_default_timezone_set($zone);

            foreach ($cases as [$format, $expression]) {
                date_default_timezone_set('UTC');
                $expected = date($format, (int) strtotime($expression));
                date_default_timezone_set($zone);
                self::assertSame($expected, DateMath::format($format, $expression), "{$expression} in {$zone}");
            }
        }
    }

    public function test_it_moves_from_a_base_date(): void
    {
        self::assertSame('2026-10-05', DateMath::format('Y-m-d', 'monday this week', '2026-10-07'));
        self::assertSame('2026-10-08', DateMath::addDays('2026-10-01', 7));
        self::assertSame('2026-09-24', DateMath::addDays('2026-10-01', -7));
        self::assertSame('2026-10-01', DateMath::addDays('2026-10-01', 0));
    }

    public function test_days_between_counts_whole_days_across_a_daylight_saving_change_and_signs_the_direction(): void
    {
        date_default_timezone_set('Europe/Berlin');

        self::assertSame(2, DateMath::daysBetween('2026-03-28', '2026-03-30'));
        self::assertSame(0, DateMath::daysBetween('2026-10-01', '2026-10-01'));
        self::assertSame(-3, DateMath::daysBetween('2026-10-04', '2026-10-01'));
        self::assertSame(365, DateMath::daysBetween('2026-01-01', '2027-01-01'));
    }

    public function test_something_that_is_not_a_date_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DateMath::format('Y-m-d', 'not a date at all');
    }
}
