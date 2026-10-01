<?php

declare(strict_types=1);

namespace App\Modules\Property\Domain\Rates;

use App\Shared\Domain\Money\Money;
use App\Shared\Domain\Time\BusinessDate;
use InvalidArgumentException;

/**
 * The nightly price of one room type under one rate plan for stay dates `from` to `to` inclusive, on the chosen weekdays.
 * A period is never edited: a change supersedes it and adds a new one, so what was valid when a reservation was made can
 * always be shown (FR-FO-008). Reservations also snapshot the nightly price they were given.
 */
final readonly class RatePeriod
{
    public function __construct(
        public string $id,
        public string $ratePlanId,
        public string $roomTypeId,
        public BusinessDate $from,
        public BusinessDate $to,
        public Weekdays $weekdays,
        public Money $nightly,
    ) {
        if ($to->isBefore($from)) {
            throw new InvalidArgumentException('The period ends before it starts.');
        }

        if ($from->daysUntil($to) > 1100) {
            throw new InvalidArgumentException('A rate period spans at most about three years.');
        }

        if ($nightly->isNegative()) {
            throw new InvalidArgumentException('A nightly price cannot be negative.');
        }
    }

    public function covers(BusinessDate $night): bool
    {
        return ! $night->isBefore($this->from) && ! $night->isAfter($this->to) && $this->weekdays->includes($night);
    }

    public function overlaps(self $other): bool
    {
        return $this->ratePlanId === $other->ratePlanId
            && $this->roomTypeId === $other->roomTypeId
            && ! $this->to->isBefore($other->from) && ! $other->to->isBefore($this->from)
            && $this->weekdays->overlaps($other->weekdays);
    }
}
