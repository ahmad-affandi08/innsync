<?php

declare(strict_types=1);

namespace App\Modules\Laundry\Domain;

use DomainException;

/** A laundry rule was refused. `$reasonCode` is stable for tests and screens. */
final class LaundryRuleViolation extends DomainException
{
    public const INVALID = 'invalid';

    public const NOT_ALLOWED = 'not_allowed';

    private function __construct(public readonly string $reasonCode, string $message, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }

    public static function invalid(string $message, ?string $field = null): self
    {
        return new self(self::INVALID, $message, $field);
    }

    public static function notAllowed(string $message): self
    {
        return new self(self::NOT_ALLOWED, $message);
    }
}
