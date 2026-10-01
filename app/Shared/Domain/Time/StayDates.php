<?php

declare(strict_types=1);

namespace App\Shared\Domain\Time;

use InvalidArgumentException;

/**
 * The room nights of a stay: arrival inclusive, departure exclusive. A guest arriving on the 1st and leaving on the 3rd
 * occupies the nights of the 1st and the 2nd. Dates are business dates (BR-001).
 */
final readonly class StayDates
{
    public const MAX_NIGHTS = 365;

    public function __construct(public BusinessDate $arrival, public BusinessDate $departure)
    {
        $nights = $arrival->daysUntil($departure);

        if ($nights < 1) {
            throw new InvalidArgumentException('Departure must be after arrival.');
        }

        if ($nights > self::MAX_NIGHTS) {
            throw new InvalidArgumentException('A stay is at most '.self::MAX_NIGHTS.' nights.');
        }
    }

    public function nightCount(): int
    {
        return $this->arrival->daysUntil($this->departure);
    }

    /** @return list<BusinessDate> one per night, in order */
    public function nights(): array
    {
        $nights = [];

        for ($i = 0, $n = $this->nightCount(); $i < $n; $i++) {
            $nights[] = $this->arrival->addDays($i);
        }

        return $nights;
    }

    public function includesNight(BusinessDate $night): bool
    {
        return ! $night->isBefore($this->arrival) && $night->isBefore($this->departure);
    }

    public function overlaps(self $other): bool
    {
        return $this->arrival->isBefore($other->departure) && $other->arrival->isBefore($this->departure);
    }
}
