<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\InventoryPurchasing;

use App\Modules\InventoryPurchasing\Domain\StockQuantity;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** FR-INV-009: the base-unit equivalent of a quantity in another unit. */
final class StockQuantityTest extends TestCase
{
    public function test_two_cartons_of_24_bottles_are_48_bottles(): void
    {
        $this->assertSame(48_000, StockQuantity::toBase(2_000, 24_000));
    }

    public function test_a_gram_of_a_kilogram_item_converts_exactly(): void
    {
        $this->assertSame(250, StockQuantity::toBase(250_000, 1));
    }

    public function test_rounding_is_half_away_from_zero(): void
    {
        $this->assertSame(2, StockQuantity::toBase(1_500, 1));
        $this->assertSame(-2, StockQuantity::toBase(-1_500, 1));
        $this->assertSame(1, StockQuantity::toBase(1_499, 1));
    }

    public function test_a_factor_out_of_range_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StockQuantity::toBase(1_000, 0);
    }

    public function test_a_huge_quantity_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StockQuantity::toBase(StockQuantity::MAX_MILLI + 1, 1_000);
    }

    public function test_a_quantity_is_read_with_a_dot_or_a_comma_and_at_most_three_decimals(): void
    {
        $this->assertSame(12_500, StockQuantity::parse('12.5'));
        $this->assertSame(12_500, StockQuantity::parse('12,5'));
        $this->assertSame(1, StockQuantity::parse('0.001'));
        $this->assertSame(5_000, StockQuantity::parse(' 5 '));
        $this->assertNull(StockQuantity::parse('1.2345'));
        $this->assertNull(StockQuantity::parse('abc'));
        $this->assertNull(StockQuantity::parse(''));
        $this->assertNull(StockQuantity::parse('-3'));
    }
}
