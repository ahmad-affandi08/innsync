<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Time;

use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\CalendarDate;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BusinessDateTest extends TestCase
{
    /** @return iterable<string, array{0: string}> */
    public static function invalid(): iterable
    {
        yield 'impossible day' => ['2026-02-30'];
        yield 'non leap year' => ['2026-02-29'];
        yield 'month 13' => ['2026-13-01'];
        yield 'single digit parts' => ['2026-1-5'];
        yield 'with time' => ['2026-10-01 00:00:00'];
        yield 'iso timestamp' => ['2026-10-01T00:00:00Z'];
        yield 'slashes' => ['01/10/2026'];
        yield 'trailing newline' => ["2026-10-01\n"];
        yield 'empty' => [''];
    }

    #[DataProvider('invalid')]
    public function test_rejects_anything_that_is_not_a_real_iso_date(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        BusinessDate::fromString($value);
    }

    public function test_accepts_leap_day_and_round_trips(): void
    {
        self::assertSame('2028-02-29', BusinessDate::fromString('2028-02-29')->toString());
    }

    public function test_calendar_arithmetic_crosses_month_and_year_boundaries(): void
    {
        $date = BusinessDate::fromString('2026-12-31');

        self::assertSame('2027-01-01', $date->next()->toString());
        self::assertSame('2026-12-30', $date->previous()->toString());
        self::assertSame('2026-03-01', BusinessDate::fromString('2026-02-28')->next()->toString());
        self::assertSame('2028-02-29', BusinessDate::fromString('2028-02-28')->next()->toString());
        self::assertSame('2026-10-31', BusinessDate::fromString('2026-10-01')->addDays(30)->toString());
        self::assertSame('2026-09-01', BusinessDate::fromString('2026-10-01')->addDays(-30)->toString());
    }

    public function test_day_counts_are_exact_across_daylight_saving_months(): void
    {
        // Calendar days, not 24-hour blocks: UTC midnight arithmetic has no DST gaps.
        self::assertSame(1, BusinessDate::fromString('2026-03-08')->daysUntil(BusinessDate::fromString('2026-03-09')));
        self::assertSame(-7, BusinessDate::fromString('2026-11-08')->daysUntil(BusinessDate::fromString('2026-11-01')));
        self::assertSame(0, BusinessDate::fromString('2026-10-01')->daysUntil(BusinessDate::fromString('2026-10-01')));
        self::assertSame(365, BusinessDate::fromString('2026-01-01')->daysUntil(BusinessDate::fromString('2027-01-01')));
    }

    public function test_comparison(): void
    {
        $a = BusinessDate::fromString('2026-10-01');
        $b = BusinessDate::fromString('2026-10-02');

        self::assertTrue($a->isBefore($b));
        self::assertTrue($b->isAfter($a));
        self::assertTrue($a->equals(BusinessDate::fromString('2026-10-01')));
        self::assertFalse($a->equals($b));
    }

    public function test_a_business_date_can_never_be_compared_with_a_calendar_date(): void
    {
        $business = BusinessDate::fromString('2026-10-01');
        $calendar = CalendarDate::fromString('2026-10-01');

        foreach ([
            fn () => $business->equals($calendar),
            fn () => $business->isBefore($calendar),
            fn () => $calendar->isAfter($business),
            fn () => $calendar->daysUntil($business),
        ] as $mixup) {
            try {
                $mixup();
                self::fail('Mixing business and calendar dates must be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('can not be compared', $exception->getMessage());
            }
        }
    }

    public function test_arithmetic_preserves_the_kind(): void
    {
        self::assertInstanceOf(BusinessDate::class, BusinessDate::fromString('2026-10-01')->next());
        self::assertInstanceOf(CalendarDate::class, CalendarDate::fromString('2026-10-01')->next());
    }
}
