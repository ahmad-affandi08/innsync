<?php

declare(strict_types=1);

namespace App\Modules\Property\Domain\Rates;

use App\Shared\Domain\Time\BusinessDate;
use InvalidArgumentException;

/**
 * Selling restrictions for stay dates `from` to `to` inclusive: minimum or maximum stay, closed to arrival, closed to
 * departure, or stopped from sale. `roomTypeId` null means every room type of the plan. Several restrictions can apply
 * to one date; the strictest of each kind wins (`RateQuoteService`).
 */
final readonly class RateRestriction
{
    public function __construct(
        public string $id,
        public string $ratePlanId,
        public ?string $roomTypeId,
        public BusinessDate $from,
        public BusinessDate $to,
        public ?int $minStay,
        public ?int $maxStay,
        public bool $closedToArrival,
        public bool $closedToDeparture,
        public bool $stopSell,
    ) {
        if ($to->isBefore($from)) {
            throw new InvalidArgumentException('The restriction ends before it starts.');
        }

        if (($minStay !== null && ($minStay < 1 || $minStay > 365)) || ($maxStay !== null && ($maxStay < 1 || $maxStay > 365))) {
            throw new InvalidArgumentException('Minimum and maximum stay are between 1 and 365 nights.');
        }

        if ($minStay !== null && $maxStay !== null && $minStay > $maxStay) {
            throw new InvalidArgumentException('The minimum stay is longer than the maximum stay.');
        }

        if ($minStay === null && $maxStay === null && ! $closedToArrival && ! $closedToDeparture && ! $stopSell) {
            throw new InvalidArgumentException('A restriction must restrict something.');
        }
    }

    public function covers(BusinessDate $date): bool
    {
        return ! $date->isBefore($this->from) && ! $date->isAfter($this->to);
    }

    public function appliesToType(string $roomTypeId): bool
    {
        return $this->roomTypeId === null || $this->roomTypeId === $roomTypeId;
    }
}
