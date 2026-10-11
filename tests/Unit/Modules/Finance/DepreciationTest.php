<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Finance;

use App\Modules\Finance\Application\Depreciation;
use PHPUnit\Framework\TestCase;

/** Straight-line depreciation by whole months: exact in total, the acquisition month in full, the disposal month and after not at all. */
final class DepreciationTest extends TestCase
{
    public function test_the_months_add_up_to_exactly_the_cost_less_the_value_at_the_end_of_the_life(): void
    {
        // 10,000,000 rupiah (in sen) less 1,000,000 over 7 months does not divide evenly: the last month takes the difference.
        $all = Depreciation::due(1_000_000_000, 100_000_000, 7, '2026-01-20', null, 0, 0, '2026-12');

        self::assertCount(7, $all);
        self::assertSame('2026-01', $all[0]['period'], 'the month of acquisition, in full');
        self::assertSame('2026-07', $all[6]['period']);
        self::assertSame(900_000_000, $all[6]['accumulated_after_minor']);
        self::assertSame(array_fill(0, 6, 128_571_428), array_slice(array_column($all, 'amount_minor'), 0, 6));
        self::assertSame(900_000_000 - 6 * 128_571_428, $all[6]['amount_minor']);
    }

    public function test_only_the_months_up_to_the_period_asked_and_not_the_ones_already_booked(): void
    {
        $first = Depreciation::due(1_200_000_000, 0, 12, '2026-03-05', null, 0, 0, '2026-05');
        self::assertSame(['2026-03', '2026-04', '2026-05'], array_column($first, 'period'));

        $more = Depreciation::due(1_200_000_000, 0, 12, '2026-03-05', null, 3, 300_000_000, '2026-07');
        self::assertSame(['2026-06', '2026-07'], array_column($more, 'period'));
        self::assertSame(500_000_000, $more[1]['accumulated_after_minor']);
        self::assertSame([], Depreciation::due(1_200_000_000, 0, 12, '2026-03-05', null, 12, 1_200_000_000, '2030-01'), 'nothing after the last month');
    }

    public function test_the_month_an_asset_leaves_is_not_depreciated(): void
    {
        $due = Depreciation::due(1_200_000_000, 0, 12, '2026-01-02', '2026-04-15', 0, 0, '2026-12');

        self::assertSame(['2026-01', '2026-02', '2026-03'], array_column($due, 'period'));
        self::assertSame([], Depreciation::due(1_200_000_000, 0, 12, '2026-04-02', '2026-04-15', 0, 0, '2026-12'), 'left in the month it was acquired');
    }

    public function test_period_helpers(): void
    {
        self::assertSame('2026-02-28', Depreciation::lastDay('2026-02'));
        self::assertSame('2027-01', Depreciation::next('2026-12'));
        self::assertSame(14, Depreciation::index('2026-01', '2027-03'));
        self::assertSame('2028-02', Depreciation::plus('2026-12', 14));
        self::assertSame('2026-09', Depreciation::plus('2026-10', -1));
        self::assertSame('2025-12', Depreciation::plus('2026-01', -1));
    }
}
