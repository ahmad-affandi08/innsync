<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

use App\Shared\Domain\Time\CalendarDate;
use App\Shared\Domain\Time\NonexistentLocalTime;
use App\Shared\Domain\Time\PropertyTimeZone;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * When a scheduled report runs next (FR-RPT-004). A schedule says a time of day on the property's wall clock and, for a week or a month, the weekday or the day of the month (1 to 28, so
 * every month has it). The next run is the first such moment strictly after the given instant, so a run that was missed because the worker was down runs once, not once for every missed
 * occurrence. A time that does not exist on a day (a daylight-saving gap) is moved to the first moment after the gap.
 */
final class ReportScheduleCalendar
{
    public const CADENCES = ['daily', 'weekly', 'monthly'];

    public static function next(string $cadence, ?int $weekday, ?int $monthDay, string $atTime, PropertyTimeZone $zone, DateTimeInterface $after): DateTimeImmutable
    {
        if (! in_array($cadence, self::CADENCES, true)) {
            throw new InvalidArgumentException('The cadence is daily, weekly or monthly.');
        }

        if ($cadence === 'weekly' && ($weekday === null || $weekday < 1 || $weekday > 7)) {
            throw new InvalidArgumentException('A weekly schedule names a weekday, 1 to 7.');
        }

        if ($cadence === 'monthly' && ($monthDay === null || $monthDay < 1 || $monthDay > 28)) {
            throw new InvalidArgumentException('A monthly schedule names a day of the month, 1 to 28.');
        }

        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/D', $atTime) !== 1) {
            throw new InvalidArgumentException('The time of day is hours and minutes.');
        }

        $local = $zone->localize($after);
        $day = $local->setTime(0, 0);

        for ($i = 0; $i < 400; $i++) {
            $candidate = $day->modify("+{$i} days");

            if (! self::onDay($cadence, $weekday, $monthDay, $candidate)) {
                continue;
            }

            $at = self::utc($zone, $candidate->format('Y-m-d'), $atTime);

            if ($at > $after) {
                return $at;
            }
        }

        throw new InvalidArgumentException('No next run was found.');
    }

    private static function utc(PropertyTimeZone $zone, string $date, string $time): DateTimeImmutable
    {
        try {
            return $zone->utcAt(CalendarDate::fromString($date), $time);
        } catch (NonexistentLocalTime) {
            // Skipped by a daylight-saving gap: the first moment after the gap.
            $local = $date.' '.(strlen($time) === 5 ? $time.':00' : $time);

            return (new DateTimeImmutable($local, new DateTimeZone($zone->identifier())))->setTimezone(new DateTimeZone('UTC'));
        }
    }

    private static function onDay(string $cadence, ?int $weekday, ?int $monthDay, DateTimeImmutable $day): bool
    {
        return match ($cadence) {
            'daily' => true,
            'weekly' => (int) $day->format('N') === $weekday,
            default => (int) $day->format('j') === $monthDay,
        };
    }
}
