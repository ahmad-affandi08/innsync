<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Domain\Stays;

use DomainException;

/** A guest registration or stay rule was refused. `$reasonCode` is stable for tests and screens. */
final class StayRuleViolation extends DomainException
{
    public const INVALID_GUEST = 'invalid_guest';

    public const NOT_CHECKABLE = 'not_checkable';

    public const TOO_EARLY = 'too_early';

    public const PAST_DEPARTURE = 'past_departure';

    public const ALREADY_OUT = 'already_out';

    private function __construct(public readonly string $reasonCode, string $message, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }

    /** `$field` is the form input to highlight. */
    public static function invalidGuest(string $message, ?string $field = null): self
    {
        return new self(self::INVALID_GUEST, $message, $field);
    }

    public static function notCheckable(string $status): self
    {
        return new self(self::NOT_CHECKABLE, "A {$status} reservation can not be checked in; confirm it first or create a new booking.");
    }

    public static function tooEarly(string $arrival): self
    {
        return new self(self::TOO_EARLY, "This reservation arrives on {$arrival}; the business date has not reached it.");
    }

    public static function pastDeparture(string $departure): self
    {
        return new self(self::PAST_DEPARTURE, "The departure date {$departure} has passed; amend the reservation before checking in.");
    }

    public static function alreadyOut(): self
    {
        return new self(self::ALREADY_OUT, 'This stay has already ended.');
    }
}
