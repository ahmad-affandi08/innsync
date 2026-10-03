<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\HumanResource;

use App\Modules\HumanResource\Application\PayrollCalculator;
use App\Modules\HumanResource\Application\PayrollSettingsService;
use PHPUnit\Framework\TestCase;

/** FR-HR-031: the arithmetic of one person's pay, with the usual parameters. */
final class PayrollCalculatorTest extends TestCase
{
    private const ATT = ['scheduled' => 26, 'present' => 26, 'absent' => 0, 'late_minutes' => 0, 'overtime_first' => 0, 'overtime_next' => 0, 'unpaid_days' => 0];

    private const K1 = ['ptkp_status' => 'K1', 'has_npwp' => true, 'in_health' => true, 'in_employment' => true];

    /** @return list<array{code: string, name: string, kind: string, taxable: bool, social_base: bool, amount_minor: int}> */
    private static function earnings(): array
    {
        return [
            ['code' => 'GAJI', 'name' => 'Basic pay', 'kind' => 'basic', 'taxable' => true, 'social_base' => true, 'amount_minor' => 5_000_000],
            ['code' => 'TETAP', 'name' => 'Fixed allowance', 'kind' => 'fixed_allowance', 'taxable' => true, 'social_base' => true, 'amount_minor' => 500_000],
            ['code' => 'TIDAK', 'name' => 'Variable allowance', 'kind' => 'variable_allowance', 'taxable' => true, 'social_base' => false, 'amount_minor' => 260_000],
            ['code' => 'MAKAN', 'name' => 'Meal allowance', 'kind' => 'meal', 'taxable' => true, 'social_base' => false, 'amount_minor' => 25_000],
        ];
    }

    /** @return array<string, int> */
    private static function amounts(array $line): array
    {
        $out = [];

        foreach ($line['items'] as $i) {
            $out[$i['code']] = $i['amount_minor'];
        }

        return $out;
    }

    public function test_earnings_follow_their_kind_and_the_social_security_and_tax_come_out(): void
    {
        // 24 of 26 planned days present: the variable allowance is 24/26 of 260,000 and the meal allowance is 24 times 25,000.
        $line = PayrollCalculator::line(PayrollSettingsService::BASELINE, self::earnings(), [...self::ATT, 'present' => 24, 'absent' => 2], self::K1, []);
        $a = self::amounts($line);

        self::assertSame([5_000_000, 500_000, 240_000, 600_000], [$a['GAJI'], $a['TETAP'], $a['TIDAK'], $a['MAKAN']]);
        self::assertSame(6_340_000, $line['gross']);

        // Two days absent cost 2/25 of the wage of 5,500,000.
        self::assertSame(440_000, $a['POT-ABSEN']);

        // Social security on the 5,500,000 flagged as the wage: person 1% + 2% + 1%, employer 4% + 3.7% + 2% + 0.24% + 0.3%.
        self::assertSame([55_000, 110_000, 55_000], [$a['BPJS-KES'], $a['BPJS-JHT'], $a['BPJS-JP']]);
        self::assertSame(220_000, $line['employee_social']);
        self::assertSame(563_200, $line['employer_social']);
        self::assertSame(['kind' => 'employer', 'amount_minor' => 203_500], array_intersect_key(array_values(array_filter($line['items'], static fn (array $i): bool => $i['code'] === 'BPJS-JHT-ER'))[0], ['kind' => 1, 'amount_minor' => 1]));

        // Taxable income 5,900,000 plus the employer's health, accident and death cover (249,700); less the job cost (5%) and the person's JHT and JP, a year, less the threshold of K1.
        self::assertSame(5_900_000, $line['taxable']);
        $income = 5_900_000 + 249_700;
        $annual = ($income - intdiv($income * 500 + 5000, 10_000) - 165_000) * 12;
        $year = intdiv(intdiv($annual - 63_000_000, 1000) * 1000 * 500 + 5000, 10_000);
        self::assertSame(intdiv($year + 6, 12), $line['tax']);
        self::assertSame($line['gross'] - 440_000 - 220_000 - $line['tax'], $line['net']);
    }

    public function test_overtime_is_paid_by_the_hour_with_the_first_hour_dearer_and_lateness_is_deducted_per_minute(): void
    {
        $settings = [...PayrollSettingsService::BASELINE, 'late_minute_deduction_minor' => 500];
        // 1/173 of 5,500,000 an hour: 90 minutes in the first hours of days at x1.5 and 60 minutes after at x2.
        $line = PayrollCalculator::line($settings, self::earnings(), [...self::ATT, 'overtime_first' => 90, 'overtime_next' => 60, 'late_minutes' => 20], self::K1, []);
        $a = self::amounts($line);

        self::assertSame(intdiv(5_500_000 * (90 * 150 + 60 * 200), 173 * 60 * 100), $a['LEMBUR']);
        self::assertSame(10_000, $a['POT-TELAT']);
    }

    public function test_adjustments_count_as_earnings_or_deductions_and_a_person_without_a_tax_number_pays_the_surcharge(): void
    {
        $adjustments = [['label' => 'Missed allowance', 'amount_minor' => 150_000, 'taxable' => true], ['label' => 'Overpaid last month', 'amount_minor' => -50_000, 'taxable' => true]];
        $with = PayrollCalculator::line(PayrollSettingsService::BASELINE, self::earnings(), self::ATT, self::K1, $adjustments);
        $without = PayrollCalculator::line(PayrollSettingsService::BASELINE, self::earnings(), self::ATT, [...self::K1, 'has_npwp' => false], $adjustments);

        self::assertSame(150_000, $with['gross'] - PayrollCalculator::line(PayrollSettingsService::BASELINE, self::earnings(), self::ATT, self::K1, [])['gross']);
        self::assertSame(50_000, $with['other_deductions']);
        self::assertSame($with['tax'] + intdiv($with['tax'] * 2000 + 5000, 10_000), $without['tax']);
        self::assertGreaterThan(0, $with['tax']);
    }

    public function test_social_security_stops_at_the_ceilings_and_outside_the_schemes(): void
    {
        $rich = [['code' => 'GAJI', 'name' => 'Basic pay', 'kind' => 'basic', 'taxable' => true, 'social_base' => true, 'amount_minor' => 20_000_000]];
        $line = PayrollCalculator::line(PayrollSettingsService::BASELINE, $rich, self::ATT, self::K1, []);
        $a = self::amounts($line);

        self::assertSame([120_000, 400_000, 105_474], [$a['BPJS-KES'], $a['BPJS-JHT'], $a['BPJS-JP']], 'health to 12,000,000, pension to 10,547,400, old-age savings without a ceiling');

        $out = PayrollCalculator::line(PayrollSettingsService::BASELINE, $rich, self::ATT, [...self::K1, 'in_health' => false, 'in_employment' => false], []);
        self::assertSame([0, 0], [$out['employee_social'], $out['employer_social']]);
    }

    public function test_income_below_the_threshold_pays_no_tax(): void
    {
        $line = PayrollCalculator::line(PayrollSettingsService::BASELINE, [['code' => 'GAJI', 'name' => 'Basic pay', 'kind' => 'basic', 'taxable' => true, 'social_base' => true, 'amount_minor' => 3_000_000]], self::ATT, ['ptkp_status' => 'TK0', 'has_npwp' => true, 'in_health' => true, 'in_employment' => true], []);

        self::assertSame([0, 2_880_000], [$line['tax'], $line['net']]);
    }
}
