<?php

declare(strict_types=1);

namespace App\Modules\Property\Domain\Rates;

use App\Shared\Domain\Time\BusinessDate;
use InvalidArgumentException;

/** The days of the week a price or restriction applies to, as a 7-bit mask: bit 0 is Monday, bit 6 is Sunday. */
final readonly class Weekdays
{
    public const ALL = 127;

    private function __construct(public int $mask)
    {
        if ($mask < 1 || $mask > self::ALL) {
            throw new InvalidArgumentException('Choose at least one day of the week.');
        }
    }

    public static function fromMask(int $mask): self
    {
        return new self($mask);
    }

    public static function all(): self
    {
        return new self(self::ALL);
    }

    public function includes(BusinessDate $date): bool
    {
        return ($this->mask & (1 << ($date->isoWeekday() - 1))) !== 0;
    }

    public function overlaps(self $other): bool
    {
        return ($this->mask & $other->mask) !== 0;
    }
}
