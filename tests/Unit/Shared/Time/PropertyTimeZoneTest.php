<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Time;

use App\Shared\Domain\Time\CalendarDate;
use App\Shared\Domain\Time\NonexistentLocalTime;
use App\Shared\Domain\Time\PropertyTimeZone;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PropertyTimeZoneTest extends TestCase
{
    private function utc(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    /** @return iterable<string, array{0: string}> */
    public static function invalid(): iterable
    {
        yield 'abbreviation' => ['WIB'];
        yield 'fixed offset' => ['+07:00'];
        yield 'wrong case' => ['asia/jakarta'];
        yield 'typo' => ['Asia/Jakartaa'];
        yield 'empty' => [''];
        yield 'path traversal' => ['../etc/passwd'];
    }

    #[DataProvider('invalid')]
    public function test_only_iana_region_identifiers_are_accepted(string $identifier): void
    {
        $this->expectException(InvalidArgumentException::class);

        PropertyTimeZone::fromIdentifier($identifier);
    }

    public function test_accepts_region_identifiers_and_utc(): void
    {
        self::assertSame('Asia/Jakarta', PropertyTimeZone::fromIdentifier('Asia/Jakarta')->identifier());
        self::assertSame('UTC', PropertyTimeZone::fromIdentifier('UTC')->identifier());
    }

    public function test_the_clock_date_can_differ_from_the_utc_date(): void
    {
        $jakarta = PropertyTimeZone::fromIdentifier('Asia/Jakarta'); // UTC+7, no DST
        $honolulu = PropertyTimeZone::fromIdentifier('Pacific/Honolulu'); // UTC-10

        // 23:30 UTC on 1 Oct is already 2 Oct 06:30 in Jakarta, but still 1 Oct 13:30 in Honolulu.
        $instant = $this->utc('2026-10-01 23:30:00');

        self::assertSame('2026-10-02', $jakarta->calendarDateAt($instant)->toString());
        self::assertSame('2026-10-01', $honolulu->calendarDateAt($instant)->toString());
        self::assertSame('2026-10-02 06:30:00', $jakarta->localize($instant)->format('Y-m-d H:i:s'));
        // Localizing never changes the instant itself.
        self::assertSame($instant->getTimestamp(), $jakarta->localize($instant)->getTimestamp());
    }

    public function test_local_time_converts_to_utc(): void
    {
        $jakarta = PropertyTimeZone::fromIdentifier('Asia/Jakarta');

        self::assertSame(
            '2026-10-01 07:00:00',
            $jakarta->utcAt(CalendarDate::fromString('2026-10-01'), '14:00')->format('Y-m-d H:i:s'),
        );
        // 00:30 local on the 2nd is still the 1st in UTC.
        self::assertSame(
            '2026-10-01 17:30:00',
            $jakarta->utcAt(CalendarDate::fromString('2026-10-02'), '00:30')->format('Y-m-d H:i:s'),
        );
        self::assertSame('UTC', $jakarta->utcAt(CalendarDate::fromString('2026-10-02'))->getTimezone()->getName());
    }

    public function test_a_time_skipped_by_daylight_saving_is_rejected_not_shifted(): void
    {
        $newYork = PropertyTimeZone::fromIdentifier('America/New_York');

        $this->expectException(NonexistentLocalTime::class);

        // Clocks jump from 02:00 to 03:00 on 8 March 2026.
        $newYork->utcAt(CalendarDate::fromString('2026-03-08'), '02:30');
    }

    public function test_a_repeated_time_resolves_to_its_first_occurrence(): void
    {
        $newYork = PropertyTimeZone::fromIdentifier('America/New_York');

        // 01:30 happens twice on 1 Nov 2026; the first is still daylight time (UTC-4).
        self::assertSame(
            '2026-11-01 05:30:00',
            $newYork->utcAt(CalendarDate::fromString('2026-11-01'), '01:30')->format('Y-m-d H:i:s'),
        );
    }

    public function test_time_format_is_validated(): void
    {
        $jakarta = PropertyTimeZone::fromIdentifier('Asia/Jakarta');

        foreach (["12:00\n", '24:00', '9:00', '12:60', 'noon', '12:00:61', ''] as $bad) {
            try {
                $jakarta->utcAt(CalendarDate::fromString('2026-10-01'), $bad);
                self::fail("{$bad} must be rejected");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_calendar_day_is_a_half_open_utc_range_that_follows_daylight_saving(): void
    {
        $newYork = PropertyTimeZone::fromIdentifier('America/New_York');
        $hours = static fn (array $range): float => ($range[1]->getTimestamp() - $range[0]->getTimestamp()) / 3600;

        self::assertSame(23.0, $hours($newYork->utcRangeOf(CalendarDate::fromString('2026-03-08'))));
        self::assertSame(25.0, $hours($newYork->utcRangeOf(CalendarDate::fromString('2026-11-01'))));
        self::assertSame(24.0, $hours($newYork->utcRangeOf(CalendarDate::fromString('2026-06-15'))));

        [$start, $end] = PropertyTimeZone::fromIdentifier('Asia/Jakarta')->utcRangeOf(CalendarDate::fromString('2026-10-01'));
        self::assertSame('2026-09-30 17:00:00', $start->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-01 17:00:00', $end->format('Y-m-d H:i:s'));
        // Adjacent days tile with no gap and no overlap.
        self::assertSame(
            $end->getTimestamp(),
            PropertyTimeZone::fromIdentifier('Asia/Jakarta')->utcRangeOf(CalendarDate::fromString('2026-10-02'))[0]->getTimestamp(),
        );
    }

    public function test_equality(): void
    {
        self::assertTrue(PropertyTimeZone::fromIdentifier('UTC')->equals(PropertyTimeZone::fromIdentifier('UTC')));
        self::assertFalse(PropertyTimeZone::fromIdentifier('UTC')->equals(PropertyTimeZone::fromIdentifier('Asia/Jakarta')));
    }
}
