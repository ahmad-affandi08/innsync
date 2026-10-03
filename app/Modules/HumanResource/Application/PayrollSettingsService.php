<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The parameters of the tax and the social security (FR-HR-036), kept by the owner and not in code, because they change by law: the shares of BPJS Kesehatan, Jaminan Hari Tua, Jaminan Pensiun, Jaminan Kecelakaan Kerja and
 * Jaminan Kematian that the person and the employer pay with the wage ceilings, the tax thresholds (PTKP) by status, the brackets of the income tax, the deduction for the cost of the job, the surcharge for no tax number, and how
 * overtime, absence and lateness are priced. Until the owner saves them the usual values apply; they are a starting point to confirm with the accountant, not advice.
 */
final readonly class PayrollSettingsService
{
    public const STATUSES = ['TK0', 'TK1', 'TK2', 'TK3', 'K0', 'K1', 'K2', 'K3'];

    /** @var array<string, mixed> */
    public const BASELINE = [
        'health_employee_bp' => 100, 'health_employer_bp' => 400, 'health_cap_minor' => 1_200_000_000,
        'jht_employee_bp' => 200, 'jht_employer_bp' => 370, 'jp_employee_bp' => 100, 'jp_employer_bp' => 200, 'jp_cap_minor' => 1_054_740_000,
        'jkk_employer_bp' => 24, 'jkm_employer_bp' => 30,
        'job_cost_bp' => 500, 'job_cost_cap_year_minor' => 600_000_000, 'no_npwp_surcharge_bp' => 2000,
        'ptkp' => ['TK0' => 5_400_000_000, 'TK1' => 5_850_000_000, 'TK2' => 6_300_000_000, 'TK3' => 6_750_000_000, 'K0' => 5_850_000_000, 'K1' => 6_300_000_000, 'K2' => 6_750_000_000, 'K3' => 7_200_000_000],
        'brackets' => [['upto_minor' => 6_000_000_000, 'rate_bp' => 500], ['upto_minor' => 25_000_000_000, 'rate_bp' => 1500], ['upto_minor' => 50_000_000_000, 'rate_bp' => 2500], ['upto_minor' => 500_000_000_000, 'rate_bp' => 3000], ['upto_minor' => null, 'rate_bp' => 3500]],
        'overtime_divisor' => 173, 'overtime_first_x100' => 150, 'overtime_next_x100' => 200, 'absence_divisor' => 25, 'late_minute_deduction_minor' => 0,
    ];

    private const BP = ['health_employee_bp', 'health_employer_bp', 'jht_employee_bp', 'jht_employer_bp', 'jp_employee_bp', 'jp_employer_bp', 'jkk_employer_bp', 'jkm_employer_bp', 'job_cost_bp'];

    private const MONEY = ['health_cap_minor', 'jp_cap_minor', 'job_cost_cap_year_minor', 'late_minute_deduction_minor'];

    public function __construct(
        private PayrollStore $store,
        private HrAccess $access,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function get(PropertyId $property, string $actorId): array
    {
        $this->access->require($property, $actorId, HrAccess::PAYROLL, 'This person may not see the parameters of the payroll.');

        return $this->current($property);
    }

    /** The parameters in force, for the payroll itself. @return array<string, mixed> */
    public function current(PropertyId $property): array
    {
        $this->access->assertProperty($property);
        $r = $this->store->settings($property);

        if ($r === null) {
            return [...self::BASELINE, 'is_baseline' => true, 'lock_version' => null];
        }

        $out = [];

        foreach (array_keys(self::BASELINE) as $key) {
            $out[$key] = in_array($key, ['ptkp', 'brackets'], true) ? json_decode((string) $r[$key], true, 512, JSON_THROW_ON_ERROR) : (int) $r[$key];
        }

        return [...$out, 'is_baseline' => false, 'lock_version' => (int) $r['lock_version']];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function save(PropertyId $property, string $actorId, array $input, ?int $lock): array
    {
        $this->access->require($property, $actorId, HrAccess::PAYROLL, 'This person may not set the parameters of the payroll.');
        $values = $this->clean($input);
        $before = $this->current($property);
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $values, $lock, $before): void {
            if ($before['lock_version'] !== $lock || ! $this->store->saveSettings($property, $values, $before['lock_version'], $actor, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('These parameters changed after you opened them. Reload them.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'payroll_settings.changed', 'payroll_settings', $property->toString(), array_diff_key($before, ['lock_version' => 1, 'is_baseline' => 1]), $values));
        });

        return $this->current($property);
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    private function clean(array $in): array
    {
        $out = [];

        foreach (self::BP as $key) {
            $out[$key] = self::whole($in[$key] ?? null, 0, 2000, $key, 'A share is from 0 to 20 percent (0 to 2000 basis points).');
        }

        foreach (self::MONEY as $key) {
            $out[$key] = self::whole($in[$key] ?? null, 0, 999_999_999_999, $key, 'Give an amount of 0 or more.');
        }

        $out['no_npwp_surcharge_bp'] = self::whole($in['no_npwp_surcharge_bp'] ?? null, 0, 10_000, 'no_npwp_surcharge_bp', 'The surcharge is from 0 to 100 percent (0 to 10000 basis points).');
        $out['overtime_divisor'] = self::whole($in['overtime_divisor'] ?? null, 1, 744, 'overtime_divisor', 'The hours that make a month of wage are from 1 to 744.');
        $out['overtime_first_x100'] = self::whole($in['overtime_first_x100'] ?? null, 100, 1000, 'overtime_first_x100', 'A multiple is from 1.00 to 10.00 (100 to 1000).');
        $out['overtime_next_x100'] = self::whole($in['overtime_next_x100'] ?? null, 100, 1000, 'overtime_next_x100', 'A multiple is from 1.00 to 10.00 (100 to 1000).');
        $out['absence_divisor'] = self::whole($in['absence_divisor'] ?? null, 1, 31, 'absence_divisor', 'The days that make a month of wage are from 1 to 31.');

        $ptkp = $in['ptkp'] ?? null;

        if (! is_array($ptkp) || array_diff(array_keys($ptkp), self::STATUSES) !== [] || array_diff(self::STATUSES, array_keys($ptkp)) !== []) {
            throw Refusal::invalid('Give the threshold of each status: '.implode(', ', self::STATUSES).'.', ['ptkp']);
        }

        foreach (self::STATUSES as $s) {
            $out['ptkp'][$s] = self::whole($ptkp[$s], 0, 999_999_999_999, 'ptkp', 'A threshold is 0 or more.');
        }

        $brackets = $in['brackets'] ?? null;

        if (! is_array($brackets) || ! array_is_list($brackets) || $brackets === [] || count($brackets) > 8) {
            throw Refusal::invalid('Give 1 to 8 brackets of the income tax.', ['brackets']);
        }

        $previous = 0;

        foreach ($brackets as $i => $b) {
            $last = $i === count($brackets) - 1;
            $upto = is_array($b) ? ($b['upto_minor'] ?? null) : null;

            if ($last !== ($upto === null)) {
                throw Refusal::invalid('Every bracket has a ceiling except the last, which has none.', ['brackets']);
            }

            $upto = $last ? null : self::whole($upto, 1, 999_999_999_999_999, 'brackets', 'A ceiling is above 0.');

            if ($upto !== null && $upto <= $previous) {
                throw Refusal::invalid('The ceilings of the brackets go up.', ['brackets']);
            }

            $previous = $upto ?? $previous;
            $out['brackets'][] = ['upto_minor' => $upto, 'rate_bp' => self::whole($b['rate_bp'] ?? null, 0, 10_000, 'brackets', 'A rate is from 0 to 100 percent.')];
        }

        return $out;
    }

    private static function whole(mixed $value, int $min, int $max, string $field, string $message): int
    {
        if (is_string($value) && preg_match('/^\d{1,18}$/D', $value) === 1) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < $min || $value > $max) {
            throw Refusal::invalid($message, [$field]);
        }

        return $value;
    }
}
