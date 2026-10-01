<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Domain\Folios;

use DomainException;

/** A folio rule was broken. `$reasonCode` is stable for tests and screens. */
final class FolioRuleViolation extends DomainException
{
    public const CLOSED = 'folio_closed';

    public const INVALID = 'invalid_posting';

    public const NOT_REVERSIBLE = 'not_reversible';

    public const BALANCE_NOT_ZERO = 'balance_not_zero';

    public const CURRENCY = 'currency_mismatch';

    private function __construct(public readonly string $reasonCode, string $message)
    {
        parent::__construct($message);
    }

    public static function closed(): self
    {
        return new self(self::CLOSED, 'The folio is closed. Late charges go through the late-charge flow.');
    }

    public static function invalid(string $message): self
    {
        return new self(self::INVALID, $message);
    }

    public static function notReversible(string $message): self
    {
        return new self(self::NOT_REVERSIBLE, $message);
    }

    public static function balanceNotZero(): self
    {
        return new self(self::BALANCE_NOT_ZERO, 'A folio can be closed only when its balance is zero.');
    }

    public static function currency(): self
    {
        return new self(self::CURRENCY, 'The posting is in another currency than the folio.');
    }
}
