<?php

declare(strict_types=1);

namespace App\Shared\Domain\Time;

/** The clock date of an instant in a property's time zone. Never a posting date. */
final readonly class CalendarDate extends IsoDate
{
    protected static function label(): string
    {
        return 'calendar date';
    }
}
