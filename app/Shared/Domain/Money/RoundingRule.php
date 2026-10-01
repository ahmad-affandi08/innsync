<?php

declare(strict_types=1);

namespace App\Shared\Domain\Money;

/**
 * How a computed amount is rounded (BR-002, PRD Q-13): to a multiple of `incrementMinor` minor units with a mode.
 * The increment is a property setting; for rupiah, whole-rupiah rounding is an increment of 100 sen.
 */
final readonly class RoundingRule
{
    public function __construct(public int $incrementMinor, public RoundingMode $mode)
    {
        if ($incrementMinor < 1 || $incrementMinor > 1_000_000) {
            throw MoneyError::invalid('The rounding increment is between 1 and 1,000,000 minor units.');
        }
    }

    /** Whole major units of a currency with the given number of minor digits (rupiah: 2 digits, increment 100). */
    public static function wholeUnits(int $minorDigits, RoundingMode $mode = RoundingMode::HalfUp): self
    {
        return new self(10 ** $minorDigits, $mode);
    }

    public static function exact(): self
    {
        return new self(1, RoundingMode::HalfUp);
    }

    /** Rounds `numerator / denominator` (an exact rational of minor units) to the increment. */
    public function applyToFraction(int $numeratorMinor, int $numerator, int $denominator): int
    {
        if ($denominator < 1) {
            throw MoneyError::invalid('A fraction needs a positive denominator.');
        }

        // Reduce first (1000/10000 becomes 1/10) so realistic amounts never come near a 64-bit overflow.
        $divisor = self::gcd($numerator, $denominator);
        $numerator = intdiv($numerator, $divisor);
        $denominator = intdiv($denominator, $divisor);

        if ($numeratorMinor !== 0 && $numerator !== 0 && abs($numeratorMinor) > intdiv(PHP_INT_MAX, $numerator)) {
            throw MoneyError::overflow();
        }

        // value = numeratorMinor * numerator / denominator, expressed in units of `increment`:
        // value / increment = (numeratorMinor * numerator) / (denominator * increment)
        $top = $numeratorMinor * $numerator;
        $bottom = $denominator * $this->incrementMinor;

        return $this->divide($top, $bottom) * $this->incrementMinor;
    }

    private static function gcd(int $a, int $b): int
    {
        $a = abs($a);

        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return max($a, 1);
    }

    /** Symmetric around zero: the result for -x is always the exact negative of the result for x. */
    private function divide(int $top, int $bottom): int
    {
        $sign = $top < 0 ? -1 : 1;
        $abs = abs($top);
        $quotient = intdiv($abs, $bottom);
        $remainder = $abs % $bottom;

        if ($remainder !== 0) {
            $twice = $remainder * 2;
            $quotient += match ($this->mode) {
                RoundingMode::HalfUp => $twice >= $bottom ? 1 : 0,
                RoundingMode::HalfEven => $twice > $bottom || ($twice === $bottom && $quotient % 2 === 1) ? 1 : 0,
                RoundingMode::Down => 0,
                RoundingMode::Up => 1,
            };
        }

        return $sign * $quotient;
    }
}
