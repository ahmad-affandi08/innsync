<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Domain;

/**
 * The housekeeping dimension of a room (BR-008), separate from occupancy and from being sellable. A room is dirty until an
 * attendant starts cleaning, cleaning while they work, clean when they finish, and ready only after a supervisor passes it
 * (or at once when the property does not require inspection). A failed inspection sends it to rework.
 */
enum CleaningStatus: string
{
    case Dirty = 'dirty';
    case Cleaning = 'cleaning';
    case Clean = 'clean';
    case Ready = 'ready';
    case Rework = 'rework';

    /** Whether a room in this status can be given to a guest. */
    public function isReady(): bool
    {
        return $this === self::Ready;
    }

    public function canMoveTo(self $next): bool
    {
        return match ($this) {
            self::Dirty => in_array($next, [self::Cleaning, self::Dirty], true),
            self::Cleaning => in_array($next, [self::Clean, self::Ready, self::Dirty], true),
            self::Clean => in_array($next, [self::Ready, self::Rework, self::Dirty], true),
            self::Ready => $next === self::Dirty,
            self::Rework => in_array($next, [self::Cleaning, self::Dirty], true),
        };
    }
}
