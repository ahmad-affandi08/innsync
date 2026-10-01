<?php

declare(strict_types=1);

namespace App\Shared\Domain\Money;

/**
 * An amount of money as integer minor units of an ISO 4217 currency (ADR-0006, BR-002). Never a float.
 * All arithmetic is exact or refuses (overflow); rounding is explicit and happens only where a rate is applied.
 */
final readonly class Money
{
    /** Largest magnitude accepted, so sums and rate multiplication cannot overflow a 64-bit integer. */
    public const MAX_MINOR = 100_000_000_000_000;

    private function __construct(public int $amountMinor, public string $currency)
    {
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw MoneyError::invalid('A currency is a three-letter ISO 4217 code.');
        }

        if (abs($amountMinor) > self::MAX_MINOR) {
            throw MoneyError::overflow();
        }
    }

    public static function ofMinor(int $amountMinor, string $currency): self
    {
        return new self($amountMinor, $currency);
    }

    public static function zero(string $currency): self
    {
        return new self(0, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSame($other);

        return new self($this->amountMinor + $other->amountMinor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSame($other);

        return new self($this->amountMinor - $other->amountMinor, $this->currency);
    }

    public function negate(): self
    {
        return new self(-$this->amountMinor, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amountMinor === 0;
    }

    public function isNegative(): bool
    {
        return $this->amountMinor < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amountMinor === $other->amountMinor;
    }

    /** @return -1|0|1 */
    public function compare(self $other): int
    {
        $this->assertSame($other);

        return $this->amountMinor <=> $other->amountMinor;
    }

    /** Exact whole-number multiple, for example nights times a nightly rate. */
    public function times(int $quantity): self
    {
        if ($quantity !== 0 && abs($this->amountMinor) > intdiv(self::MAX_MINOR, abs($quantity))) {
            throw MoneyError::overflow();
        }

        return new self($this->amountMinor * $quantity, $this->currency);
    }

    /** The share of this amount at a rate, rounded by the rule. Half away from zero is symmetric for corrections. */
    public function percent(Percentage $rate, RoundingRule $rounding): self
    {
        return new self($rounding->applyToFraction($this->amountMinor, $rate->basisPoints, Percentage::DENOMINATOR), $this->currency);
    }

    /**
     * Splits this amount in proportion to the weights so the parts add up to exactly the whole: the largest-remainder
     * method, ties to the earlier part. Used for split bills, tips and service-charge pools.
     *
     * @param  list<int>  $weights  non-negative, at least one positive
     * @return list<self>
     */
    public function allocate(array $weights): array
    {
        $total = array_sum($weights);

        if ($weights === [] || $total <= 0 || min($weights) < 0) {
            throw MoneyError::invalid('Weights must be non-negative with at least one positive.');
        }

        $sign = $this->amountMinor < 0 ? -1 : 1;
        $abs = abs($this->amountMinor);
        $parts = [];
        $remainders = [];

        foreach ($weights as $index => $weight) {
            if ($weight !== 0 && $abs > intdiv(PHP_INT_MAX, $weight)) {
                throw MoneyError::overflow();
            }

            $parts[$index] = intdiv($abs * $weight, $total);
            $remainders[$index] = ($abs * $weight) % $total;
        }

        $left = $abs - array_sum($parts);
        $order = array_keys($remainders);
        usort($order, static fn (int $a, int $b): int => $remainders[$b] <=> $remainders[$a] ?: $a <=> $b);

        foreach (array_slice($order, 0, $left) as $index) {
            $parts[$index]++;
        }

        return array_map(fn (int $minor): self => new self($sign * $minor, $this->currency), $parts);
    }

    private function assertSame(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw MoneyError::currencyMismatch($this->currency, $other->currency);
        }
    }
}
