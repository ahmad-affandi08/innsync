<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Domain;

use DateTimeImmutable;
use DateTimeZone;

/** Whether a reservation may be pre-registered now (FR-GST-006): booked, not yet in the house, from a few days before the arrival until the day of departure. */
final class CheckInWindow
{
    public const OPEN = 'open';

    public const TOO_EARLY = 'too_early';

    public const ENDED = 'ended';

    public const UNAVAILABLE = 'unavailable';

    /**
     * @param  array{status: string, arrival: string, departure: string, has_stay: bool}  $arrival
     * @return array{state: string, opens_on: string}
     */
    public static function of(array $arrival, string $today, int $openDaysBefore): array
    {
        $opensOn = (new DateTimeImmutable($arrival['arrival'], new DateTimeZone('UTC')))->modify('-'.max(0, $openDaysBefore).' days')->format('Y-m-d');

        if (! in_array($arrival['status'], ['confirmed', 'guaranteed'], true) || $arrival['has_stay']) {
            return ['state' => self::UNAVAILABLE, 'opens_on' => $opensOn];
        }

        if ($today >= $arrival['departure']) {
            return ['state' => self::ENDED, 'opens_on' => $opensOn];
        }

        return ['state' => $today < $opensOn ? self::TOO_EARLY : self::OPEN, 'opens_on' => $opensOn];
    }
}
