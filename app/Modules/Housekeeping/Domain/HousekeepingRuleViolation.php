<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Domain;

use DomainException;

/** A housekeeping rule was refused. `$reasonCode` is stable for tests and screens. */
final class HousekeepingRuleViolation extends DomainException
{
    public const NOT_ALLOWED = 'not_allowed';

    public const INVALID = 'invalid';

    private function __construct(public readonly string $reasonCode, string $message)
    {
        parent::__construct($message);
    }

    public static function notAllowed(string $message): self
    {
        return new self(self::NOT_ALLOWED, $message);
    }

    public static function invalid(string $message): self
    {
        return new self(self::INVALID, $message);
    }

    public static function transition(CleaningStatus $from, CleaningStatus $to): self
    {
        return new self(self::NOT_ALLOWED, "A room that is {$from->value} cannot become {$to->value}.");
    }
}
