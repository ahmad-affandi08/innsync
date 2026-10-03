<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

/**
 * Shares an amount among the staff by points and by how much of the planned time each person was there (FR-HR-032). A person's weight is the points of their position times the whole percent of their planned days they were
 * present; each share is the amount times the weight over the total weight, rounded down, and what the rounding leaves is the residue, kept with the reserve. With no weight at all nothing is shared.
 */
final class ServiceChargeCalculator
{
    /**
     * @param  list<array{employee_id: string, points_x100: int, scheduled: int, present: int}>  $people
     * @return array{lines: list<array{employee_id: string, attendance_bp: int, weight: int, share_minor: int}>, distributed: int, residue: int}
     */
    public static function shares(int $amountMinor, array $people): array
    {
        $lines = [];
        $total = 0;

        foreach ($people as $p) {
            $bp = $p['scheduled'] === 0 ? 0 : intdiv(min($p['present'], $p['scheduled']) * 10_000, $p['scheduled']);
            $weight = $p['points_x100'] * intdiv($bp, 100);
            $total += $weight;
            $lines[] = ['employee_id' => $p['employee_id'], 'attendance_bp' => $bp, 'weight' => $weight, 'share_minor' => 0];
        }

        if ($total === 0 || $amountMinor <= 0) {
            return ['lines' => $lines, 'distributed' => 0, 'residue' => max(0, $amountMinor)];
        }

        $whole = intdiv($amountMinor, $total);
        $rest = $amountMinor % $total;
        $distributed = 0;

        foreach ($lines as &$l) {
            // amount*weight/total without overflowing: the whole part and the part of the rest.
            $l['share_minor'] = $whole * $l['weight'] + intdiv($rest * $l['weight'], $total);
            $distributed += $l['share_minor'];
        }

        unset($l);

        return ['lines' => $lines, 'distributed' => $distributed, 'residue' => $amountMinor - $distributed];
    }
}
