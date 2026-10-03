<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Modules\Property\Application\Ports\PropertyProfileReader;
use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Export\CsvWriter;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The electronic payslip (FR-HR-034) and the payroll file (FR-HR-035). A person sees their own payslip once the run was paid; the people who run the payroll see any payslip of a run that has lines, and take a CSV of an
 * approved run for a spreadsheet or the software of an outside payroll provider. Every payslip opened for someone else and every file taken is written to the audit trail.
 */
final readonly class PayslipService
{
    public function __construct(
        private PayrollRunStore $store,
        private EmployeeStore $employees,
        private HrAccess $access,
        private PropertyProfileReader $profile,
        private PropertyCurrencyReader $currency,
        private AuditTrail $audit,
    ) {}

    /** The person's own payslips of the runs that were paid. @return array<string, mixed> */
    public function mine(PropertyId $property, string $actorId): array
    {
        $this->access->assertProperty($property);
        $employeeId = $this->employees->employeeOfUser($property, strtolower($actorId));

        if ($employeeId === null) {
            return ['linked' => false, 'currency' => $this->currency->currencyOf($property), 'slips' => []];
        }

        return ['linked' => true, 'currency' => $this->currency->currencyOf($property), 'slips' => array_map(static fn (array $l): array => ['run_id' => $l['run_id'], 'period' => $l['period'], 'number' => $l['run_number'], 'gross_minor' => (int) $l['gross_minor'], 'net_minor' => (int) $l['net_minor']], $this->store->linesOfEmployee($property, $employeeId, ['paid', 'locked']))];
    }

    /** @return array<string, mixed> */
    public function ownSlip(PropertyId $property, string $actorId, string $runId): array
    {
        $this->access->assertProperty($property);
        $employeeId = $this->employees->employeeOfUser($property, strtolower($actorId)) ?? throw Refusal::notFound('Payslip not found.');
        $run = $this->store->run($property, strtolower($runId));
        $line = $run === null || ! in_array($run['status'], ['paid', 'locked'], true) ? null : $this->store->lineOf($property, $run['id'], $employeeId);

        if ($run === null || $line === null) {
            throw Refusal::notFound('Payslip not found.');
        }

        return $this->slip($property, $run, $line);
    }

    /** @return array<string, mixed> */
    public function slipOf(PropertyId $property, string $actorId, string $runId, string $employeeId): array
    {
        $this->access->require($property, $actorId, HrAccess::PAYROLL, 'This person may not see the payslips of others.');
        $run = $this->store->run($property, strtolower($runId)) ?? throw Refusal::notFound('Payroll run not found.');
        $line = $this->store->lineOf($property, $run['id'], strtolower($employeeId)) ?? throw Refusal::notFound('Payslip not found.');
        $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'payslip.viewed', 'payroll_run', $run['id'], null, ['employee' => $line['number'], 'period' => $run['period']]));

        return $this->slip($property, $run, $line);
    }

    /** @return array{filename: string, contents: string} */
    public function export(PropertyId $property, string $actorId, string $runId): array
    {
        $this->access->require($property, $actorId, HrAccess::PAYROLL, 'This person may not take the payroll file.');
        $run = $this->store->run($property, strtolower($runId)) ?? throw Refusal::notFound('Payroll run not found.');

        if (! in_array($run['status'], ['approved', 'paid', 'locked'], true)) {
            throw Refusal::stateConflict('The file is taken of a run that was approved.');
        }

        $money = static fn (int $minor): string => ($minor < 0 ? '-' : '').intdiv(abs($minor), 100).'.'.str_pad((string) (abs($minor) % 100), 2, '0', STR_PAD_LEFT);
        $rows = array_map(static fn (array $l): array => [
            $l['number'], $l['full_name'], $l['department'], $l['position'], $l['ptkp_status'], (int) $l['scheduled_days'], (int) $l['present_days'], (int) $l['absent_days'], (int) $l['overtime_minutes'],
            $money((int) $l['gross_minor']), $money((int) $l['employee_social_minor']), $money((int) $l['tax_minor']), $money((int) $l['other_deductions_minor']), $money((int) $l['net_minor']), $money((int) $l['employer_social_minor']),
        ], $this->store->lines($property, $run['id']));
        $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'payroll_run.exported', 'payroll_run', $run['id'], null, ['period' => $run['period'], 'rows' => count($rows)]));

        return ['filename' => 'payroll-'.$run['period'].'.csv', 'contents' => CsvWriter::build(['employee_number', 'name', 'department', 'position', 'tax_status', 'days_planned', 'days_present', 'days_absent', 'overtime_minutes', 'gross', 'social_security_employee', 'income_tax', 'other_deductions', 'net_pay', 'social_security_employer'], $rows)];
    }

    /** @param array<string, mixed> $run @param array<string, mixed> $line @return array<string, mixed> */
    private function slip(PropertyId $property, array $run, array $line): array
    {
        $line = PayrollRunService::line($line);

        return [
            'property' => $this->profile->nameOf($property) ?? '', 'currency' => $this->currency->currencyOf($property), 'run' => ['id' => $run['id'], 'number' => $run['number'], 'period' => $run['period'], 'status' => $run['status']],
            'employee' => $line['employee'], 'line' => $line,
        ];
    }
}
