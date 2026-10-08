<?php

declare(strict_types=1);

namespace App\Shared\Domain\Time;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * Arithmetic on calendar dates written as `YYYY-MM-DD`, for the places that move a date by days or months or find the end of a month. It always works in UTC and never in the zone the server happens to be set to, so
 * a day is always 24 hours long and the answer is the same everywhere. The wording of a change is the one PHP's `strtotime` understands (`+7 days`, `-1 month`, `last day of this month`), so a month that
 * runs over (31 January plus a month) behaves as it always did here. Never use it for an instant or for a posting date; those come from the clock and the business date.
 */
final class DateMath
{
    /**
     * `$expression` is a date with an optional change (`2026-10-01 +7 days`), or only a change when `$base` is given.
     *
     * @throws InvalidArgumentException when it cannot be read as a date
     */
    public static function format(string $format, string $expression, ?string $base = null): string
    {
        try {
            $zone = new DateTimeZone('UTC');
            $from = $base === null ? null : new DateTimeImmutable($base, $zone);

            return ($from === null ? new DateTimeImmutable($expression, $zone) : $from->modify($expression))->format($format);
        } catch (Throwable) {
            throw new InvalidArgumentException('That is not a date: '.$expression);
        }
    }

    /** The date `$days` after `$date` (before when negative). */
    public static function addDays(string $date, int $days): string
    {
        return self::format('Y-m-d', ($days >= 0 ? '+' : '').$days.' days', $date);
    }

    /** Whole days from `$from` to `$to`; negative when `$to` is the earlier one. */
    public static function daysBetween(string $from, string $to): int
    {
        try {
            $zone = new DateTimeZone('UTC');

            return (int) (new DateTimeImmutable($from, $zone))->setTime(0, 0)->diff((new DateTimeImmutable($to, $zone))->setTime(0, 0))->format('%r%a');
        } catch (Throwable) {
            throw new InvalidArgumentException('That is not a date: '.$from.' / '.$to);
        }
    }
}
