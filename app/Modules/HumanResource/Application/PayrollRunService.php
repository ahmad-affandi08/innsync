<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Modules\Finance\Application\PayrollDisbursements;
use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Approval\ApprovalGate;
use App\Shared\Application\Approval\ApprovalRequestInput;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The payroll of a month (FR-HR-031, -037, -038): draft, calculated, reviewed, approved, paid, locked. Calculating works out every person's pay from what they earn, the days they were there, overtime that was
 * approved, unpaid leave and the adjustments waiting, with the parameters in force (kept with the run). Approving needs the chain the owner configured (subject `hr.payroll-run`, mandatory, the band is the net pay); the person
 * who asked takes the decision once it is made, and the run goes to Finance to be verified and paid. From then the lines cannot change. An approved run that was not paid can be reopened by the owner with a reason (what
 * it was is kept as a revision and Finance gets it back); after it was paid a mistake is corrected by an adjustment that the next period takes in.
 */
final readonly class PayrollRunService
{
    public const SUBJECT = 'hr.payroll-run';

    public function __construct(
        private PayrollRunStore $store,
        private PayrollStore $pay,
        private PayrollSettingsService $settings,
        private EmployeeStore $employees,
        private AttendanceService $attendance,
        private LeaveStore $leave,
        private ApprovalGate $approvals,
        private PayrollDisbursements $disbursements,
        private HrAccess $access,
        private BusinessDateProvider $businessDate,
        private PropertyCurrencyReader $currency,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId, ?string $runId): array
    {
        $this->access->require($property, $actorId, HrAccess::PAYROLL, 'This person may not see the payroll.');
        $today = $this->businessDate->current($property)->toString();
        $runs = $this->store->runs($property);
        $selected = $runId === null || $runId === '' ? ($runs[0] ?? null) : ($this->store->run($property, strtolower($runId)) ?? throw Refusal::notFound('Payroll run not found.'));
        $reopen = $this->access->may($property, $actorId, HrAccess::PAYROLL_REOPEN);
        $without = [];

        if ($selected !== null && in_array($selected['status'], ['draft', 'calculated', 'reviewed'], true)) {
            $paid = array_unique(array_column($this->pay->items($property, null), 'employee_id'));

            foreach ($this->employees->employees($property, 'active') as $e) {
                if (! in_array($e['id'], $paid, true)) {
                    $without[] = ['id' => $e['id'], 'number' => $e['number'], 'name' => $e['full_name']];
                }
            }
        }

        return [
            'currency' => $this->currency->currencyOf($property), 'today' => $today, 'period' => substr($today, 0, 7), 'runs' => array_map(fn (array $r): array => $this->shape($property, $actorId, $r, $reopen), $runs),
            'run' => $selected === null ? null : [...$this->shape($property, $actorId, $selected, $reopen), 'lines' => array_map(self::line(...), $this->store->lines($property, $selected['id']))],
            'adjustments' => array_map(PayrollAdjustmentService::shape(...), $this->store->adjustments($property, $selected['id'] ?? null)), 'without_pay' => $without,
            'employees' => array_map(static fn (array $e): array => ['id' => $e['id'], 'number' => $e['number'], 'name' => $e['full_name']], $this->employees->employees($property, 'active')),
        ];
    }

    /** @return array<string, mixed> */
    public function create(PropertyId $property, string $actorId, string $period): array
    {
        $this->access->require($property, $actorId, HrAccess::PAYROLL, 'This person may not run the payroll.');
        $today = $this->businessDate->current($property)->toString();

        if (preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/D', $period) !== 1 || $period.'-01' > $today) {
            throw Refusal::invalid('Choose a month that has begun.', ['period']);
        }

        $id = $this->ids->next();
        $actor = strtolower($actorId);
        $start = $period.'-01';
        $end = date('Y-m-t', strtotime($start));

        $this->transactions->run(function () use ($property, $actor, $id, $period, $start, $end): void {
            if (! $this->store->addRun($property, ['id' => $id, 'number' => 'PAY-'.$period, 'period' => $period, 'period_start' => $start, 'period_end' => $end, 'status' => 'draft', 'created_by' => $actor], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This month has a payroll run already.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'payroll_run.created', 'payroll_run', $id, null, ['period' => $period]));
        });

        return $this->overview($property, $actorId, $id);
    }

    /** Works out every person's pay again; allowed until the run is approved. @return array<string, mixed> */
    public function calculate(PropertyId $property, string $actorId, string $runId, int $lock): array
    {
        $this->access->require($property, $actorId, HrAccess::PAYROLL, 'This person may not run the payroll.');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $runId, $lock): void {
            $run = $this->store->run($property, strtolower($runId)) ?? throw Refusal::notFound('Payroll run not found.');

            if (! in_array($run['status'], ['draft', 'calculated', 'reviewed'], true)) {
                throw Refusal::stateConflict('This run was approved. Reopen it first, or correct it with an adjustment in a later period.');
            }

            if ($run['approval_id'] !== null) {
                throw Refusal::stateConflict('This run was sent for approval. Wait for the decision first.');
            }

            $now = $this->clock->nowUtc();
            $settings = $this->settings->current($property);
            $start = (string) $run['period_start'];
            $end = (string) $run['period_end'];
            $this->store->releaseAdjustments($property, $run['id'], $now);
            $lines = $this->lines($property, $run, $settings, $start, $end);
            $totals = ['employees_count' => count($lines), 'gross_minor' => 0, 'deductions_minor' => 0, 'net_minor' => 0, 'tax_minor' => 0, 'employee_social_minor' => 0, 'employer_social_minor' => 0];

            foreach ($lines as $l) {
                $totals['gross_minor'] += $l['gross_minor'];
                $totals['net_minor'] += $l['net_minor'];
                $totals['tax_minor'] += $l['tax_minor'];
                $totals['employee_social_minor'] += $l['employee_social_minor'];
                $totals['employer_social_minor'] += $l['employer_social_minor'];
                $totals['deductions_minor'] += $l['gross_minor'] - $l['net_minor'];
            }

            $this->store->replaceLines($property, $run['id'], array_map(static fn (array $l): array => array_diff_key($l, ['_adjustments' => 1]), $lines), $now);

            foreach ($lines as $l) {
                foreach ($l['_adjustments'] as $adjustmentId) {
                    $a = $this->store->adjustment($property, $adjustmentId);
                    $this->store->updateAdjustment($property, $adjustmentId, (int) $a['lock_version'], ['status' => 'applied', 'applied_run_id' => $run['id']], $now);
                }
            }

            if (! $this->store->updateRun($property, $run['id'], $lock, [...$totals, 'status' => 'calculated', 'settings_snapshot' => json_encode($settings, JSON_THROW_ON_ERROR), 'calculated_by' => $actor, 'calculated_at' => $now->format('Y-m-d H:i:s.u'), 'reviewed_by' => null, 'reviewed_at' => null], $now)) {
                throw Refusal::stateConflict('This payroll run changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'payroll_run.calculated', 'payroll_run', $run['id'], ['status' => $run['status']], ['status' => 'calculated', 'period' => $run['period'], ...$totals]));
        });

        return $this->overview($property, $actorId, $runId);
    }

    /** @return array<string, mixed> */
    public function review(PropertyId $property, string $actorId, string $runId, int $lock): array
    {
        $this->access->require($property, $actorId, HrAccess::PAYROLL, 'This person may not run the payroll.');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $runId, $lock): void {
            $run = $this->store->run($property, strtolower($runId)) ?? throw Refusal::notFound('Payroll run not found.');

            if ($run['status'] !== 'calculated' || (int) $run['employees_count'] === 0) {
                throw Refusal::stateConflict('Only a calculated run with people in it is reviewed.');
            }

            $now = $this->clock->nowUtc();

            if (! $this->store->updateRun($property, $run['id'], $lock, ['status' => 'reviewed', 'reviewed_by' => $actor, 'reviewed_at' => $now->format('Y-m-d H:i:s.u')], $now)) {
                throw Refusal::stateConflict('This payroll run changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'payroll_run.reviewed', 'payroll_run', $run['id'], ['status' => 'calculated'], ['status' => 'reviewed', 'period' => $run['period'], 'net_minor' => (int) $run['net_minor']]));
        });

        return $this->overview($property, $actorId, $runId);
    }

    /** First call asks the approvers; once they decided, the same person calls it again to take the decision. @return array<string, mixed> */
    public function approve(PropertyId $property, string $actorId, string $runId, int $lock): array
    {
        $this->access->require($property, $actorId, HrAccess::PAYROLL, 'This person may not run the payroll.');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $runId, $lock): void {
            $run = $this->store->run($property, strtolower($runId)) ?? throw Refusal::notFound('Payroll run not found.');

            if ($run['status'] !== 'reviewed') {
                throw Refusal::stateConflict('Only a reviewed run is approved.');
            }

            $now = $this->clock->nowUtc();
            $payload = $this->payload($run);

            if ($run['approval_id'] === null) {
                $this->approvals->requirementFor($property, self::SUBJECT, (int) $run['net_minor']);
                $approvalId = $this->approvals->request(new ApprovalRequestInput(
                    $property, self::SUBJECT, $run['id'], $actor, 'Payroll '.$run['period'], $payload, ['status' => 'reviewed'], (int) $run['net_minor'], $this->currency->currencyOf($property),
                ), IdempotencyKey::fromString('hr-payroll-'.$run['id'].'-'.$run['lock_version']))->id;

                if (! $this->store->updateRun($property, $run['id'], $lock, ['approval_id' => $approvalId, 'approval_by' => $actor], $now)) {
                    throw Refusal::stateConflict('This payroll run changed after you opened it. Reload it.');
                }

                $this->audit->record(new AuditEntry($property->toString(), $actor, 'payroll_run.approval_requested', 'payroll_run', $run['id'], null, ['period' => $run['period'], 'net_minor' => (int) $run['net_minor']], null, $approvalId));

                return;
            }

            if ($run['approval_by'] !== $actor) {
                throw Refusal::forbidden('The person who asked for the approval takes the decision.');
            }

            $view = $this->approvals->find($property, (string) $run['approval_id']) ?? throw Refusal::notFound('Approval not found.');

            if (in_array($view->status, ['rejected', 'cancelled', 'expired'], true)) {
                if (! $this->store->updateRun($property, $run['id'], $lock, ['approval_id' => null, 'approval_by' => null], $now)) {
                    throw Refusal::stateConflict('This payroll run changed after you opened it. Reload it.');
                }

                $this->audit->record(new AuditEntry($property->toString(), $actor, 'payroll_run.approval_refused', 'payroll_run', $run['id'], null, ['period' => $run['period'], 'status' => $view->status], null, $run['approval_id']));

                return;
            }

            if (! $view->isApproved() || $view->consumed) {
                return;
            }

            $this->approvals->consume($property, (string) $run['approval_id'], self::SUBJECT, $run['id'], $payload, $actor);

            if (! $this->store->updateRun($property, $run['id'], $lock, ['status' => 'approved', 'approved_at' => $now->format('Y-m-d H:i:s.u')], $now)) {
                throw Refusal::stateConflict('This payroll run changed after you opened it. Reload it.');
            }

            $this->disbursements->receive($property, $run['id'], $run['number'], $run['period'], (int) $run['employees_count'], (int) $run['net_minor'], (int) $run['tax_minor'], (int) $run['employee_social_minor'], (int) $run['employer_social_minor'], $actor);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'payroll_run.approved', 'payroll_run', $run['id'], ['status' => 'reviewed'], ['status' => 'approved', 'period' => $run['period'], 'net_minor' => (int) $run['net_minor']], null, $run['approval_id']));
            $this->outbox->publish(new OutboxEvent($property, 'hr.payroll.approved', $run['id'], 1, ['run_id' => $run['id'], 'period' => $run['period'], 'employees' => (int) $run['employees_count'], 'net_minor' => (int) $run['net_minor']]));
        });

        return $this->overview($property, $actorId, $runId);
    }

    /** Takes an approved run back before it was paid: what it was is kept, Finance gets it back, and the run is calculated again. @return array<string, mixed> */
    public function reopen(PropertyId $property, string $actorId, string $runId, string $reason, int $lock): array
    {
        $this->access->require($property, $actorId, HrAccess::PAYROLL_REOPEN, 'This person may not reopen a payroll run.');
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Give the reason in at most 200 characters.', ['reason']);
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $runId, $reason, $lock): void {
            $run = $this->store->run($property, strtolower($runId)) ?? throw Refusal::notFound('Payroll run not found.');

            if ($run['status'] !== 'approved') {
                throw Refusal::stateConflict($run['status'] === 'paid' || $run['status'] === 'locked' ? 'This run was paid. Correct it with an adjustment in a later period.' : 'Only an approved run is reopened.');
            }

            $now = $this->clock->nowUtc();
            $revision = (int) $run['revision'] + 1;
            $this->disbursements->withdraw($property, $run['id'], $actor);
            $this->store->addRevision($property, ['id' => $this->ids->next(), 'run_id' => $run['id'], 'revision' => $revision, 'snapshot' => ['run' => self::runSnapshot($run), 'lines' => array_map(self::line(...), $this->store->lines($property, $run['id']))], 'reason' => $reason, 'created_by' => $actor], $now);

            // The lines are frozen once approved, so the run goes back to calculated first and the trigger lets the next calculation replace them.
            if (! $this->store->updateRun($property, $run['id'], $lock, ['status' => 'calculated', 'approved_at' => null, 'approval_id' => null, 'approval_by' => null, 'reviewed_by' => null, 'reviewed_at' => null, 'revision' => $revision], $now)) {
                throw Refusal::stateConflict('This payroll run changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'payroll_run.reopened', 'payroll_run', $run['id'], ['status' => 'approved'], ['status' => 'calculated', 'period' => $run['period'], 'revision' => $revision], $reason, $run['approval_id']));
            $this->outbox->publish(new OutboxEvent($property, 'hr.payroll.reopened', $run['id'], 1, ['run_id' => $run['id'], 'period' => $run['period'], 'revision' => $revision]));
        });

        return $this->overview($property, $actorId, $runId);
    }

    /** A paid run is locked: from then on it is only ever corrected by an adjustment in a later period. @return array<string, mixed> */
    public function lock(PropertyId $property, string $actorId, string $runId, int $lock): array
    {
        $this->access->require($property, $actorId, HrAccess::PAYROLL, 'This person may not run the payroll.');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $runId, $lock): void {
            $run = $this->store->run($property, strtolower($runId)) ?? throw Refusal::notFound('Payroll run not found.');

            if ($run['status'] !== 'paid') {
                throw Refusal::stateConflict('Only a paid run is locked.');
            }

            $now = $this->clock->nowUtc();

            if (! $this->store->updateRun($property, $run['id'], $lock, ['status' => 'locked', 'locked_at' => $now->format('Y-m-d H:i:s.u')], $now)) {
                throw Refusal::stateConflict('This payroll run changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'payroll_run.locked', 'payroll_run', $run['id'], ['status' => 'paid'], ['status' => 'locked', 'period' => $run['period']]));
        });

        return $this->overview($property, $actorId, $runId);
    }

    /** A run that was never calculated is dropped. @return array<string, mixed> */
    public function discard(PropertyId $property, string $actorId, string $runId): array
    {
        $this->access->require($property, $actorId, HrAccess::PAYROLL, 'This person may not run the payroll.');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $runId): void {
            $run = $this->store->run($property, strtolower($runId)) ?? throw Refusal::notFound('Payroll run not found.');

            if ($run['status'] !== 'draft') {
                throw Refusal::stateConflict('Only a run that was not calculated is dropped.');
            }

            $this->store->deleteDraft($property, $run['id']);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'payroll_run.discarded', 'payroll_run', $run['id'], ['status' => 'draft'], null));
        });

        return $this->overview($property, $actorId, null);
    }

    /**
     * @param  array<string, mixed>  $run
     * @param  array<string, mixed>  $settings
     * @return list<array<string, mixed>>
     */
    private function lines(PropertyId $property, array $run, array $settings, string $start, string $end): array
    {
        $components = array_column($this->pay->components($property, true), null, 'id');
        $items = $this->pay->items($property, null);
        $profiles = array_column($this->pay->profiles($property), null, 'employee_id');
        $attendance = [];

        foreach ($this->attendance->periodSummary($property, $start, $end, null) as $a) {
            $attendance[$a['employee']['id']] = $a;
        }

        $unpaid = $this->leave->unpaidDaysBetween($property, $start, $end);
        $adjustments = [];

        foreach ($this->store->adjustments($property, null) as $a) {
            if ($a['status'] === 'open' && ($a['source_period'] === null || $a['source_period'] < $run['period'])) {
                $adjustments[$a['employee_id']][] = $a;
            }
        }

        $out = [];

        foreach ($this->employees->employees($property, null) as $e) {
            if ($e['status'] !== 'active' && ($e['offboarded_on'] === null || (string) $e['offboarded_on'] < $start)) {
                continue;
            }

            $inForce = PayrollBasisService::inForce(array_values(array_filter($items, static fn (array $i): bool => $i['employee_id'] === $e['id'])), $end);
            $earnings = [];

            foreach ($inForce as $componentId => $line) {
                $c = $components[$componentId] ?? null;

                if ($c !== null && $line['amount_minor'] > 0) {
                    $earnings[] = ['code' => $c['code'], 'name' => $c['name'], 'kind' => $c['kind'], 'taxable' => (bool) $c['taxable'], 'social_base' => (bool) $c['social_base'], 'amount_minor' => $line['amount_minor']];
                }
            }

            if ($earnings === []) {
                continue;
            }

            $a = $attendance[$e['id']] ?? null;
            $att = ['scheduled' => $a['scheduled'] ?? 0, 'present' => $a['present'] ?? 0, 'absent' => $a['absent'] ?? 0, 'late_minutes' => $a['late_minutes'] ?? 0, 'overtime_first' => $a['overtime_first_minutes'] ?? 0, 'overtime_next' => $a['overtime_next_minutes'] ?? 0, 'unpaid_days' => $unpaid[$e['id']] ?? 0];
            $p = $profiles[$e['id']] ?? null;
            $profile = ['ptkp_status' => $p['ptkp_status'] ?? 'TK0', 'has_npwp' => $p === null || (bool) $p['has_npwp'], 'in_health' => $p === null || (bool) $p['in_health'], 'in_employment' => $p === null || (bool) $p['in_employment']];
            $mine = $adjustments[$e['id']] ?? [];
            $r = PayrollCalculator::line($settings, $earnings, $att, $profile, array_map(static fn (array $x): array => ['label' => (string) $x['label'], 'amount_minor' => (int) $x['amount_minor'], 'taxable' => (bool) $x['taxable']], $mine));
            $warnings = array_values(array_filter([$p === null ? 'no_tax_profile' : null, $att['scheduled'] === 0 ? 'no_roster' : null, $r['net'] < 0 ? 'negative_net' : null]));

            $out[] = [
                'id' => $this->ids->next(), 'employee_id' => $e['id'], 'number' => $e['number'], 'full_name' => $e['full_name'], 'department' => $e['department'], 'position' => $e['position'], 'ptkp_status' => $profile['ptkp_status'],
                'scheduled_days' => $att['scheduled'], 'present_days' => $att['present'], 'absent_days' => $att['absent'], 'unpaid_leave_days' => $att['unpaid_days'], 'late_minutes' => $att['late_minutes'], 'overtime_minutes' => $att['overtime_first'] + $att['overtime_next'],
                'gross_minor' => $r['gross'], 'taxable_minor' => $r['taxable'], 'tax_minor' => $r['tax'], 'employee_social_minor' => $r['employee_social'], 'employer_social_minor' => $r['employer_social'], 'other_deductions_minor' => $r['other_deductions'], 'net_minor' => $r['net'],
                'items' => $r['items'], 'warnings' => $warnings, '_adjustments' => array_column($mine, 'id'),
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $run @return array<string, mixed> */
    private function payload(array $run): array
    {
        return ['run' => $run['number'], 'period' => $run['period'], 'employees' => (int) $run['employees_count'], 'net_minor' => (int) $run['net_minor'], 'revision' => (int) $run['revision']];
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    private static function runSnapshot(array $r): array
    {
        return ['number' => $r['number'], 'period' => $r['period'], 'status' => $r['status'], 'employees' => (int) $r['employees_count'], 'gross_minor' => (int) $r['gross_minor'], 'deductions_minor' => (int) $r['deductions_minor'], 'net_minor' => (int) $r['net_minor'],
            'tax_minor' => (int) $r['tax_minor'], 'employee_social_minor' => (int) $r['employee_social_minor'], 'employer_social_minor' => (int) $r['employer_social_minor'], 'approved_at' => $r['approved_at'], 'revision' => (int) $r['revision']];
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    private function shape(PropertyId $property, string $actorId, array $r, bool $mayReopen): array
    {
        $approval = $r['approval_id'] === null ? null : $this->approvals->find($property, (string) $r['approval_id']);
        $actor = strtolower($actorId);
        $status = $r['status'];

        return [
            'id' => $r['id'], 'number' => $r['number'], 'period' => $r['period'], 'status' => $status, 'employees' => (int) $r['employees_count'], 'gross_minor' => (int) $r['gross_minor'], 'deductions_minor' => (int) $r['deductions_minor'], 'net_minor' => (int) $r['net_minor'],
            'tax_minor' => (int) $r['tax_minor'], 'employee_social_minor' => (int) $r['employee_social_minor'], 'employer_social_minor' => (int) $r['employer_social_minor'], 'revision' => (int) $r['revision'], 'lock_version' => (int) $r['lock_version'],
            'approval' => $approval === null ? null : ['id' => $approval->id, 'status' => $approval->status, 'consumed' => $approval->consumed], 'paid_reference' => $r['paid_reference'],
            'may' => [
                'calculate' => in_array($status, ['draft', 'calculated', 'reviewed'], true) && $r['approval_id'] === null, 'review' => $status === 'calculated' && (int) $r['employees_count'] > 0, 'approve' => $status === 'reviewed' && ($r['approval_id'] === null || $r['approval_by'] === $actor),
                'reopen' => $status === 'approved' && $mayReopen, 'discard' => $status === 'draft', 'lock' => $status === 'paid',
            ],
        ];
    }

    /** @param array<string, mixed> $l @return array<string, mixed> */
    public static function line(array $l): array
    {
        return [
            'id' => $l['id'], 'employee' => ['id' => $l['employee_id'], 'number' => $l['number'], 'name' => $l['full_name'], 'department' => $l['department'], 'position' => $l['position']], 'ptkp_status' => $l['ptkp_status'],
            'scheduled_days' => (int) $l['scheduled_days'], 'present_days' => (int) $l['present_days'], 'absent_days' => (int) $l['absent_days'], 'unpaid_leave_days' => (int) $l['unpaid_leave_days'], 'late_minutes' => (int) $l['late_minutes'], 'overtime_minutes' => (int) $l['overtime_minutes'],
            'gross_minor' => (int) $l['gross_minor'], 'tax_minor' => (int) $l['tax_minor'], 'employee_social_minor' => (int) $l['employee_social_minor'], 'employer_social_minor' => (int) $l['employer_social_minor'], 'other_deductions_minor' => (int) $l['other_deductions_minor'], 'net_minor' => (int) $l['net_minor'],
            'items' => is_string($l['items']) ? json_decode($l['items'], true, 512, JSON_THROW_ON_ERROR) : $l['items'], 'warnings' => is_string($l['warnings']) ? json_decode($l['warnings'], true, 512, JSON_THROW_ON_ERROR) : $l['warnings'],
        ];
    }
}
