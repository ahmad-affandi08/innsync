<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

/**
 * The arithmetic of one person's pay for one month (FR-HR-031), with no access to anything: the same inputs give the same lines. Whole minor units (sen) throughout, shares rounded half up.
 *
 * Earnings follow the kinds (whole, by days present, or for each day present); overtime is priced from the wage of basic pay and fixed allowance; absence, unpaid leave and lateness are deducted. The social security
 * comes out of the wage the owner flagged, up to the ceilings. The income tax is worked out on the month as if it lasted the year (the monthly income less the cost of the job and the person's own pension contributions,
 * times twelve, less the untaxed threshold of the status, through the brackets, divided by twelve), with the surcharge when there is no tax number; what the employer pays toward health, accident and death insurance
 * counts as income. It is the standard annualised method, not the monthly category tables of the tax office, and it is not tax advice.
 */
final class PayrollCalculator
{
    /**
     * @param  array<string, mixed>  $s  the parameters (see PayrollSettingsService::BASELINE)
     * @param  list<array{code: string, name: string, kind: string, taxable: bool, social_base: bool, amount_minor: int}>  $earnings
     * @param  array{scheduled: int, present: int, absent: int, late_minutes: int, overtime_first: int, overtime_next: int, unpaid_days: int}  $att
     * @param  array{ptkp_status: string, has_npwp: bool, in_health: bool, in_employment: bool}  $profile
     * @param  list<array{label: string, amount_minor: int, taxable: bool}>  $adjustments
     * @return array{items: list<array{code: string, label: string, kind: string, amount_minor: int}>, gross: int, taxable: int, tax: int, employee_social: int, employer_social: int, other_deductions: int, net: int}
     */
    public static function line(array $s, array $earnings, array $att, array $profile, array $adjustments): array
    {
        $items = [];
        $gross = 0;
        $taxable = 0;
        $socialBase = 0;
        $wage = 0;

        foreach ($earnings as $e) {
            $amount = match (PayComponentService::basis($e['kind'])) {
                'attendance' => $att['scheduled'] === 0 ? 0 : intdiv($e['amount_minor'] * min($att['present'], $att['scheduled']), $att['scheduled']),
                'per_day' => $e['amount_minor'] * $att['present'],
                default => $e['amount_minor'],
            };

            if (in_array($e['kind'], ['basic', 'fixed_allowance'], true)) {
                $wage += $e['amount_minor'];
            }

            if ($amount > 0) {
                $items[] = ['code' => $e['code'], 'label' => $e['name'], 'kind' => 'earning', 'amount_minor' => $amount];
                $gross += $amount;
                $taxable += $e['taxable'] ? $amount : 0;
                $socialBase += $e['social_base'] ? $amount : 0;
            }
        }

        $overtime = intdiv($wage * ($att['overtime_first'] * $s['overtime_first_x100'] + $att['overtime_next'] * $s['overtime_next_x100']), $s['overtime_divisor'] * 60 * 100);

        if ($overtime > 0) {
            $items[] = ['code' => 'LEMBUR', 'label' => 'Overtime', 'kind' => 'earning', 'amount_minor' => $overtime];
            $gross += $overtime;
            $taxable += $overtime;
        }

        $other = 0;

        foreach ($adjustments as $a) {
            if ($a['amount_minor'] > 0) {
                $items[] = ['code' => 'PENYESUAIAN', 'label' => $a['label'], 'kind' => 'earning', 'amount_minor' => $a['amount_minor']];
                $gross += $a['amount_minor'];
                $taxable += $a['taxable'] ? $a['amount_minor'] : 0;
            } else {
                $items[] = ['code' => 'PENYESUAIAN', 'label' => $a['label'], 'kind' => 'deduction', 'amount_minor' => -$a['amount_minor']];
                $other += -$a['amount_minor'];
                $taxable += $a['taxable'] ? $a['amount_minor'] : 0;
            }
        }

        foreach ([['POT-ABSEN', 'Absence', intdiv($att['absent'] * $wage, $s['absence_divisor'])], ['POT-CUTI', 'Unpaid leave', intdiv($att['unpaid_days'] * $wage, $s['absence_divisor'])], ['POT-TELAT', 'Lateness', $att['late_minutes'] * $s['late_minute_deduction_minor']]] as [$code, $label, $amount]) {
            if ($amount > 0) {
                $items[] = ['code' => $code, 'label' => $label, 'kind' => 'deduction', 'amount_minor' => $amount];
                $other += $amount;
                $taxable -= $amount;
            }
        }

        $health = min($socialBase, $s['health_cap_minor']);
        $pension = min($socialBase, $s['jp_cap_minor']);
        $inHealth = $profile['in_health'];
        $inWork = $profile['in_employment'];
        $employee = ['BPJS-KES' => $inHealth ? self::share($health, $s['health_employee_bp']) : 0, 'BPJS-JHT' => $inWork ? self::share($socialBase, $s['jht_employee_bp']) : 0, 'BPJS-JP' => $inWork ? self::share($pension, $s['jp_employee_bp']) : 0];
        $employer = ['BPJS-KES-ER' => $inHealth ? self::share($health, $s['health_employer_bp']) : 0, 'BPJS-JHT-ER' => $inWork ? self::share($socialBase, $s['jht_employer_bp']) : 0, 'BPJS-JP-ER' => $inWork ? self::share($pension, $s['jp_employer_bp']) : 0,
            'BPJS-JKK-ER' => $inWork ? self::share($socialBase, $s['jkk_employer_bp']) : 0, 'BPJS-JKM-ER' => $inWork ? self::share($socialBase, $s['jkm_employer_bp']) : 0];

        foreach ($employee as $code => $amount) {
            if ($amount > 0) {
                $items[] = ['code' => $code, 'label' => $code, 'kind' => 'deduction', 'amount_minor' => $amount];
            }
        }

        foreach ($employer as $code => $amount) {
            if ($amount > 0) {
                $items[] = ['code' => $code, 'label' => $code, 'kind' => 'employer', 'amount_minor' => $amount];
            }
        }

        $taxable = max(0, $taxable);
        $tax = self::tax($s, $profile, $taxable + $employer['BPJS-KES-ER'] + $employer['BPJS-JKK-ER'] + $employer['BPJS-JKM-ER'], $employee['BPJS-JHT'] + $employee['BPJS-JP']);

        if ($tax > 0) {
            $items[] = ['code' => 'PPH21', 'label' => 'Income tax', 'kind' => 'deduction', 'amount_minor' => $tax];
        }

        $employeeSocial = array_sum($employee);

        return ['items' => $items, 'gross' => $gross, 'taxable' => $taxable, 'tax' => $tax, 'employee_social' => $employeeSocial, 'employer_social' => array_sum($employer), 'other_deductions' => $other, 'net' => $gross - $other - $employeeSocial - $tax];
    }

    /** @param array<string, mixed> $s */
    private static function tax(array $s, array $profile, int $monthlyIncome, int $ownPension): int
    {
        $jobCost = min(self::share($monthlyIncome, $s['job_cost_bp']), intdiv($s['job_cost_cap_year_minor'], 12));
        $annual = max(0, $monthlyIncome - $jobCost - $ownPension) * 12;
        $taxableYear = max(0, $annual - ($s['ptkp'][$profile['ptkp_status']] ?? 0));
        // The taxable income is rounded down to a thousand rupiah (100,000 minor units).
        $taxableYear = intdiv($taxableYear, 100_000) * 100_000;
        $year = 0;
        $from = 0;

        foreach ($s['brackets'] as $b) {
            $top = $b['upto_minor'] ?? PHP_INT_MAX;

            if ($taxableYear > $from) {
                $year += self::share(min($taxableYear, $top) - $from, $b['rate_bp']);
            }

            $from = $top;
        }

        $month = intdiv($year + 6, 12);

        return $profile['has_npwp'] ? $month : $month + self::share($month, $s['no_npwp_surcharge_bp']);
    }

    private static function share(int $amount, int $basisPoints): int
    {
        return intdiv($amount * $basisPoints + 5000, 10_000);
    }
}
