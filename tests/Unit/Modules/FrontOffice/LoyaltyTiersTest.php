<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\FrontOffice;

use App\Modules\FrontOffice\Application\Loyalty\LoyaltyTiers;
use PHPUnit\Framework\TestCase;

final class LoyaltyTiersTest extends TestCase
{
    private const TIERS = [['key' => 'silver', 'from_points' => 1000, 'bonus_percent' => 5], ['key' => 'gold', 'from_points' => 5000, 'bonus_percent' => 10]];

    public function test_the_highest_tier_reached_is_the_guests_tier(): void
    {
        self::assertNull(LoyaltyTiers::of(999, self::TIERS));
        self::assertSame('silver', LoyaltyTiers::of(1000, self::TIERS)['key']);
        self::assertSame('silver', LoyaltyTiers::of(4999, self::TIERS)['key']);
        self::assertSame('gold', LoyaltyTiers::of(50_000, self::TIERS)['key']);
        self::assertNull(LoyaltyTiers::of(10_000, []));
    }

    public function test_the_bonus_is_that_of_the_tier_before_the_stay_and_rounds_down(): void
    {
        self::assertSame(200, LoyaltyTiers::withBonus(200, 0, self::TIERS));
        self::assertSame(210, LoyaltyTiers::withBonus(200, 1000, self::TIERS));
        self::assertSame(220, LoyaltyTiers::withBonus(200, 5000, self::TIERS));
        self::assertSame(103, LoyaltyTiers::withBonus(99, 1000, self::TIERS), '99 + 4 (4.95 rounded down)');
    }

    public function test_the_settings_are_read_and_what_is_not_a_tier_is_dropped(): void
    {
        $tiers = LoyaltyTiers::clean([['key' => 'gold', 'from_points' => 5000, 'bonus_percent' => 500], ['key' => 'Bad Key', 'from_points' => 1], ['key' => 'zero', 'from_points' => 0], 'nonsense', ['key' => 'silver', 'from_points' => 1000]]);

        self::assertSame([['key' => 'silver', 'from_points' => 1000, 'bonus_percent' => 0], ['key' => 'gold', 'from_points' => 5000, 'bonus_percent' => 200]], $tiers);
        self::assertSame([], LoyaltyTiers::clean(null));
    }
}
