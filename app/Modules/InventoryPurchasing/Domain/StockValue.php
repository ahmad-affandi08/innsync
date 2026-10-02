<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Domain;

use InvalidArgumentException;

/**
 * Money arithmetic for stock without floats. Values are whole minor units; a cost is minor units per unit. `mulDiv` computes a*b/c exactly even
 * when a*b would not fit in 64 bits, and rounds half up (all operands are magnitudes; the caller applies the sign).
 */
final class StockValue
{
    /** Largest cost per unit accepted: 100 million in the property currency. */
    public const MAX_UNIT_COST_MINOR = 10_000_000_000;

    public static function mulDiv(int $a, int $b, int $c): int
    {
        if ($a < 0 || $b < 0 || $c < 1 || $c > 2_000_000_000_000_000_000) {
            throw new InvalidArgumentException('mulDiv takes magnitudes and a positive divisor.');
        }

        // a = whole*c + a0 with a0 < c, so a*b/c = whole*b + a0*b/c, and the second term is built bit by bit without overflow.
        $whole = intdiv($a, $c);
        $a0 = $a % $c;
        $q = 0;
        $r = 0;

        for ($bit = 62; $bit >= 0; $bit--) {
            $q *= 2;
            $r *= 2;

            if ($r >= $c) {
                $r -= $c;
                $q++;
            }

            if ((($b >> $bit) & 1) === 1) {
                $r += $a0;

                if ($r >= $c) {
                    $r -= $c;
                    $q++;
                }
            }
        }

        $q += $whole * $b;

        return $q + (2 * $r >= $c ? 1 : 0);
    }

    /** The value of `$unitQtyMilli` units (thousandths) at `$unitCostMinor` per unit. */
    public static function ofQuantity(int $unitQtyMilli, int $unitCostMinor): int
    {
        return self::mulDiv(abs($unitQtyMilli), $unitCostMinor, 1000);
    }
}
