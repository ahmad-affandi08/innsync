<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\InventoryPurchasing;

use App\Modules\InventoryPurchasing\Domain\StockValue;
use PHPUnit\Framework\TestCase;

final class StockValueTest extends TestCase
{
    public function test_it_divides_exactly_and_rounds_half_up(): void
    {
        $this->assertSame(0, StockValue::mulDiv(0, 5, 3));
        $this->assertSame(3, StockValue::mulDiv(1, 5, 2)); // 2.5 -> 3
        $this->assertSame(7, StockValue::mulDiv(10, 2, 3)); // 6.67 -> 7
        $this->assertSame(6, StockValue::mulDiv(10, 3, 5)); // 6
        $this->assertSame(1, StockValue::mulDiv(1, 1, 2)); // 0.5 -> 1
        $this->assertSame(0, StockValue::mulDiv(1, 1, 3)); // 0.33 -> 0
    }

    public function test_it_does_not_overflow_on_large_products(): void
    {
        // 1e15 * 1e15 / 1e15 would overflow a 64-bit product but is exactly 1e15.
        $this->assertSame(1_000_000_000_000_000, StockValue::mulDiv(1_000_000_000_000_000, 1_000_000_000_000_000, 1_000_000_000_000_000));
        // 3e15 * 2e15 / 6e15 = 1e15
        $this->assertSame(1_000_000_000_000_000, StockValue::mulDiv(3_000_000_000_000_000, 2_000_000_000_000_000, 6_000_000_000_000_000));
        $this->assertSame(10_000_000_000_000_000, StockValue::ofQuantity(1_000_000_000, StockValue::MAX_UNIT_COST_MINOR));
    }

    public function test_quantity_value_uses_thousandths(): void
    {
        $this->assertSame(12_500, StockValue::ofQuantity(2_500, 5_000)); // 2.5 units at 5,000
        $this->assertSame(1, StockValue::ofQuantity(1, 1_000)); // 0.001 unit at 1,000
        $this->assertSame(3, StockValue::ofQuantity(-3_000, 1)); // magnitude only
    }
}
