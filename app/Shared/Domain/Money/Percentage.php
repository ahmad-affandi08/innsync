<?php

declare(strict_types=1);

namespace App\Shared\Domain\Money;

/** A rate in basis points (1/100 of a percent): 10% is 1000, 2.5% is 250. Rates are exact integers, never floats. */
final readonly class Percentage
{
    public const DENOMINATOR = 10_000;

    private function __construct(public int $basisPoints)
    {
        if ($basisPoints < 0 || $basisPoints > 100_000) {
            throw MoneyError::invalid('A rate must be between 0% and 1000%.');
        }
    }

    public static function ofBasisPoints(int $basisPoints): self
    {
        return new self($basisPoints);
    }

    /** From text such as "10", "10.00" or "2.5" with at most two decimals; no locale, no float. */
    public static function parse(string $text): self
    {
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/D', $text, $match) !== 1) {
            throw MoneyError::invalid('A rate is a number with at most two decimals, such as 10 or 2.5.');
        }

        return new self(((int) $match[1]) * 100 + (int) str_pad($match[2] ?? '0', 2, '0'));
    }

    public function isZero(): bool
    {
        return $this->basisPoints === 0;
    }
}
