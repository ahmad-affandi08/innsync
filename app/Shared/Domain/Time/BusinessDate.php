<?php

declare(strict_types=1);

namespace App\Shared\Domain\Time;

/**
 * The operating date of a property (BR-001): the posting boundary for room
 * charges, shifts, settlements and reports. It advances only through night
 * audit and may differ from the clock date at which a transaction occurs.
 *
 * Deliberately has no constructor from a timestamp: deriving a business date
 * from the clock needs the unresolved rollover policy (PRD Q-11) and belongs
 * to the night-audit task, behind `BusinessDateProvider`.
 */
final readonly class BusinessDate extends IsoDate
{
    protected static function label(): string
    {
        return 'business date';
    }
}
