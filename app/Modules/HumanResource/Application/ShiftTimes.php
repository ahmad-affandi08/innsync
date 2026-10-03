<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Domain\Time\CalendarDate;
use App\Shared\Domain\Time\PropertyTimeZone;
use DateTimeImmutable;
use DateTimeZone;

/** Where a planned day starts and ends in time, and what a time of the clock on a day means in UTC. One place, so attendance, overtime and corrections agree. */
final class ShiftTimes
{
    /**
     * @param  array<string, mixed>  $entry  a roster entry
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable} the planned start and end, in UTC
     */
    public static function window(array $entry, PropertyTimeZone $tz): array
    {
        $date = substr((string) $entry['work_date'], 0, 10);
        $lastEnd = $entry['starts2_at'] !== null ? $entry['ends2_at'] : $entry['ends_at'];
        $nextDay = $entry['starts2_at'] === null && $entry['ends_at'] <= $entry['starts_at'];
        $endDate = $nextDay ? date('Y-m-d', strtotime($date.' +1 day')) : $date;

        return [$tz->utcAt(CalendarDate::fromString($date), $entry['starts_at']), $tz->utcAt(CalendarDate::fromString($endDate), $lastEnd)];
    }

    /** @return array{0: DateTimeImmutable, 1: DateTimeImmutable|null} the time in and the time out of a day, an out that is not after the in being the next morning */
    public static function clocked(PropertyTimeZone $tz, string $date, string $in, ?string $out): array
    {
        $inAt = $tz->utcAt(CalendarDate::fromString($date), $in);

        if ($out === null || $out === '') {
            return [$inAt, null];
        }

        $outDate = $out <= $in ? date('Y-m-d', strtotime($date.' +1 day')) : $date;

        return [$inAt, $tz->utcAt(CalendarDate::fromString($outDate), $out)];
    }

    public static function isDate(string $v): bool
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v, new DateTimeZone('UTC'));

        return $d !== false && $d->format('Y-m-d') === $v;
    }

    public static function isTime(?string $v): bool
    {
        return $v !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) === 1;
    }
}
