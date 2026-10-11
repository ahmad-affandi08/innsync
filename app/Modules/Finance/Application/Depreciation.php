<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Shared\Domain\Time\DateMath;

/**
 * Straight-line depreciation by whole months, with no access to anything. The cost less what the asset is worth at the end of its life is spread over its months, rounded down, and the last month takes what is
 * left so the total is exact. The first month is the month the asset was acquired, in full; the month an asset leaves is not depreciated.
 */
final class Depreciation
{
    /** The usual life in months of each category until the hotel sets one (tax groups of 4, 8, 16 and 20 years; a permanent building 20 years). */
    public const BASELINE_LIFE = ['building' => 240, 'furniture' => 48, 'equipment' => 96, 'vehicle' => 96, 'it' => 48, 'other' => 96];

    /** The month number (0-based) of a `YYYY-MM` period counted from the start month. */
    public static function index(string $startPeriod, string $period): int
    {
        return ((int) substr($period, 0, 4) - (int) substr($startPeriod, 0, 4)) * 12 + (int) substr($period, 5, 2) - (int) substr($startPeriod, 5, 2);
    }

    public static function period(string $date): string
    {
        return substr($date, 0, 7);
    }

    public static function lastDay(string $period): string
    {
        return DateMath::format('Y-m-t', 'first day of this month', $period.'-01');
    }

    public static function next(string $period): string
    {
        return substr(DateMath::format('Y-m-d', '+1 month', $period.'-01'), 0, 7);
    }

    /** What month number `$i` (0-based) depreciates. */
    public static function amountOf(int $cost, int $salvage, int $life, int $i): int
    {
        $total = $cost - $salvage;
        $each = intdiv($total, $life);

        return $i >= $life - 1 ? $total - $each * ($life - 1) : $each;
    }

    /**
     * The periods still to be depreciated up to and including `$upTo`, each with its amount and the accumulated after it. The months already booked (`$bookedCount`, with `$accumulated` between them) are
     * the first ones, in order; the month an asset left and every month after it are not depreciated.
     *
     * @return list<array{period: string, amount_minor: int, accumulated_after_minor: int}>
     */
    public static function due(int $cost, int $salvage, int $life, string $acquiredOn, ?string $disposedOn, int $bookedCount, int $accumulated, string $upTo): array
    {
        $start = self::period($acquiredOn);
        $last = $disposedOn === null ? $upTo : min($upTo, DateMath::format('Y-m', '-1 month', self::period($disposedOn).'-01'));
        $out = [];

        for ($i = $bookedCount; $i < $life; $i++) {
            $period = self::plus($start, $i);

            if ($period > $last) {
                break;
            }

            $amount = self::amountOf($cost, $salvage, $life, $i);
            $accumulated += $amount;
            $out[] = ['period' => $period, 'amount_minor' => $amount, 'accumulated_after_minor' => $accumulated];
        }

        return $out;
    }

    /** The period `$months` after `$period`. */
    public static function plus(string $period, int $months): string
    {
        return substr(DateMath::format('Y-m-d', ($months >= 0 ? '+' : '').$months.' months', $period.'-01'), 0, 7);
    }
}
