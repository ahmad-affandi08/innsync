<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\DayUse;

/** The clock of a day use: times are "HH:MM" of one day, and two bookings of a room must not overlap (one may end when the next starts). */
final class DayUseTimes
{
    public static function minutes(string $time): ?int
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/D', $time, $m) !== 1) {
            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }

    public static function time(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** @param list<array{start: int, end: int}> $others */
    public static function free(array $others, int $start, int $end): bool
    {
        foreach ($others as $o) {
            if ($start < $o['end'] && $o['start'] < $end) {
                return false;
            }
        }

        return true;
    }
}
