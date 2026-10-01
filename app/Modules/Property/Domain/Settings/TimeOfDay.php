<?php

declare(strict_types=1);

namespace App\Modules\Property\Domain\Settings;

use InvalidArgumentException;

/** A wall-clock time in the property's zone, `HH:MM`, with no date. */
final readonly class TimeOfDay
{
    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $value) !== 1) {
            throw new InvalidArgumentException('A time of day is HH:MM between 00:00 and 23:59.');
        }

        return new self($value);
    }
}
