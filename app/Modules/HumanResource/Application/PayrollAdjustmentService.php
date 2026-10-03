<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Corrections for a later period (FR-HR-038). Nothing in a run that was approved is overwritten; an amount that was wrong (an overpayment, a missed allowance) is written here, plus or minus, with the run it corrects
 * and the reason, and the next run calculated for a later period takes it in as its own line. An adjustment is cancelled while still open, never deleted.
 */
final readonly class PayrollAdjustmentService
{
    public function __construct(
        private PayrollRunStore $store,
        private EmployeeStore $employees,
        private HrAccess $access,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function create(PropertyId $property, string $actorId, string $employeeId, int $amountMinor, string $label, bool $taxable, string $reason, ?string $sourceRunId): array
    {
        $this->access->require($property, $actorId, HrAccess::PAYROLL, 'This person may not run the payroll.');
        $label = trim($label);
        $reason = trim($reason);

        if ($amountMinor === 0 || abs($amountMinor) > 999_999_999_999) {
            throw Refusal::invalid('Give an amount, plus or minus, that is not 0.', ['amount_minor']);
        }

        if ($label === '' || mb_strlen($label) > 60 || $reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Give a label of at most 60 characters and a reason of at most 200.', ['label', 'reason']);
        }

        $actor = strtolower($actorId);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actor, $id, $employeeId, $amountMinor, $label, $taxable, $reason, $sourceRunId): void {
            $employee = $this->employees->employee($property, strtolower($employeeId)) ?? throw Refusal::notFound('Employee not found.');
            $source = $sourceRunId === null || $sourceRunId === '' ? null : ($this->store->run($property, strtolower($sourceRunId)) ?? throw Refusal::notFound('Payroll run not found.'));

            if ($source !== null && ! in_array($source['status'], ['approved', 'paid', 'locked'], true)) {
                throw Refusal::stateConflict('Only a run that was approved needs an adjustment; change the others by calculating them again.');
            }

            $this->store->addAdjustment($property, ['id' => $id, 'employee_id' => $employee['id'], 'amount_minor' => $amountMinor, 'taxable' => $taxable, 'label' => $label, 'reason' => $reason, 'source_run_id' => $source['id'] ?? null, 'status' => 'open', 'created_by' => $actor], $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'payroll_adjustment.created', 'payroll_adjustment', $id, null, ['employee' => $employee['number'], 'amount_minor' => $amountMinor, 'label' => $label, 'source' => $source['number'] ?? null], $reason));
        });

        return self::shape($this->store->adjustment($property, $id) ?? throw Refusal::notFound('Adjustment not found.'));
    }

    /** @return array<string, mixed> */
    public function cancel(PropertyId $property, string $actorId, string $id, int $lock): array
    {
        $this->access->require($property, $actorId, HrAccess::PAYROLL, 'This person may not run the payroll.');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $lock): void {
            $a = $this->store->adjustment($property, strtolower($id)) ?? throw Refusal::notFound('Adjustment not found.');

            if ($a['status'] !== 'open') {
                throw Refusal::stateConflict('Only an adjustment that no run took in is cancelled.');
            }

            if (! $this->store->updateAdjustment($property, $a['id'], $lock, ['status' => 'cancelled'], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This adjustment changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'payroll_adjustment.cancelled', 'payroll_adjustment', $a['id'], ['status' => 'open'], ['status' => 'cancelled', 'employee' => $a['number'], 'amount_minor' => (int) $a['amount_minor']]));
        });

        return self::shape($this->store->adjustment($property, strtolower($id)) ?? throw Refusal::notFound('Adjustment not found.'));
    }

    /** @param array<string, mixed> $a @return array<string, mixed> */
    public static function shape(array $a): array
    {
        return [
            'id' => $a['id'], 'employee' => ['id' => $a['employee_id'], 'number' => $a['number'], 'name' => $a['full_name']], 'amount_minor' => (int) $a['amount_minor'], 'taxable' => (bool) $a['taxable'], 'label' => $a['label'], 'reason' => $a['reason'],
            'source_period' => $a['source_period'], 'status' => $a['status'], 'lock_version' => (int) $a['lock_version'],
        ];
    }
}
