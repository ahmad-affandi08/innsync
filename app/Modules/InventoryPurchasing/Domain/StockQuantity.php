<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Domain;

use InvalidArgumentException;

/**
 * Quantities are whole thousandths of a unit (12,500 is 12.5), so no fraction is ever kept as a float. A conversion factor is the number of
 * base units one unit holds, also in thousandths (1 carton of 24 bottles is 24,000). The base equivalent is rounded half away from zero.
 */
final class StockQuantity
{
    /** Largest quantity accepted in one posting: one million units. */
    public const MAX_MILLI = 1_000_000_000;

    public const MAX_FACTOR_MILLI = 1_000_000_000;

    public static function toBase(int $unitQtyMilli, int $factorMilli): int
    {
        if ($factorMilli < 1 || $factorMilli > self::MAX_FACTOR_MILLI) {
            throw new InvalidArgumentException('A conversion factor is between 0.001 and 1,000,000.');
        }

        if (abs($unitQtyMilli) > self::MAX_MILLI) {
            throw new InvalidArgumentException('A quantity is at most one million units.');
        }

        $product = abs($unitQtyMilli) * $factorMilli;
        $base = intdiv($product + 500, 1000);

        return $unitQtyMilli < 0 ? -$base : $base;
    }

    /** Reads "12.5" or "12,5" as 12,500 thousandths; at most three decimals. */
    public static function parse(string $text): ?int
    {
        $text = trim(str_replace(',', '.', $text));

        if (preg_match('/^(\d{1,7})(?:\.(\d{1,3}))?$/', $text, $m) !== 1) {
            return null;
        }

        return ((int) $m[1]) * 1000 + (int) str_pad($m[2] ?? '', 3, '0');
    }
}
