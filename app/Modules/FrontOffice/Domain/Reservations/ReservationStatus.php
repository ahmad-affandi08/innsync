<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Domain\Reservations;

/** Tentative -> confirmed or guaranteed -> checked in -> completed, with cancelled and no-show as branches (PRD state machine). */
enum ReservationStatus: string
{
    case Tentative = 'tentative';
    case Confirmed = 'confirmed';
    case Guaranteed = 'guaranteed';
    case CheckedIn = 'checked_in';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    /** Whether a reservation in this status still holds rooms in the inventory. */
    public function holdsInventory(): bool
    {
        return match ($this) {
            self::Tentative, self::Confirmed, self::Guaranteed, self::CheckedIn => true,
            self::Completed, self::Cancelled, self::NoShow => false,
        };
    }

    /** Before check-in: can still be changed, cancelled or marked no-show. */
    public function isExpected(): bool
    {
        return in_array($this, [self::Tentative, self::Confirmed, self::Guaranteed], true);
    }
}
