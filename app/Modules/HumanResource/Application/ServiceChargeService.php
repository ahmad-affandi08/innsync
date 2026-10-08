<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Modules\Finance\Application\ServiceChargeCollected;
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
use App\Shared\Domain\Time\DateMath;

/**
 * The share of the service charge of a month (FR-HR-032, -033). The simulation takes what Finance booked as service charge from the rooms and the outlets in the month, keeps the staff's share of it, sets aside the reserve
 * for damage and loss, and shares the rest by the points of each position and the part of their planned days (paid leave counts as being there) each person was present. It can be worked out again as often as needed while it is
 * a draft. Approving it needs the chain the owner configured (subject `hr.service-charge`, mandatory, normally the General Manager) and a month that is over; the person who asked takes the decision, and from then the figures
 * are locked and the payroll of that month pays each person's share as an earning.
 */
final readonly class ServiceChargeService
{
    public const SUBJECT = 'hr.service-charge';

    public function __construct(
        private ServiceChargeStore $store,
        private ServiceChargeSettingsService $settings,
        private ServiceChargeCollected $collected,
        private EmployeeStore $employees,
        private AttendanceService $attendance,
        private LeaveStore $leave,
        private ApprovalGate $approvals,
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
    public function overview(PropertyId $property, string $actorId, ?string $id): array
    {
        $this->access->require($property, $actorId, HrAccess::SERVICE_CHARGE, 'This person may not see the service charge.');
        $today = $this->businessDate->current($property)->toString();
        $all = $this->store->distributions($property);
        $selected = $id === null || $id === '' ? ($all[0] ?? null) : ($this->store->distribution($property, strtolower($id)) ?? throw Refusal::notFound('Distribution not found.'));
        $settings = $this->settings->current($property);
        $known = array_map(static fn (array $p): string => mb_strtolower($p['position']), $settings['points']);
        $unlisted = [];

        foreach ($this->employees->employees($property, 'active') as $e) {
            if (! in_array(mb_strtolower((string) $e['position']), $known, true)) {
                $unlisted[mb_strtolower((string) $e['position'])] = $e['position'];
            }
        }

        $actor = strtolower($actorId);

        return [
            'currency' => $this->currency->currencyOf($property), 'today' => $today, 'period' => substr($today, 0, 7), 'settings' => $settings, 'unlisted_positions' => array_values($unlisted),
            'distributions' => array_map(fn (array $d): array => $this->shape($property, $actor, $d, $today), $all),
            'selected' => $selected === null ? null : [...$this->shape($property, $actor, $selected, $today), 'lines' => array_map(static fn (array $l): array => [
                'id' => $l['id'], 'employee' => ['id' => $l['employee_id'], 'number' => $l['number'], 'name' => $l['full_name'], 'position' => $l['position']], 'points_x100' => (int) $l['points_x100'], 'scheduled_days' => (int) $l['scheduled_days'], 'present_days' => (int) $l['present_days'],
                'attendance_bp' => (int) $l['attendance_bp'], 'share_minor' => (int) $l['share_minor'], 'default_points' => (bool) $l['default_points'],
            ], $this->store->lines($property, $selected['id']))],
        ];
    }

    /** @return array<string, mixed> */
    public function create(PropertyId $property, string $actorId, string $period): array
    {
        $this->access->require($property, $actorId, HrAccess::SERVICE_CHARGE, 'This person may not share the service charge.');
        $today = $this->businessDate->current($property)->toString();

        if (preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/D', $period) !== 1 || $period.'-01' > $today) {
            throw Refusal::invalid('Choose a month that has begun.', ['period']);
        }

        $id = $this->ids->next();
        $actor = strtolower($actorId);
        $start = $period.'-01';

        $this->transactions->run(function () use ($property, $actor, $id, $period, $start): void {
            if (! $this->store->addDistribution($property, ['id' => $id, 'number' => 'SC-'.$period, 'period' => $period, 'period_start' => $start, 'period_end' => DateMath::format('Y-m-t', $start), 'status' => 'draft', 'created_by' => $actor], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This month has a distribution already.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'service_charge.created', 'service_charge_distribution', $id, null, ['period' => $period]));
        });

        return $this->overview($property, $actorId, $id);
    }

    /** The simulation: works out the shares again from what is booked and how people were there. @return array<string, mixed> */
    public function calculate(PropertyId $property, string $actorId, string $id, int $lock): array
    {
        $this->access->require($property, $actorId, HrAccess::SERVICE_CHARGE, 'This person may not share the service charge.');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $lock): void {
            $d = $this->store->distribution($property, strtolower($id)) ?? throw Refusal::notFound('Distribution not found.');

            if ($d['status'] !== 'draft' || $d['approval_id'] !== null) {
                throw Refusal::stateConflict($d['status'] === 'approved' ? 'This distribution was approved; its figures are locked.' : 'This distribution was sent for approval. Wait for the decision first.');
            }

            $start = (string) $d['period_start'];
            $end = (string) $d['period_end'];
            $settings = $this->settings->current($property);
            $booked = $this->collected->between($property, $start, $end);
            $pool = intdiv($booked['total_minor'] * $settings['staff_share_bp'], 10_000);
            $reserve = intdiv($pool * $settings['reserve_bp'], 10_000);
            $points = [];

            foreach ($settings['points'] as $p) {
                $points[mb_strtolower($p['position'])] = $p['points_x100'];
            }

            $attendance = [];

            foreach ($this->attendance->periodSummary($property, $start, $end, null) as $a) {
                $attendance[$a['employee']['id']] = $a;
            }

            $paidLeave = $this->leave->paidDaysBetween($property, $start, $end);
            $people = [];
            $meta = [];

            foreach ($this->employees->employees($property, null) as $e) {
                if ($e['status'] !== 'active' && ($e['offboarded_on'] === null || (string) $e['offboarded_on'] < $start)) {
                    continue;
                }

                $a = $attendance[$e['id']] ?? null;
                $leave = $paidLeave[$e['id']] ?? 0;
                $key = mb_strtolower((string) $e['position']);
                $x100 = $points[$key] ?? $settings['default_points_x100'];
                $people[] = ['employee_id' => $e['id'], 'points_x100' => $x100, 'scheduled' => ($a['scheduled'] ?? 0) + $leave, 'present' => ($a['present'] ?? 0) + $leave];
                $meta[$e['id']] = ['number' => $e['number'], 'full_name' => $e['full_name'], 'position' => $e['position'], 'points_x100' => $x100, 'scheduled' => ($a['scheduled'] ?? 0) + $leave, 'present' => ($a['present'] ?? 0) + $leave, 'default_points' => ! isset($points[$key])];
            }

            $result = ServiceChargeCalculator::shares($pool - $reserve, $people);
            $now = $this->clock->nowUtc();
            $this->store->replaceLines($property, $d['id'], array_map(fn (array $l): array => [
                'id' => $this->ids->next(), 'employee_id' => $l['employee_id'], 'number' => $meta[$l['employee_id']]['number'], 'full_name' => $meta[$l['employee_id']]['full_name'], 'position' => $meta[$l['employee_id']]['position'],
                'points_x100' => $meta[$l['employee_id']]['points_x100'], 'scheduled_days' => $meta[$l['employee_id']]['scheduled'], 'present_days' => min($meta[$l['employee_id']]['present'], $meta[$l['employee_id']]['scheduled']),
                'attendance_bp' => $l['attendance_bp'], 'weight' => $l['weight'], 'share_minor' => $l['share_minor'], 'default_points' => $meta[$l['employee_id']]['default_points'],
            ], $result['lines']), $now);

            $fields = [
                'days_booked' => $booked['days'], 'collected_minor' => $booked['total_minor'], 'pool_minor' => $pool, 'reserve_minor' => $reserve, 'distributed_minor' => $result['distributed'], 'residue_minor' => $result['residue'],
                'staff_share_bp' => $settings['staff_share_bp'], 'reserve_bp' => $settings['reserve_bp'], 'sources' => json_encode($booked['by_outlet'], JSON_THROW_ON_ERROR), 'calculated_by' => $actor,
            ];

            if (! $this->store->updateDistribution($property, $d['id'], $lock, $fields, $now)) {
                throw Refusal::stateConflict('This distribution changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'service_charge.calculated', 'service_charge_distribution', $d['id'], null, ['period' => $d['period'], 'collected_minor' => $booked['total_minor'], 'distributed_minor' => $result['distributed'], 'people' => count($people)]));
        });

        return $this->overview($property, $actorId, $id);
    }

    /** First call asks the approvers; once they decided, the same person calls it again to take the decision and lock the figures. @return array<string, mixed> */
    public function approve(PropertyId $property, string $actorId, string $id, int $lock): array
    {
        $this->access->require($property, $actorId, HrAccess::SERVICE_CHARGE, 'This person may not share the service charge.');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $lock): void {
            $d = $this->store->distribution($property, strtolower($id)) ?? throw Refusal::notFound('Distribution not found.');

            if ($d['status'] !== 'draft' || (int) $d['distributed_minor'] === 0) {
                throw Refusal::stateConflict('Only a draft that shares something is approved. Work it out first.');
            }

            if ($this->businessDate->current($property)->toString() <= (string) $d['period_end']) {
                throw Refusal::stateConflict('The month is not over yet. Approve it after its last day.');
            }

            $now = $this->clock->nowUtc();
            $payload = ['distribution' => $d['number'], 'period' => $d['period'], 'collected_minor' => (int) $d['collected_minor'], 'distributed_minor' => (int) $d['distributed_minor']];

            if ($d['approval_id'] === null) {
                $this->approvals->requirementFor($property, self::SUBJECT, (int) $d['distributed_minor']);
                $approvalId = $this->approvals->request(new ApprovalRequestInput($property, self::SUBJECT, $d['id'], $actor, 'Service charge '.$d['period'], $payload, ['status' => 'draft'], (int) $d['distributed_minor'], $this->currency->currencyOf($property)), IdempotencyKey::fromString('hr-service-'.$d['id'].'-'.$d['lock_version']))->id;

                if (! $this->store->updateDistribution($property, $d['id'], $lock, ['approval_id' => $approvalId, 'approval_by' => $actor], $now)) {
                    throw Refusal::stateConflict('This distribution changed after you opened it. Reload it.');
                }

                $this->audit->record(new AuditEntry($property->toString(), $actor, 'service_charge.approval_requested', 'service_charge_distribution', $d['id'], null, $payload, null, $approvalId));

                return;
            }

            if ($d['approval_by'] !== $actor) {
                throw Refusal::forbidden('The person who asked for the approval takes the decision.');
            }

            $view = $this->approvals->find($property, (string) $d['approval_id']) ?? throw Refusal::notFound('Approval not found.');

            if (in_array($view->status, ['rejected', 'cancelled', 'expired'], true)) {
                if (! $this->store->updateDistribution($property, $d['id'], $lock, ['approval_id' => null, 'approval_by' => null], $now)) {
                    throw Refusal::stateConflict('This distribution changed after you opened it. Reload it.');
                }

                $this->audit->record(new AuditEntry($property->toString(), $actor, 'service_charge.approval_refused', 'service_charge_distribution', $d['id'], null, ['period' => $d['period'], 'status' => $view->status], null, $d['approval_id']));

                return;
            }

            if (! $view->isApproved() || $view->consumed) {
                return;
            }

            $this->approvals->consume($property, (string) $d['approval_id'], self::SUBJECT, $d['id'], $payload, $actor);

            if (! $this->store->updateDistribution($property, $d['id'], $lock, ['status' => 'approved', 'approved_at' => $now->format('Y-m-d H:i:s.u')], $now)) {
                throw Refusal::stateConflict('This distribution changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'service_charge.approved', 'service_charge_distribution', $d['id'], ['status' => 'draft'], ['status' => 'approved', ...$payload], null, $d['approval_id']));
            $this->outbox->publish(new OutboxEvent($property, 'hr.service-charge.approved', $d['id'], 1, ['distribution_id' => $d['id'], 'period' => $d['period'], 'distributed_minor' => (int) $d['distributed_minor']]));
        });

        return $this->overview($property, $actorId, $id);
    }

    /** A draft is dropped. @return array<string, mixed> */
    public function discard(PropertyId $property, string $actorId, string $id): array
    {
        $this->access->require($property, $actorId, HrAccess::SERVICE_CHARGE, 'This person may not share the service charge.');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id): void {
            $d = $this->store->distribution($property, strtolower($id)) ?? throw Refusal::notFound('Distribution not found.');

            if ($d['status'] !== 'draft' || $d['approval_id'] !== null) {
                throw Refusal::stateConflict('Only a draft that was not sent for approval is dropped.');
            }

            $this->store->deleteDraft($property, $d['id']);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'service_charge.discarded', 'service_charge_distribution', $d['id'], ['status' => 'draft'], null));
        });

        return $this->overview($property, $actorId, null);
    }

    /** @param array<string, mixed> $d @return array<string, mixed> */
    private function shape(PropertyId $property, string $actor, array $d, string $today): array
    {
        $approval = $d['approval_id'] === null ? null : $this->approvals->find($property, (string) $d['approval_id']);
        $draft = $d['status'] === 'draft';

        return [
            'id' => $d['id'], 'number' => $d['number'], 'period' => $d['period'], 'status' => $d['status'], 'days_booked' => (int) $d['days_booked'], 'collected_minor' => (int) $d['collected_minor'], 'pool_minor' => (int) $d['pool_minor'],
            'reserve_minor' => (int) $d['reserve_minor'], 'distributed_minor' => (int) $d['distributed_minor'], 'residue_minor' => (int) $d['residue_minor'], 'staff_share_bp' => (int) $d['staff_share_bp'], 'reserve_bp' => (int) $d['reserve_bp'],
            'sources' => $d['sources'] === null ? [] : json_decode((string) $d['sources'], true, 512, JSON_THROW_ON_ERROR), 'lock_version' => (int) $d['lock_version'], 'month_over' => $today > (string) $d['period_end'],
            'approval' => $approval === null ? null : ['id' => $approval->id, 'status' => $approval->status, 'consumed' => $approval->consumed],
            'may' => ['calculate' => $draft && $d['approval_id'] === null, 'approve' => $draft && (int) $d['distributed_minor'] > 0 && ($d['approval_id'] === null || $d['approval_by'] === $actor), 'discard' => $draft && $d['approval_id'] === null],
        ];
    }
}
