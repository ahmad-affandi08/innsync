<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Modules\Property\Application\Ports\PropertyTimeZoneReader;
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
use App\Shared\Domain\Time\PropertyTimeZone;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Correcting what attendance says about a day (FR-HR-019). The times of a person on a day are changed only through a correction: a reason, and the approval the owner configured (subject
 * `hr.attendance-correction`; mandatory, so with no policy no correction is made). It keeps the times before and after, who asked and who applied it. The person who asked applies it once the approvers have
 * approved, and only when the record still says what it said when it was asked for. Lateness, extra time and the rest are worked out whenever attendance is read, so they follow at once; an event tells
 * whatever is built on the hours (payroll, later) that the day changed.
 */
final readonly class AttendanceCorrectionService
{
    public const SUBJECT = 'hr.attendance-correction';

    /** How far back a day may be corrected. */
    public const DAYS_BACK = 90;

    private const STAMP = 'Y-m-d H:i:s.u';

    public function __construct(
        private AttendanceCorrectionStore $store,
        private AttendanceStore $attendance,
        private RosterStore $roster,
        private EmployeeStore $employees,
        private HrAccess $access,
        private ApprovalGate $approvals,
        private PropertyTimeZoneReader $zones,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->access->require($property, $actorId, HrAccess::ATTENDANCE, 'This person may not see corrections of attendance.');

        return ['corrections' => array_map(fn (array $r): array => $this->shape($property, $r), $this->store->latest($property, 200)), 'days_back' => self::DAYS_BACK];
    }

    /** @return array<string, mixed> */
    public function request(PropertyId $property, string $actorId, string $employeeId, string $date, string $in, ?string $out, string $reason): array
    {
        $this->access->require($property, $actorId, HrAccess::ATTENDANCE, 'This person may not correct attendance.');
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Say why attendance is corrected, in at most 200 characters.', ['reason']);
        }

        $out = $out === '' ? null : $out;

        if (! ShiftTimes::isTime($in) || ($out !== null && ! ShiftTimes::isTime($out))) {
            throw Refusal::invalid('Give the time as hours and minutes, like 07:05.', [ShiftTimes::isTime($in) ? 'out_time' : 'in_time']);
        }

        $tz = $this->zone($property);
        $now = $this->clock->nowUtc();
        $today = $tz->calendarDateAt($now)->toString();

        if (! ShiftTimes::isDate($date) || $date > $today || $date < DateMath::format('Y-m-d', $today.' -'.self::DAYS_BACK.' days')) {
            throw Refusal::invalid('Give a day from the last '.self::DAYS_BACK.' days, up to today.', ['work_date']);
        }

        $employee = $this->employees->employee($property, strtolower($employeeId)) ?? throw Refusal::invalid('Choose an employee of this property.', ['employee_id']);
        $entry = $this->roster->entry($property, $employee['id'], $date);

        if ($entry === null || (bool) $entry['is_off']) {
            throw Refusal::invalid('The roster has no shift for this person on this day.', ['work_date']);
        }

        [$newIn, $newOut] = ShiftTimes::clocked($tz, $date, $in, $out);

        if ($newIn > $now || ($newOut !== null && $newOut > $now)) {
            throw Refusal::invalid('A time that has not come yet cannot be recorded.', ['in_time', 'out_time']);
        }

        $record = $this->attendance->record($property, $employee['id'], $date);

        if ($record !== null && $record['out_at'] !== null && $newOut === null) {
            throw Refusal::invalid('This day was clocked out; give the time out too.', ['out_time']);
        }

        if ($record !== null && $this->sameTime($record['in_at'], $newIn) && $this->sameTime($record['out_at'], $newOut)) {
            throw Refusal::invalid('Nothing is different from what is recorded.', ['in_time', 'out_time']);
        }

        $actor = strtolower($actorId);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actor, $employee, $date, $newIn, $newOut, $record, $reason, $id, $now): void {
            $this->employees->lockEmployee($property, $employee['id']);

            if ($this->store->pendingFor($property, $employee['id'], $date) !== null) {
                throw Refusal::stateConflict('A correction of this day waits for its approval already.');
            }

            // Throws when there is no policy: this subject is mandatory.
            $this->approvals->requirementFor($property, self::SUBJECT);
            $old = ['in_at' => $record === null ? null : $this->utc($record['in_at']), 'out_at' => $record === null ? null : $this->utc($record['out_at'])];
            $new = ['in_at' => $newIn->format('Y-m-d\TH:i:s\Z'), 'out_at' => $newOut?->format('Y-m-d\TH:i:s\Z')];
            $approvalId = $this->approvals->request(new ApprovalRequestInput(
                $property, self::SUBJECT, $id, $actor, $reason, ['employee_id' => $employee['id'], 'work_date' => $date, 'record_id' => $record['id'] ?? null, 'old' => $old, 'new' => $new], ['employee' => $employee['number'], 'work_date' => $date, 'old' => $old, 'new' => $new],
            ), IdempotencyKey::fromString('hr-correction-'.$id))->id;

            $this->store->add($property, [
                'id' => $id, 'employee_id' => $employee['id'], 'work_date' => $date, 'status' => 'pending_approval', 'reason' => $reason, 'record_id' => $record['id'] ?? null,
                'old_in_at' => $record['in_at'] ?? null, 'old_out_at' => $record['out_at'] ?? null, 'new_in_at' => $newIn->format(self::STAMP), 'new_out_at' => $newOut?->format(self::STAMP), 'approval_id' => $approvalId, 'requested_by' => $actor,
            ], $now);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'attendance_correction.requested', 'attendance_correction', $id, $old, $new + ['employee' => $employee['number'], 'work_date' => $date], $reason, $approvalId));
        });

        return $this->one($property, $id);
    }

    /** Takes the decision of the approvers: approved, the day is changed; rejected, the correction is closed. While they have not decided, nothing changes. @return array<string, mixed> */
    public function apply(PropertyId $property, string $actorId, string $id): array
    {
        $this->access->require($property, $actorId, HrAccess::ATTENDANCE, 'This person may not apply a correction of attendance.');
        $actor = strtolower($actorId);
        $c = $this->store->find($property, strtolower($id)) ?? throw Refusal::notFound('Correction not found.');

        if ($c['status'] !== 'pending_approval') {
            throw Refusal::stateConflict('This correction is not waiting for approval.');
        }

        $view = $this->approvals->find($property, (string) $c['approval_id']) ?? throw Refusal::notFound('Approval not found.');

        if ($view->makerId !== $actor) {
            throw Refusal::forbidden('The person who asked for the correction applies it.');
        }

        $rejected = in_array($view->status, ['rejected', 'cancelled', 'expired'], true);

        if (! $rejected && ! ($view->isApproved() && ! $view->consumed)) {
            return $this->shape($property, $c);
        }

        $this->transactions->run(function () use ($property, $actor, $c, $rejected): void {
            $now = $this->clock->nowUtc();
            $date = substr((string) $c['work_date'], 0, 10);
            $this->employees->lockEmployee($property, $c['employee_id']);

            if ($rejected) {
                $this->close($property, $c, 'rejected', $actor, $now, null);

                return;
            }

            $record = $this->attendance->record($property, $c['employee_id'], $date);

            if (($record['id'] ?? null) !== $c['record_id'] || ($record !== null && (! $this->sameTime($record['in_at'], $c['old_in_at']) || ! $this->sameTime($record['out_at'], $c['old_out_at'])))) {
                throw Refusal::stateConflict('The attendance of this day changed after the correction was asked for. Ask for it again.');
            }

            $this->approvals->consume($property, (string) $c['approval_id'], self::SUBJECT, $c['id'], ['employee_id' => $c['employee_id'], 'work_date' => $date, 'record_id' => $c['record_id'], 'old' => $this->times($c['old_in_at'], $c['old_out_at']), 'new' => $this->times($c['new_in_at'], $c['new_out_at'])], $actor);
            $recordId = $record['id'] ?? $this->ids->next();

            if ($record === null) {
                if (! $this->attendance->add($property, ['id' => $recordId, 'employee_id' => $c['employee_id'], 'work_date' => $date, 'in_at' => $c['new_in_at'], 'in_method' => 'manual', 'out_at' => $c['new_out_at'], 'out_method' => $c['new_out_at'] === null ? null : 'manual', 'recorded_by' => $actor, 'manual_reason' => $c['reason']], $now)) {
                    throw Refusal::stateConflict('This person has a record for this day already.');
                }
            } else {
                $inChanged = ! $this->sameTime($record['in_at'], $c['new_in_at']);
                $outChanged = ! $this->sameTime($record['out_at'], $c['new_out_at']);
                $fields = ['manual_reason' => $c['reason'], 'recorded_by' => $actor, 'in_at' => $c['new_in_at'], 'out_at' => $c['new_out_at']];

                if ($inChanged) {
                    $fields += ['in_method' => 'manual', 'in_distance_m' => null];
                }

                if ($outChanged) {
                    $fields += ['out_method' => 'manual', 'out_distance_m' => null];
                }

                if (! $this->attendance->update($property, $record['id'], (int) $record['lock_version'], $fields, $now)) {
                    throw Refusal::stateConflict('The attendance of this day changed meanwhile.');
                }
            }

            $this->close($property, $c, 'applied', $actor, $now, $recordId);
            $this->outbox->publish(new OutboxEvent($property, 'hr.attendance.corrected', $recordId, 1, ['employee_id' => $c['employee_id'], 'work_date' => $date, 'correction_id' => $c['id'], 'actor_id' => $actor]));
        });

        return $this->one($property, $c['id']);
    }

    /** @return array<string, mixed> */
    public function cancel(PropertyId $property, string $actorId, string $id): array
    {
        $this->access->require($property, $actorId, HrAccess::ATTENDANCE, 'This person may not cancel a correction of attendance.');
        $actor = strtolower($actorId);
        $c = $this->store->find($property, strtolower($id)) ?? throw Refusal::notFound('Correction not found.');

        if ($c['status'] !== 'pending_approval') {
            throw Refusal::stateConflict('This correction is not waiting for approval.');
        }

        $this->transactions->run(function () use ($property, $actor, $c): void {
            $this->close($property, $c, 'cancelled', $actor, $this->clock->nowUtc(), null);
        });

        return $this->one($property, $c['id']);
    }

    /** @param array<string, mixed> $c */
    private function close(PropertyId $property, array $c, string $status, string $actor, DateTimeImmutable $now, ?string $recordId): void
    {
        $fields = ['status' => $status] + ($status === 'applied' ? ['applied_by' => $actor, 'applied_at' => $now->format(self::STAMP)] : []);

        if (! $this->store->update($property, $c['id'], (int) $c['lock_version'], $fields, $now)) {
            throw Refusal::stateConflict('This correction changed meanwhile.');
        }

        $date = substr((string) $c['work_date'], 0, 10);
        $this->audit->record(new AuditEntry($property->toString(), $actor, 'attendance_correction.'.$status, 'attendance_correction', $c['id'], $this->times($c['old_in_at'], $c['old_out_at']), $this->times($c['new_in_at'], $c['new_out_at']) + ['status' => $status, 'employee' => $c['number'], 'work_date' => $date], $status === 'applied' ? $c['reason'] : null, $c['approval_id']));
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    private function shape(PropertyId $property, array $c): array
    {
        $approval = $c['approval_id'] === null ? null : $this->approvals->find($property, (string) $c['approval_id']);

        return [
            'id' => $c['id'], 'employee' => ['id' => $c['employee_id'], 'number' => $c['number'], 'name' => $c['full_name'], 'department' => $c['department']], 'work_date' => substr((string) $c['work_date'], 0, 10), 'status' => $c['status'], 'reason' => $c['reason'],
            'old' => $this->times($c['old_in_at'], $c['old_out_at']), 'new' => $this->times($c['new_in_at'], $c['new_out_at']),
            'applied_at' => $c['applied_at'] === null ? null : $this->utc($c['applied_at']), 'created_at' => $this->utc($c['created_at']),
            'approval' => $approval === null ? null : ['id' => $approval->id, 'status' => $approval->status, 'consumed' => $approval->consumed],
        ];
    }

    /** @return array<string, mixed> */
    private function one(PropertyId $property, string $id): array
    {
        return $this->shape($property, $this->store->find($property, $id) ?? throw Refusal::notFound('Correction not found.'));
    }

    /** @return array{in_at: string|null, out_at: string|null} */
    private function times(mixed $in, mixed $out): array
    {
        return ['in_at' => $this->utc($in), 'out_at' => $this->utc($out)];
    }

    private function utc(mixed $v): ?string
    {
        return $v === null ? null : (new DateTimeImmutable((string) $v, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    private function sameTime(mixed $stored, mixed $other): bool
    {
        return $this->utc($stored) === ($other instanceof DateTimeImmutable ? $other->format('Y-m-d\TH:i:s\Z') : $this->utc($other));
    }

    private function zone(PropertyId $property): PropertyTimeZone
    {
        return $this->zones->forProperty($property) ?? throw Refusal::notFound('Property not found.');
    }
}
