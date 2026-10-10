<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Export\CsvWriter;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Where each person is paid (FR-HR-031) and the bank transfer file of an approved payroll run. The account number is sealed at rest, shown only as its last four digits and never written to the audit trail; the file
 * is taken only of a run that was approved, by the people who run the payroll, and each file taken is audited. A person with pay to receive and no account stops the file (it lists who), so nobody is left out unseen.
 * The file is plain CSV (employee number, holder, bank, account number, amount, remark): the banks' own bulk formats differ, and the columns map onto any of them.
 */
final readonly class PayrollBankService
{
    public function __construct(
        private PayrollBankStore $banks,
        private PayrollRunStore $runs,
        private EmployeeStore $employees,
        private HrAccess $access,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private Clock $clock,
    ) {}

    /** @return array{bank_name: string, account_holder: string, account_last4: string, lock_version: int}|null */
    public function summaryOf(PropertyId $property, string $employeeId): ?array
    {
        return $this->banks->accounts($property)[$employeeId] ?? null;
    }

    /** @return array<string, array{bank_name: string, account_holder: string, account_last4: string, lock_version: int}> by employee, for the payroll basis screen */
    public function summaries(PropertyId $property, string $actorId): array
    {
        $this->access->require($property, $actorId, HrAccess::PAYROLL, 'This person may not see where people are paid.');

        return $this->banks->accounts($property);
    }

    /** @return array{bank_name: string, account_holder: string, account_last4: string, lock_version: int} */
    public function save(PropertyId $property, string $actorId, string $employeeId, string $bankName, string $holder, string $number, ?int $lock): array
    {
        $this->access->require($property, $actorId, HrAccess::PAYROLL, 'This person may not set where people are paid.');
        $bankName = trim($bankName);
        $holder = trim($holder);
        $number = preg_replace('/[\s.-]+/', '', $number) ?? '';

        if (mb_strlen($bankName) < 2 || mb_strlen($bankName) > 60) {
            throw Refusal::invalid('Give the name of the bank, at most 60 characters.', ['bank_name']);
        }

        if (mb_strlen($holder) < 2 || mb_strlen($holder) > 120) {
            throw Refusal::invalid('Give the name on the account, at most 120 characters.', ['account_holder']);
        }

        if (preg_match('/^\d{5,20}$/', $number) !== 1) {
            throw Refusal::invalid('The account number is 5 to 20 digits.', ['account_number']);
        }

        $actor = strtolower($actorId);
        $employeeId = strtolower($employeeId);

        $this->transactions->run(function () use ($property, $actor, $employeeId, $bankName, $holder, $number, $lock): void {
            $employee = $this->employees->employee($property, $employeeId) ?? throw Refusal::notFound('Employee not found.');
            $before = $this->banks->accounts($property)[$employeeId] ?? null;

            if (($before === null ? null : (int) $before['lock_version']) !== $lock || ! $this->banks->save($property, $employeeId, $bankName, $holder, $number, $lock, $actor, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('The bank account of this person changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry(
                $property->toString(), $actor, 'pay_bank_account.saved', 'hr_employee', $employee['id'],
                $before === null ? null : ['bank_name' => $before['bank_name'], 'account_last4' => $before['account_last4']],
                ['bank_name' => $bankName, 'account_last4' => substr($number, -4)],
            ));
        });

        return $this->banks->accounts($property)[$employeeId] ?? throw Refusal::notFound('Employee not found.');
    }

    /** The people of an approved run who are to be paid and have no bank account yet, so the screen can name them before the file is asked for. @return list<array{number: string, name: string}> */
    public function withoutAccount(PropertyId $property, string $actorId, ?string $runId): array
    {
        $this->access->require($property, $actorId, HrAccess::PAYROLL, 'This person may not see where people are paid.');
        $run = $runId === null || $runId === '' ? null : $this->runs->run($property, strtolower($runId));

        if ($run === null || ! in_array($run['status'], ['approved', 'paid', 'locked'], true)) {
            return [];
        }

        $lines = $this->paid($property, $run['id']);
        $has = $this->banks->numbers($property, array_map(static fn (array $l): string => (string) $l['employee_id'], $lines));

        return array_values(array_map(static fn (array $l): array => ['number' => (string) $l['number'], 'name' => (string) $l['full_name']], array_filter($lines, static fn (array $l): bool => ! isset($has[(string) $l['employee_id']]))));
    }

    /** @return list<array<string, mixed>> the lines with something to pay */
    private function paid(PropertyId $property, string $runId): array
    {
        return array_values(array_filter($this->runs->lines($property, $runId), static fn (array $l): bool => (int) $l['net_minor'] > 0));
    }

    /** @return array{filename: string, contents: string} */
    public function transferFile(PropertyId $property, string $actorId, string $runId): array
    {
        $this->access->require($property, $actorId, HrAccess::PAYROLL, 'This person may not take the bank transfer file.');
        $run = $this->runs->run($property, strtolower($runId)) ?? throw Refusal::notFound('Payroll run not found.');

        if (! in_array($run['status'], ['approved', 'paid', 'locked'], true)) {
            throw Refusal::stateConflict('The transfer file is taken of a run that was approved.');
        }

        $lines = $this->paid($property, $run['id']);
        $accounts = $this->banks->numbers($property, array_map(static fn (array $l): string => (string) $l['employee_id'], $lines));

        if (array_filter($lines, static fn (array $l): bool => ! isset($accounts[(string) $l['employee_id']])) !== []) {
            throw Refusal::stateConflict('Some people to be paid have no bank account yet. Set their accounts, then take the file.');
        }

        $total = 0;
        $rows = [];

        foreach ($lines as $l) {
            $a = $accounts[(string) $l['employee_id']];
            $net = (int) $l['net_minor'];
            $total += $net;
            $rows[] = [$l['number'], $a['account_holder'], $a['bank_name'], $a['account_number'], intdiv($net, 100).'.'.str_pad((string) ($net % 100), 2, '0', STR_PAD_LEFT), 'Gaji '.$run['period']];
        }

        $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'payroll_run.transfer_file', 'payroll_run', $run['id'], null, ['period' => $run['period'], 'rows' => count($rows), 'total_minor' => $total]));

        return ['filename' => 'payroll-transfer-'.$run['period'].'.csv', 'contents' => CsvWriter::build(['employee_number', 'account_holder', 'bank', 'account_number', 'amount', 'remark'], $rows)];
    }
}
