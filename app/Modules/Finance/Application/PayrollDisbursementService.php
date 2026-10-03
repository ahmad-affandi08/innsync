<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Time\Clock;
use App\Shared\Domain\Tenancy\PropertyId;

/** Intake of approved payroll runs for Finance (the contract Human Resource uses). Verifying and paying them is the next step, by people with the right to do it. */
final readonly class PayrollDisbursementService implements PayrollDisbursements
{
    public function __construct(private PayrollDisbursementStore $store, private FinanceAccess $access, private AuditTrail $audit, private IdentifierGenerator $ids, private Clock $clock) {}

    public function receive(PropertyId $property, string $runId, string $number, string $period, int $employees, int $netMinor, int $taxMinor, int $employeeSocialMinor, int $employerSocialMinor, string $byUserId): void
    {
        $this->access->assertProperty($property);
        $runId = strtolower($runId);
        $by = strtolower($byUserId);
        $now = $this->clock->nowUtc();
        $existing = $this->store->byRun($property, $runId);
        $amounts = ['employees_count' => $employees, 'net_minor' => $netMinor, 'tax_minor' => $taxMinor, 'employee_social_minor' => $employeeSocialMinor, 'employer_social_minor' => $employerSocialMinor];

        if ($existing === null) {
            $id = $this->ids->next();
            $this->store->add($property, ['id' => $id, 'run_id' => $runId, 'number' => $number, 'period' => $period, ...$amounts, 'status' => 'awaiting', 'received_by' => $by], $now);
            $this->audit->record(new AuditEntry($property->toString(), $by, 'payroll_disbursement.received', 'payroll_disbursement', $id, null, ['run' => $number, ...$amounts]));

            return;
        }

        if ($existing['status'] !== 'withdrawn') {
            throw Refusal::stateConflict('Finance has this payroll run already.');
        }

        if (! $this->store->update($property, $existing['id'], (int) $existing['lock_version'], [...$amounts, 'status' => 'awaiting', 'verified_by' => null, 'verified_at' => null, 'received_by' => $by], $now)) {
            throw Refusal::stateConflict('This payroll run changed meanwhile.');
        }

        $this->audit->record(new AuditEntry($property->toString(), $by, 'payroll_disbursement.received_again', 'payroll_disbursement', $existing['id'], ['status' => 'withdrawn'], ['run' => $number, ...$amounts]));
    }

    public function withdraw(PropertyId $property, string $runId, string $byUserId): void
    {
        $this->access->assertProperty($property);
        $by = strtolower($byUserId);
        $row = $this->store->byRun($property, strtolower($runId));

        if ($row === null || $row['status'] === 'withdrawn') {
            return;
        }

        if ($row['status'] === 'paid') {
            throw Refusal::stateConflict('Finance paid this payroll run already; correct it with an adjustment in a later period.');
        }

        if (! $this->store->update($property, $row['id'], (int) $row['lock_version'], ['status' => 'withdrawn', 'verified_by' => null, 'verified_at' => null], $this->clock->nowUtc())) {
            throw Refusal::stateConflict('This payroll run changed meanwhile.');
        }

        $this->audit->record(new AuditEntry($property->toString(), $by, 'payroll_disbursement.withdrawn', 'payroll_disbursement', $row['id'], ['status' => $row['status']], ['status' => 'withdrawn', 'run' => $row['number']]));
    }

    public function statusOf(PropertyId $property, string $runId): ?string
    {
        $this->access->assertProperty($property);

        return $this->store->byRun($property, strtolower($runId))['status'] ?? null;
    }
}
