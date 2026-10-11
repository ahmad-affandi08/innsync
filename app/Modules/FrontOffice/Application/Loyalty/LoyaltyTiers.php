<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Loyalty;

/** The tiers of the programme: a guest reaches a tier by the points earned over all stays, and a tier earns extra points on later stays. */
final class LoyaltyTiers
{
    /**
     * The highest tier reached, or null below the first.
     *
     * @param  list<array{key: string, from_points: int, bonus_percent: int}>  $tiers
     * @return array{key: string, from_points: int, bonus_percent: int}|null
     */
    public static function of(int $earnedPoints, array $tiers): ?array
    {
        $reached = null;

        foreach ($tiers as $tier) {
            if ($earnedPoints >= $tier['from_points'] && ($reached === null || $tier['from_points'] >= $reached['from_points'])) {
                $reached = $tier;
            }
        }

        return $reached;
    }

    /** The points of a stay with the bonus of the tier the guest had before it, rounded down. @param list<array{key: string, from_points: int, bonus_percent: int}> $tiers */
    public static function withBonus(int $points, int $earnedBefore, array $tiers): int
    {
        $tier = self::of($earnedBefore, $tiers);

        return $points + ($tier === null ? 0 : intdiv($points * $tier['bonus_percent'], 100));
    }

    /**
     * Reads the tiers from the settings (a list of keys, thresholds and bonuses) and drops what is not one: a key of lower-case letters, a threshold above zero, a bonus of 0 to 200.
     *
     * @return list<array{key: string, from_points: int, bonus_percent: int}>
     */
    public static function clean(mixed $raw): array
    {
        $out = [];

        foreach (is_array($raw) ? $raw : [] as $tier) {
            if (! is_array($tier) || ! isset($tier['key'], $tier['from_points']) || preg_match('/^[a-z][a-z0-9_]{1,15}$/D', (string) $tier['key']) !== 1 || (int) $tier['from_points'] < 1) {
                continue;
            }

            $out[] = ['key' => (string) $tier['key'], 'from_points' => (int) $tier['from_points'], 'bonus_percent' => max(0, min(200, (int) ($tier['bonus_percent'] ?? 0)))];
        }

        usort($out, static fn (array $a, array $b): int => $a['from_points'] <=> $b['from_points']);

        return $out;
    }
}
