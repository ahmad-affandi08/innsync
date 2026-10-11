<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Activities;

/** Whether a booking fits: at no moment of it may the guests taking part together, with those already booked, pass what the service can take at once. */
final class ActivityCapacity
{
    /**
     * @param  list<array{start: int, end: int, guests: int}>  $existing  bookings of the same service on the same day, in minutes from midnight
     */
    public static function fits(array $existing, int $start, int $end, int $guests, int $capacity): bool
    {
        // The load only grows when something starts, so it is enough to look at the moment the new booking starts and at every start inside it.
        $moments = [$start];

        foreach ($existing as $e) {
            if ($e['start'] > $start && $e['start'] < $end) {
                $moments[] = $e['start'];
            }
        }

        foreach ($moments as $at) {
            $load = $guests;

            foreach ($existing as $e) {
                if ($e['start'] <= $at && $at < $e['end']) {
                    $load += $e['guests'];
                }
            }

            if ($load > $capacity) {
                return false;
            }
        }

        return true;
    }

    /** Minutes from midnight of a "HH:MM" time, or null when it is not one. */
    public static function minutes(string $time): ?int
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/D', $time, $m) !== 1) {
            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }
}
