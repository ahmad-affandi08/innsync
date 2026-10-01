<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Domain\Reservations;

use DomainException;

/** A reservation rule or state transition was refused. `$code` is stable for tests and screens. */
final class ReservationRuleViolation extends DomainException
{
    public const NOT_EXPECTED = 'not_expected';

    public const REASON_REQUIRED = 'reason_required';

    public const TOO_EARLY_FOR_NO_SHOW = 'too_early_for_no_show';

    public const INVALID_GUEST = 'invalid_guest';

    private function __construct(public readonly string $reasonCode, string $message)
    {
        parent::__construct($message);
    }

    public static function notExpected(ReservationStatus $status): self
    {
        return new self(self::NOT_EXPECTED, "A {$status->value} reservation can no longer be changed this way.");
    }

    public static function reasonRequired(): self
    {
        return new self(self::REASON_REQUIRED, 'A reason of at most 500 characters is required.');
    }

    public static function tooEarlyForNoShow(): self
    {
        return new self(self::TOO_EARLY_FOR_NO_SHOW, 'A reservation can be marked no-show only on or after its arrival date.');
    }

    public static function invalidGuest(string $message): self
    {
        return new self(self::INVALID_GUEST, $message);
    }
}
