<?php

declare(strict_types=1);

namespace App\Shared\Domain\Money;

use DomainException;

/** A money rule was broken (mixed currencies, overflow, an impossible rate). Never swallowed: wrong money is worse than a failure. */
final class MoneyError extends DomainException
{
    public static function currencyMismatch(string $a, string $b): self
    {
        return new self("Cannot combine {$a} with {$b}.");
    }

    public static function overflow(): self
    {
        return new self('The amount is too large to calculate safely.');
    }

    public static function invalid(string $message): self
    {
        return new self($message);
    }
}
