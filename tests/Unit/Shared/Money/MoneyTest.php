<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Money;

use App\Shared\Domain\Money\ChargeScheme;
use App\Shared\Domain\Money\Money;
use App\Shared\Domain\Money\MoneyError;
use App\Shared\Domain\Money\Percentage;
use App\Shared\Domain\Money\RoundingMode;
use App\Shared\Domain\Money\RoundingRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    private function idr(int $minor): Money
    {
        return Money::ofMinor($minor, 'IDR');
    }

    public function test_arithmetic_is_exact_and_refuses_mixed_currencies(): void
    {
        self::assertSame(150_000, $this->idr(100_000)->add($this->idr(50_000))->amountMinor);
        self::assertSame(-50_000, $this->idr(0)->subtract($this->idr(50_000))->amountMinor);
        self::assertSame(300_000, $this->idr(100_000)->times(3)->amountMinor);
        self::assertTrue($this->idr(5)->equals($this->idr(5)));
        self::assertFalse($this->idr(5)->equals(Money::ofMinor(5, 'USD')));

        $this->expectException(MoneyError::class);
        $this->idr(1)->add(Money::ofMinor(1, 'USD'));
    }

    public function test_currency_codes_and_magnitudes_are_validated(): void
    {
        foreach (['idr', 'ID', 'IDRR', '', 'I D'] as $bad) {
            try {
                Money::ofMinor(1, $bad);
                self::fail("{$bad} accepted");
            } catch (MoneyError) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(MoneyError::class);
        Money::ofMinor(Money::MAX_MINOR + 1, 'IDR');
    }

    public function test_multiplication_that_could_overflow_is_refused(): void
    {
        $this->expectException(MoneyError::class);
        $this->idr(Money::MAX_MINOR)->times(2);
    }

    /** @return iterable<string, array{string, int}> */
    public static function percentages(): iterable
    {
        yield '10' => ['10', 1000];
        yield '10.00' => ['10.00', 1000];
        yield '2.5' => ['2.5', 250];
        yield '0' => ['0', 0];
        yield '8.25' => ['8.25', 825];
        yield '100' => ['100', 10000];
    }

    #[DataProvider('percentages')]
    public function test_rates_parse_without_floats(string $text, int $bp): void
    {
        self::assertSame($bp, Percentage::parse($text)->basisPoints);
    }

    public function test_bad_rates_are_refused(): void
    {
        foreach (['', '-1', '10.555', '1e2', '10%', ' 10', '10 ', '1001', '.5', '10,5'] as $bad) {
            try {
                Percentage::parse($bad);
                self::fail("{$bad} accepted");
            } catch (MoneyError) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return iterable<string, array{RoundingMode, int, int, int}> */
    public static function rounding(): iterable
    {
        // [mode, amount minor, rate bp, expected] with increment 100 (whole rupiah)
        yield 'half up exact' => [RoundingMode::HalfUp, 1_000_000, 1000, 100_000];
        yield 'half up below half' => [RoundingMode::HalfUp, 10_490, 1000, 1_000];   // 1049 sen -> 10.49 units -> 10
        yield 'half up at half' => [RoundingMode::HalfUp, 10_500, 1000, 1_100];      // 1050 sen -> 10.5 units -> 11
        yield 'half up negative at half' => [RoundingMode::HalfUp, -10_500, 1000, -1_100];
        yield 'half even at half goes to even down' => [RoundingMode::HalfEven, 10_500, 1000, 1_000]; // 10.5 -> 10
        yield 'half even at half goes to even up' => [RoundingMode::HalfEven, 11_500, 1000, 1_200];   // 11.5 -> 12
        yield 'down' => [RoundingMode::Down, 10_099, 1000, 1_000];
        yield 'up' => [RoundingMode::Up, 10_001, 1000, 1_100];
        yield 'up negative is away from zero' => [RoundingMode::Up, -10_001, 1000, -1_100];
        yield 'down negative is toward zero' => [RoundingMode::Down, -10_099, 1000, -1_000];
    }

    #[DataProvider('rounding')]
    public function test_rounding_modes_follow_their_definition(RoundingMode $mode, int $amount, int $bp, int $expected): void
    {
        $rule = RoundingRule::wholeUnits(2, $mode);

        self::assertSame($expected, $this->idr($amount)->percent(Percentage::ofBasisPoints($bp), $rule)->amountMinor);
    }

    public function test_a_reversal_is_the_exact_negative_in_every_mode(): void
    {
        foreach (RoundingMode::cases() as $mode) {
            $rule = RoundingRule::wholeUnits(2, $mode);

            foreach ([1, 49, 50, 51, 99, 100, 12_345, 987_654_321] as $amount) {
                $positive = $this->idr($amount)->percent(Percentage::parse('11'), $rule);
                $negative = $this->idr(-$amount)->percent(Percentage::parse('11'), $rule);
                self::assertSame(-$positive->amountMinor, $negative->amountMinor, "{$mode->value} {$amount}");
            }
        }
    }

    public function test_allocation_conserves_the_total_and_gives_the_remainder_fairly(): void
    {
        $parts = $this->idr(100)->allocate([1, 1, 1]);
        self::assertSame([34, 33, 33], array_map(static fn (Money $m): int => $m->amountMinor, $parts));

        $parts = $this->idr(-100)->allocate([1, 1, 1]);
        self::assertSame([-34, -33, -33], array_map(static fn (Money $m): int => $m->amountMinor, $parts));

        $parts = $this->idr(5)->allocate([0, 1, 0]);
        self::assertSame([0, 5, 0], array_map(static fn (Money $m): int => $m->amountMinor, $parts));

        foreach ([[3, 7, 11], [1, 1], [10, 0, 5, 5], [1, 2, 3, 4, 5, 6, 7]] as $weights) {
            foreach ([0, 1, 99, 1000, 123_457, 999_999_999] as $amount) {
                $sum = array_sum(array_map(static fn (Money $m): int => $m->amountMinor, $this->idr($amount)->allocate($weights)));
                self::assertSame($amount, $sum);
            }
        }
    }

    public function test_allocation_refuses_empty_negative_or_zero_weights(): void
    {
        foreach ([[], [0, 0], [-1, 2]] as $weights) {
            try {
                $this->idr(100)->allocate($weights);
                self::fail('accepted');
            } catch (MoneyError) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function plusPlus(): ChargeScheme
    {
        // 10% service charge, 10% regional tax charged on base plus service charge, prices exclusive, whole rupiah.
        return new ChargeScheme(Percentage::parse('10'), Percentage::parse('10'), true, false, RoundingRule::wholeUnits(2));
    }

    public function test_a_plus_plus_price_adds_service_charge_then_tax_on_the_sum(): void
    {
        $b = $this->plusPlus()->calculate($this->idr(100_000_000)); // Rp 1.000.000

        self::assertSame([100_000_000, 10_000_000, 11_000_000, 121_000_000], [$b->base->amountMinor, $b->serviceCharge->amountMinor, $b->tax->amountMinor, $b->total->amountMinor]);
    }

    public function test_tax_on_base_only_is_supported(): void
    {
        $scheme = new ChargeScheme(Percentage::parse('10'), Percentage::parse('10'), false, false, RoundingRule::wholeUnits(2));
        $b = $scheme->calculate($this->idr(100_000_000));

        self::assertSame([10_000_000, 10_000_000, 120_000_000], [$b->serviceCharge->amountMinor, $b->tax->amountMinor, $b->total->amountMinor]);
    }

    public function test_a_nett_price_is_split_so_that_the_parts_always_add_up_to_the_quoted_total(): void
    {
        $scheme = new ChargeScheme(Percentage::parse('10'), Percentage::parse('10'), true, true, RoundingRule::wholeUnits(2));
        $b = $scheme->calculate($this->idr(121_000_000));

        self::assertSame([100_000_000, 10_000_000, 11_000_000], [$b->base->amountMinor, $b->serviceCharge->amountMinor, $b->tax->amountMinor]);

        foreach ([100, 200, 300, 500, 600, 1000, 12_300, 99_999_900, 123_456_700, 987_654_300] as $total) {
            $parts = $scheme->calculate($this->idr($total));
            self::assertSame($total, $parts->total->amountMinor, "total {$total}");
            self::assertFalse($parts->base->isNegative() || $parts->serviceCharge->isNegative() || $parts->tax->isNegative());
        }
    }

    public function test_a_nett_price_that_is_not_a_whole_rounding_unit_is_refused_rather_than_invented(): void
    {
        $scheme = new ChargeScheme(Percentage::parse('10'), Percentage::parse('10'), true, true, RoundingRule::wholeUnits(2));

        $this->expectException(MoneyError::class);
        $scheme->calculate($this->idr(99));
    }

    public function test_the_breakdown_always_adds_up_for_many_amounts_rates_and_modes(): void
    {
        mt_srand(20261001);

        for ($i = 0; $i < 3000; $i++) {
            $scheme = new ChargeScheme(
                Percentage::ofBasisPoints(mt_rand(0, 2500)),
                Percentage::ofBasisPoints(mt_rand(0, 2500)),
                (bool) mt_rand(0, 1),
                (bool) mt_rand(0, 1),
                new RoundingRule([1, 100, 1000][mt_rand(0, 2)], RoundingMode::cases()[mt_rand(0, 3)]),
            );
            $increment = $scheme->rounding->incrementMinor;
            $quoted = $this->idr(mt_rand(0, intdiv(5_000_000_000, $increment)) * $increment);
            $b = $scheme->calculate($quoted);

            self::assertSame($b->base->amountMinor + $b->serviceCharge->amountMinor + $b->tax->amountMinor, $b->total->amountMinor);

            if ($scheme->pricesIncludeCharges) {
                self::assertSame($quoted->amountMinor, $b->total->amountMinor);
            } else {
                self::assertSame($quoted->amountMinor, $b->base->amountMinor);
            }

            // A correction mirrors the original exactly.
            $mirror = $scheme->calculate($quoted->negate());
            self::assertTrue($b->negate()->total->equals($mirror->total) || $scheme->pricesIncludeCharges, 'exclusive mirror');
        }
    }

    public function test_a_zero_price_has_no_charges_and_huge_amounts_fail_loudly_instead_of_wrapping(): void
    {
        $zero = $this->plusPlus()->calculate($this->idr(0));
        self::assertTrue($zero->total->isZero());

        $big = new ChargeScheme(Percentage::parse('10'), Percentage::parse('10'), true, true, RoundingRule::wholeUnits(2));
        $b = $big->calculate($this->idr(Money::MAX_MINOR));
        self::assertSame(Money::MAX_MINOR, $b->total->amountMinor);
    }
}
