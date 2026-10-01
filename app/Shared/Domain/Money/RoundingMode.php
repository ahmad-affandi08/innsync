<?php

declare(strict_types=1);

namespace App\Shared\Domain\Money;

enum RoundingMode: string
{
    /** Half away from zero: 0.5 becomes 1 and -0.5 becomes -1. The usual commercial rule. */
    case HalfUp = 'half_up';
    case HalfEven = 'half_even';
    /** Toward zero. */
    case Down = 'down';
    /** Away from zero. */
    case Up = 'up';
}
