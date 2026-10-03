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
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\PropertyTimeZone;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Overtime approved before it is worked (FR-HR-018). A supervisor asks for a number of minutes after the end of a planned shift, for one person and day, before the shift is over. The owner may put an
 * approval chain on it (subject `hr.overtime`); with none, the request is approved when it is made. The extra time a person works is then told apart when attendance is read: up to the minutes approved
 * before the shift ended it is overtime, anything beyond is extra time nobody approved.
 */
final readonly class OvertimeService
{
    public const SUBJECT = 'hr.overtime';

    public const MIN_MINUTES = 15;

    public const MAX_MINUTES = 480;

    /** How far back the list reaches, and how far ahead a request may be made. */
    private const BACK_DAYS = 62;

    private const AHEAD_DAYS = 31;

    public function __construct(
        private OvertimeStore $store,
        private RosterStore $roster,
        private EmployeeStore $employees,
        private HrAccess $access,
        private ApprovalGate $approvals,
        private PropertyTimeZoneReader $zones,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->access->require($property, $actorId, HrAccess::ATTENDANCE, 'This person may not see overtime.');
        $tz = $this->zone($property);
        $today = $tz->calendarDateAt($this->clock->nowUtc())->toString();

        return ['requests' => array_map(fn (array $r): array => $this->shape($property, $r, $tz), $this->store->between($property, date('Y-m-d', strtotime($today.' -'.self::BACK_DAYS.' days')), date('Y-m-d', strtotime($today.' +'.self::AHEAD_DAYS.' days'))))];
    }

    /** @return array<string, mixed> */
    public function request(PropertyId $property, string $actorId, string $employeeId, string $date, int $minutes, string $reason): array
    {
        $this->access->require($property, $actorId, HrAccess::ATTENDANCE, 'This person may not ask for overtime.');
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Say why the overtime is needed, in at most 200 characters.', ['reason']);
        }

        if ($minutes < self::MIN_MINUTES || $minutes > self::MAX_MINUTES) {
            throw Refusal::invalid('Overtime is from '.self::MIN_MINUTES.' to '.self::MAX_MINUTES.' minutes.', ['minutes']);
        }

        $tz = $this->zone($property);
        $now = $this->clock->nowUtc();
        $today = $tz->calendarDateAt($now)->toString();

        if (! ShiftTimes::isDate($date) || $date < date('Y-m-d', strtotime($today.' -1 day')) || $date > date('Y-m-d', strtotime($today.' +'.self::AHEAD_DAYS.' days'))) {
            throw Refusal::invalid('Give a day from yesterday to '.self::AHEAD_DAYS.' days ahead.', ['work_date']);
        }

        $employee = $this->employees->employee($property, strtolower($employeeId)) ?? throw Refusal::invalid('Choose an employee of this property.', ['employee_id']);

        if ($employee['status'] !== 'active') {
            throw Refusal::invalid('This person no longer works here.', ['employee_id']);
        }

        $entry = $this->roster->entry($property, $employee['id'], $date);

        if ($entry === null || (bool) $entry['is_off']) {
            throw Refusal::invalid('The roster has no shift for this person on this day.', ['work_date']);
        }

        [, $end] = ShiftTimes::window($entry, $tz);

        if ($end <= $now) {
            throw Refusal::stateConflict('This shift is over. Overtime is asked for before the shift ends; what was worked since is a correction of attendance.');
        }

        $actor = strtolower($actorId);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actor, $employee, $date, $minutes, $reason, $id, $now): void {
            $this->employees->lockEmployee($property, $employee['id']);

            if ($this->store->openFor($property, $employee['id'], $date) !== null) {
                throw Refusal::stateConflict('This person has overtime for this day already.');
            }

            $required = $this->approvals->requirementFor($property, self::SUBJECT)->required;
            $approvalId = null;

            if ($required) {
                $approvalId = $this->approvals->request(new ApprovalRequestInput(
                    $property, self::SUBJECT, $id, $actor, $reason, ['employee_id' => $employee['id'], 'work_date' => $date, 'minutes' => $minutes], ['employee' => $employee['number']],
                ), IdempotencyKey::fromString('hr-overtime-'.$id))->id;
            }

            $status = $required ? 'pending_approval' : 'approved';
            $this->store->add($property, ['id' => $id, 'employee_id' => $employee['id'], 'work_date' => $date, 'minutes' => $minutes, 'reason' => $reason, 'status' => $status, 'approval_id' => $approvalId, 'requested_by' => $actor, 'approved_at' => $required ? null : $now->format('Y-m-d H:i:s.u')], $now);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'overtime.requested', 'overtime', $id, null, ['status' => $status, 'employee' => $employee['number'], 'work_date' => $date, 'minutes' => $minutes], $reason, $approvalId));
        });

        return $this->one($property, $id, $tz);
    }

    /** Takes the decision of the approvers: approved, the overtime counts from now; rejected, it is closed. While they have not decided, nothing changes. @return array<string, mixed> */
    public function release(PropertyId $property, string $actorId, string $id): array
    {
        $this->access->require($property, $actorId, HrAccess::ATTENDANCE, 'This person may not take the decision on overtime.');
        $actor = strtolower($actorId);
        $r = $this->store->find($property, strtolower($id)) ?? throw Refusal::notFound('Overtime request not found.');

        if ($r['status'] !== 'pending_approval') {
            throw Refusal::stateConflict('This overtime is not waiting for approval.');
        }

        $view = $this->approvals->find($property, (string) $r['approval_id']) ?? throw Refusal::notFound('Approval not found.');

        if ($view->makerId !== $actor) {
            throw Refusal::forbidden('The person who asked for the overtime takes the decision.');
        }

        $rejected = in_array($view->status, ['rejected', 'cancelled', 'expired'], true);

        if ($rejected || ($view->isApproved() && ! $view->consumed)) {
            $this->transactions->run(function () use ($property, $actor, $r, $rejected): void {
                $now = $this->clock->nowUtc();

                if (! $rejected) {
                    $this->approvals->consume($property, (string) $r['approval_id'], self::SUBJECT, $r['id'], ['employee_id' => $r['employee_id'], 'work_date' => substr((string) $r['work_date'], 0, 10), 'minutes' => (int) $r['minutes']], $actor);
                }

                $status = $rejected ? 'rejected' : 'approved';

                if (! $this->store->update($property, $r['id'], (int) $r['lock_version'], ['status' => $status, 'approved_at' => $rejected ? null : $now->format('Y-m-d H:i:s.u')], $now)) {
                    throw Refusal::stateConflict('This overtime changed meanwhile.');
                }

                $this->audit->record(new AuditEntry($property->toString(), $actor, 'overtime.'.$status, 'overtime', $r['id'], ['status' => 'pending_approval'], ['status' => $status, 'employee' => $r['number'], 'work_date' => substr((string) $r['work_date'], 0, 10), 'minutes' => (int) $r['minutes']], null, $r['approval_id']));
            });
        }

        return $this->one($property, $r['id'], $this->zone($property));
    }

    /** @return array<string, mixed> */
    public function cancel(PropertyId $property, string $actorId, string $id): array
    {
        $this->access->require($property, $actorId, HrAccess::ATTENDANCE, 'This person may not cancel overtime.');
        $actor = strtolower($actorId);
        $r = $this->store->find($property, strtolower($id)) ?? throw Refusal::notFound('Overtime request not found.');
        $tz = $this->zone($property);

        if (! in_array($r['status'], ['pending_approval', 'approved'], true)) {
            throw Refusal::stateConflict('This overtime is closed already.');
        }

        $entry = $this->roster->entry($property, $r['employee_id'], substr((string) $r['work_date'], 0, 10));

        if ($entry !== null && ShiftTimes::window($entry, $tz)[1] <= $this->clock->nowUtc()) {
            throw Refusal::stateConflict('The shift is over; overtime that was approved stays as it was.');
        }

        $this->transactions->run(function () use ($property, $actor, $r): void {
            $this->employees->lockEmployee($property, $r['employee_id']);

            if (! $this->store->update($property, $r['id'], (int) $r['lock_version'], ['status' => 'cancelled'], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This overtime changed meanwhile.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'overtime.cancelled', 'overtime', $r['id'], ['status' => $r['status']], ['status' => 'cancelled', 'employee' => $r['number'], 'work_date' => substr((string) $r['work_date'], 0, 10), 'minutes' => (int) $r['minutes']], null, $r['approval_id']));
        });

        return $this->one($property, $r['id'], $tz);
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function shape(PropertyId $property, array $r, PropertyTimeZone $tz): array
    {
        $date = substr((string) $r['work_date'], 0, 10);
        $entry = $this->roster->entry($property, $r['employee_id'], $date);
        $approval = $r['approval_id'] === null ? null : $this->approvals->find($property, (string) $r['approval_id']);
        $ended = $entry !== null && ShiftTimes::window($entry, $tz)[1] <= $this->clock->nowUtc();

        return [
            'id' => $r['id'], 'employee' => ['id' => $r['employee_id'], 'number' => $r['number'], 'name' => $r['full_name'], 'department' => $r['department']], 'work_date' => $date, 'minutes' => (int) $r['minutes'], 'reason' => $r['reason'], 'status' => $r['status'],
            'approved_at' => $r['approved_at'] === null ? null : (new DateTimeImmutable((string) $r['approved_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'), 'lock_version' => (int) $r['lock_version'],
            'approval' => $approval === null ? null : ['id' => $approval->id, 'status' => $approval->status, 'consumed' => $approval->consumed],
            'may_cancel' => in_array($r['status'], ['pending_approval', 'approved'], true) && ! $ended,
        ];
    }

    /** @return array<string, mixed> */
    private function one(PropertyId $property, string $id, PropertyTimeZone $tz): array
    {
        return $this->shape($property, $this->store->find($property, $id) ?? throw Refusal::notFound('Overtime request not found.'), $tz);
    }

    private function zone(PropertyId $property): PropertyTimeZone
    {
        return $this->zones->forProperty($property) ?? throw Refusal::notFound('Property not found.');
    }
}
