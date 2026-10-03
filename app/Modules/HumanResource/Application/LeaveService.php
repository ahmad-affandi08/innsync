<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Approval\ApprovalGate;
use App\Shared\Application\Approval\ApprovalRequestInput;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Files\DownloadFile;
use App\Shared\Application\Files\FileAccessDenied;
use App\Shared\Application\Files\FileAccessPolicy;
use App\Shared\Application\Files\FileContent;
use App\Shared\Application\Files\FilePolicy;
use App\Shared\Application\Files\FileRejected;
use App\Shared\Application\Files\FileSensitivity;
use App\Shared\Application\Files\FileUpload;
use App\Shared\Application\Files\StoredFile;
use App\Shared\Application\Files\StoredFileNotFound;
use App\Shared\Application\Files\StoreFile;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Leave, sick leave and permits (FR-HR-015, -016). A person asks for a kind of leave for days of a calendar year, for themselves with their own account or, with the leave privilege, for someone else. The
 * approval the owner configured is mandatory (subject `hr.leave`), so with no policy no request is taken; the person who asked takes the decision once the approvers have decided. Approving takes the days
 * out of the roster (so staffing shows the gap and nobody is absent), and cancelling before the leave starts puts them back. Annual leave comes out of a yearly balance: the entitlement of the kind once the
 * months of work have passed, plus adjustments, less the days taken and the days waiting for approval. Days the roster shows as a day off are not counted.
 */
final readonly class LeaveService
{
    public const SUBJECT = 'hr.leave';

    public const MAX_DAYS = 120;

    /** How many days back a leave that does not come out of a balance may start (sick leave is often reported after). */
    public const BACK_DAYS = 7;

    public const EVIDENCE_PURPOSE = 'hr_personnel_document';

    public const EVIDENCE_MAX_BYTES = 3_145_728;

    private const ENTRY_FIELDS = ['id', 'employee_id', 'work_date', 'pattern_id', 'pattern_code', 'department', 'is_off', 'starts_at', 'ends_at', 'starts2_at', 'ends2_at', 'minutes', 'planned_by'];

    public function __construct(
        private LeaveStore $store,
        private RosterStore $roster,
        private EmployeeStore $employees,
        private AttendanceStore $attendance,
        private HrAccess $access,
        private ApprovalGate $approvals,
        private BusinessDateProvider $businessDate,
        private StoreFile $storeFile,
        private DownloadFile $downloadFile,
        private PermissionChecker $permissions,
        private OutboxPublisher $outbox,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId, ?int $year, ?string $status): array
    {
        $this->access->assertProperty($property);
        $manage = $this->access->may($property, $actorId, HrAccess::LEAVE);
        $mineId = $this->employees->employeeOfUser($property, strtolower($actorId));

        if (! $manage && $mineId === null) {
            throw Refusal::forbidden('This person has no employee record and may not see leave.');
        }

        $today = $this->businessDate->current($property)->toString();
        $thisYear = (int) substr($today, 0, 4);
        $year ??= $thisYear;

        if ($year < $thisYear - 1 || $year > $thisYear + 1) {
            throw Refusal::invalid('Choose last year, this year or next year.', ['year']);
        }

        if ($status !== null && $status !== '' && ! in_array($status, ['pending_approval', 'approved', 'rejected', 'cancelled'], true)) {
            throw Refusal::invalid('Choose a status of the list.', ['status']);
        }

        $types = $this->store->types($property, true);
        $out = ['year' => $year, 'years' => [$thisYear - 1, $thisYear, $thisYear + 1], 'today' => $today, 'types' => array_map(LeaveTypeService::shape(...), $types), 'may' => ['manage' => $manage], 'mine' => null, 'requests' => null, 'balances' => null, 'adjustments' => null, 'employees' => null];

        if ($mineId !== null && ($e = $this->employees->employee($property, $mineId)) !== null) {
            $out['mine'] = [
                'employee' => ['id' => $e['id'], 'number' => $e['number'], 'name' => $e['full_name'], 'department' => $e['department']], 'active' => $e['status'] === 'active',
                'balances' => $this->balances($property, [$e], $types, $year)[0]['items'] ?? [], 'requests' => array_map(fn (array $r): array => $this->shape($property, $actorId, $r, $today), $this->store->requests($property, $e['id'], null, 100)),
            ];
        }

        if ($manage) {
            $people = $this->employees->employees($property, 'active');
            $out['requests'] = array_map(fn (array $r): array => $this->shape($property, $actorId, $r, $today), $this->store->requests($property, null, $status === '' ? null : $status, 300));
            $out['balances'] = $this->balances($property, $people, $types, $year);
            $out['adjustments'] = array_map(static fn (array $a): array => ['id' => $a['id'], 'employee' => ['id' => $a['employee_id'], 'number' => $a['number'], 'name' => $a['full_name']], 'type_code' => $a['type_code'], 'days' => (int) $a['days_delta'], 'reason' => $a['reason'], 'created_at' => $a['created_at']], $this->store->adjustments($property, $year, null));
            $out['employees'] = array_map(static fn (array $e): array => ['id' => $e['id'], 'number' => $e['number'], 'name' => $e['full_name'], 'department' => $e['department']], $people);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function request(PropertyId $property, string $actorId, ?string $employeeId, string $typeId, string $from, string $to, string $reason, ?string $evidence, ?string $evidenceName): array
    {
        $this->access->assertProperty($property);
        $actor = strtolower($actorId);
        $mine = $this->employees->employeeOfUser($property, $actor);
        $targetId = $employeeId === null || $employeeId === '' ? $mine : strtolower($employeeId);

        if ($targetId === null) {
            throw Refusal::forbidden('Your account is not linked to an employee record.');
        }

        if ($targetId !== $mine) {
            $this->access->require($property, $actorId, HrAccess::LEAVE, 'This person may only ask for their own leave.');
        }

        $employee = $this->employees->employee($property, $targetId) ?? throw Refusal::invalid('Choose an employee of this property.', ['employee_id']);

        if ($employee['status'] !== 'active') {
            throw Refusal::invalid('This person no longer works here.', ['employee_id']);
        }

        $type = $this->store->type($property, strtolower($typeId));

        if ($type === null || ! (bool) $type['is_active']) {
            throw Refusal::invalid('Choose a kind of leave that is in use.', ['leave_type_id']);
        }

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Say why, in at most 200 characters.', ['reason']);
        }

        if (! ShiftTimes::isDate($from) || ! ShiftTimes::isDate($to) || $to < $from) {
            throw Refusal::invalid('Give the first and the last day, the last not before the first.', ['from_date', 'to_date']);
        }

        $span = (int) round((strtotime($to) - strtotime($from)) / 86400) + 1;
        $today = $this->businessDate->current($property)->toString();
        $deducts = (bool) $type['deducts_balance'];

        if ($span > self::MAX_DAYS) {
            throw Refusal::invalid('A request covers at most '.self::MAX_DAYS.' days.', ['to_date']);
        }

        if ($from < ($deducts ? $today : date('Y-m-d', strtotime($today.' -'.self::BACK_DAYS.' days')))) {
            throw Refusal::invalid($deducts ? 'Leave from the balance starts today or later.' : 'This leave may start at most '.self::BACK_DAYS.' days back.', ['from_date']);
        }

        if ($from < substr((string) $employee['joined_on'], 0, 10)) {
            throw Refusal::invalid('This person had not joined yet.', ['from_date']);
        }

        if ($deducts) {
            if (substr($from, 0, 4) !== substr($to, 0, 4)) {
                throw Refusal::invalid('Leave from the balance stays within one year; ask for the next year separately.', ['to_date']);
            }

            $eligible = $this->eligibleOn($employee, $type);

            if ($from < $eligible) {
                throw Refusal::invalid("The right to this leave begins on {$eligible}.", ['from_date']);
            }
        }

        if ($type['evidence_after_days'] !== null && $span > (int) $type['evidence_after_days'] && ($evidence === null || $evidence === '')) {
            throw Refusal::invalid('A paper (a doctor\'s note or like) is needed for this leave.', ['evidence']);
        }

        if ($this->attendance->between($property, $from, $to, $employee['id']) !== []) {
            throw Refusal::stateConflict('This person has attendance recorded within these days.');
        }

        $file = null;

        if ($evidence !== null && $evidence !== '') {
            try {
                $file = $this->storeFile->execute(new FileUpload($property, $actor, self::EVIDENCE_PURPOSE, 'employee', $employee['id'], $evidence, new FilePolicy(['application/pdf', 'image/jpeg', 'image/png'], self::EVIDENCE_MAX_BYTES, FileSensitivity::Sensitive, false), $evidenceName));
            } catch (FileRejected $e) {
                throw Refusal::invalid($e->getMessage(), ['evidence']);
            }
        }

        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actor, $employee, $type, $from, $to, $reason, $file, $id, $deducts): void {
            $this->employees->lockEmployee($property, $employee['id']);

            if ($this->store->openBetween($property, $employee['id'], $from, $to) !== []) {
                throw Refusal::stateConflict('This person has leave waiting or approved within these days.');
            }

            $days = $this->countDays($property, $employee['id'], $from, $to);

            if ($days < 1) {
                throw Refusal::invalid('Every one of these days is a day off.', ['from_date', 'to_date']);
            }

            if ($deducts) {
                $left = $this->remaining($property, $employee, $type, (int) substr($from, 0, 4), null);

                if ($days > $left) {
                    throw Refusal::stateConflict("The balance is {$left} day(s); {$days} are asked for.");
                }
            }

            // Throws when there is no policy: this subject is mandatory.
            $this->approvals->requirementFor($property, self::SUBJECT);
            $approvalId = $this->approvals->request(new ApprovalRequestInput(
                $property, self::SUBJECT, $id, $actor, $reason, $this->payload($employee['id'], $type['id'], $from, $to), ['employee' => $employee['number'], 'type' => $type['code'], 'from' => $from, 'to' => $to, 'days' => $days],
            ), IdempotencyKey::fromString('hr-leave-'.$id))->id;

            $this->store->addRequest($property, [
                'id' => $id, 'employee_id' => $employee['id'], 'leave_type_id' => $type['id'], 'type_code' => $type['code'], 'type_name' => $type['name'], 'deducts_balance' => $deducts, 'from_date' => $from, 'to_date' => $to, 'days' => $days,
                'reason' => $reason, 'status' => 'pending_approval', 'approval_id' => $approvalId, 'evidence_file_id' => $file?->id, 'requested_by' => $actor,
            ], $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'leave.requested', 'leave', $id, null, ['employee' => $employee['number'], 'type' => $type['code'], 'from' => $from, 'to' => $to, 'days' => $days, 'evidence' => $file !== null], $reason, $approvalId));
        });

        return $this->one($property, $actorId, $id);
    }

    /** Takes the decision of the approvers: approved, the days leave the roster; rejected, the request is closed. While they have not decided, nothing changes. @return array<string, mixed> */
    public function release(PropertyId $property, string $actorId, string $id): array
    {
        $this->access->assertProperty($property);
        $actor = strtolower($actorId);
        $r = $this->store->request($property, strtolower($id)) ?? throw Refusal::notFound('Leave request not found.');

        if ($r['status'] !== 'pending_approval') {
            throw Refusal::stateConflict('This request is not waiting for approval.');
        }

        if ($r['requested_by'] !== $actor) {
            throw Refusal::forbidden('The person who asked for the leave takes the decision.');
        }

        $view = $this->approvals->find($property, (string) $r['approval_id']) ?? throw Refusal::notFound('Approval not found.');
        $rejected = in_array($view->status, ['rejected', 'cancelled', 'expired'], true);

        if (! $rejected && ! ($view->isApproved() && ! $view->consumed)) {
            return $this->one($property, $actorId, $r['id']);
        }

        $this->transactions->run(function () use ($property, $actor, $r, $rejected): void {
            $now = $this->clock->nowUtc();
            $from = substr((string) $r['from_date'], 0, 10);
            $to = substr((string) $r['to_date'], 0, 10);
            $this->employees->lockEmployee($property, $r['employee_id']);

            if ($rejected) {
                if (! $this->store->updateRequest($property, $r['id'], (int) $r['lock_version'], ['status' => 'rejected'], $now)) {
                    throw Refusal::stateConflict('This request changed meanwhile.');
                }

                $this->audit->record(new AuditEntry($property->toString(), $actor, 'leave.rejected', 'leave', $r['id'], ['status' => 'pending_approval'], ['status' => 'rejected', 'employee' => $r['number'], 'type' => $r['type_code'], 'from' => $from, 'to' => $to], null, $r['approval_id']));

                return;
            }

            $employee = $this->employees->employee($property, $r['employee_id']);

            if ($employee === null || $employee['status'] !== 'active') {
                throw Refusal::stateConflict('This person no longer works here.');
            }

            if ($this->attendance->between($property, $from, $to, $employee['id']) !== []) {
                throw Refusal::stateConflict('This person has attendance recorded within these days.');
            }

            $days = $this->countDays($property, $employee['id'], $from, $to);

            if ($days < 1) {
                throw Refusal::stateConflict('Every one of these days is a day off now.');
            }

            if ((bool) $r['deducts_balance']) {
                $type = $this->store->type($property, $r['leave_type_id']) ?? throw Refusal::notFound('Kind of leave not found.');
                $left = $this->remaining($property, $employee, $type, (int) substr($from, 0, 4), (int) $r['days']);

                if ($days > $left) {
                    throw Refusal::stateConflict("The balance is {$left} day(s); {$days} are asked for.");
                }
            }

            $this->approvals->consume($property, (string) $r['approval_id'], self::SUBJECT, $r['id'], $this->payload($employee['id'], $r['leave_type_id'], $from, $to), $actor);
            $taken = 0;

            for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d.' +1 day'))) {
                $entry = $this->roster->entry($property, $employee['id'], $d);
                $counted = $entry === null || ! (bool) $entry['is_off'];
                $taken += $counted ? 1 : 0;

                if ($entry !== null) {
                    $this->roster->removeEntry($property, $employee['id'], $d);
                }

                $this->store->addDay($property, ['employee_id' => $employee['id'], 'work_date' => $d, 'leave_id' => $r['id'], 'type_code' => $r['type_code'], 'counted' => $counted, 'entry_snapshot' => $entry === null ? null : json_encode($this->snapshot($entry), JSON_THROW_ON_ERROR)], $now);
            }

            if (! $this->store->updateRequest($property, $r['id'], (int) $r['lock_version'], ['status' => 'approved', 'days' => $taken, 'decided_at' => $now->format('Y-m-d H:i:s.u')], $now)) {
                throw Refusal::stateConflict('This request changed meanwhile.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'leave.approved', 'leave', $r['id'], ['status' => 'pending_approval'], ['status' => 'approved', 'employee' => $r['number'], 'type' => $r['type_code'], 'from' => $from, 'to' => $to, 'days' => $taken], null, $r['approval_id']));
            $this->outbox->publish(new OutboxEvent($property, 'hr.leave.approved', $r['id'], 1, ['employee_id' => $employee['id'], 'leave_id' => $r['id'], 'type' => $r['type_code'], 'from' => $from, 'to' => $to, 'days' => $taken, 'paid' => (bool) ($this->store->type($property, $r['leave_type_id'])['paid'] ?? false)]));
        });

        return $this->one($property, $actorId, $r['id']);
    }

    /** A waiting request is withdrawn; an approved one only before it starts, and its days go back into the roster. @return array<string, mixed> */
    public function cancel(PropertyId $property, string $actorId, string $id): array
    {
        $this->access->assertProperty($property);
        $actor = strtolower($actorId);
        $r = $this->store->request($property, strtolower($id)) ?? throw Refusal::notFound('Leave request not found.');

        if ($r['requested_by'] !== $actor && ! $this->access->may($property, $actorId, HrAccess::LEAVE)) {
            throw Refusal::forbidden('Only the person who asked, or the one who manages leave, may cancel it.');
        }

        if (! in_array($r['status'], ['pending_approval', 'approved'], true)) {
            throw Refusal::stateConflict('This request is closed already.');
        }

        $today = $this->businessDate->current($property)->toString();
        $from = substr((string) $r['from_date'], 0, 10);

        if ($r['status'] === 'approved' && $from < $today) {
            throw Refusal::stateConflict('This leave has started; it cannot be cancelled.');
        }

        $this->transactions->run(function () use ($property, $actor, $r, $from): void {
            $now = $this->clock->nowUtc();
            $this->employees->lockEmployee($property, $r['employee_id']);
            $restored = 0;

            if ($r['status'] === 'approved') {
                $employee = $this->employees->employee($property, $r['employee_id']);

                foreach ($this->store->daysOf($property, $r['id']) as $day) {
                    $date = substr((string) $day['work_date'], 0, 10);

                    if ($day['entry_snapshot'] !== null && $employee !== null && $employee['status'] === 'active' && $this->roster->entry($property, $r['employee_id'], $date) === null) {
                        $this->roster->putEntry($property, (array) json_decode((string) $day['entry_snapshot'], true, 512, JSON_THROW_ON_ERROR), $now);
                        $restored++;
                    }
                }

                $this->store->removeDays($property, $r['id'], $from);
            }

            if (! $this->store->updateRequest($property, $r['id'], (int) $r['lock_version'], ['status' => 'cancelled'], $now)) {
                throw Refusal::stateConflict('This request changed meanwhile.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'leave.cancelled', 'leave', $r['id'], ['status' => $r['status']], ['status' => 'cancelled', 'employee' => $r['number'], 'type' => $r['type_code'], 'from' => $from, 'restored_days' => $restored], null, $r['approval_id']));

            if ($r['status'] === 'approved') {
                $this->outbox->publish(new OutboxEvent($property, 'hr.leave.cancelled', $r['id'], 1, ['employee_id' => $r['employee_id'], 'leave_id' => $r['id'], 'from' => $from]));
            }
        });

        return $this->one($property, $actorId, $r['id']);
    }

    /** Adds or takes days off the balance of a person for a year, with a reason. @return array<string, mixed> the balances of the person that year */
    public function adjust(PropertyId $property, string $actorId, string $employeeId, string $typeId, int $year, int $days, string $reason): array
    {
        $this->access->require($property, $actorId, HrAccess::LEAVE, 'This person may not adjust a leave balance.');
        $reason = trim($reason);
        $thisYear = (int) substr($this->businessDate->current($property)->toString(), 0, 4);

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Say why, in at most 200 characters.', ['reason']);
        }

        if ($days === 0 || abs($days) > 60) {
            throw Refusal::invalid('Adjust by 1 to 60 days, more or less.', ['days']);
        }

        if ($year < $thisYear - 1 || $year > $thisYear + 1) {
            throw Refusal::invalid('Choose last year, this year or next year.', ['year']);
        }

        $employee = $this->employees->employee($property, strtolower($employeeId)) ?? throw Refusal::invalid('Choose an employee of this property.', ['employee_id']);
        $type = $this->store->type($property, strtolower($typeId));

        if ($employee['status'] !== 'active') {
            throw Refusal::invalid('This person no longer works here.', ['employee_id']);
        }

        if ($type === null || ! (bool) $type['deducts_balance']) {
            throw Refusal::invalid('Choose a kind of leave that has a balance.', ['leave_type_id']);
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $employee, $type, $year, $days, $reason): void {
            $this->employees->lockEmployee($property, $employee['id']);

            if ($this->remaining($property, $employee, $type, $year, null) + $days < 0) {
                throw Refusal::stateConflict('The balance would be below zero.');
            }

            $id = $this->ids->next();
            $this->store->addAdjustment($property, ['id' => $id, 'employee_id' => $employee['id'], 'leave_type_id' => $type['id'], 'year' => $year, 'days_delta' => $days, 'reason' => $reason, 'created_by' => $actor], $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'leave_balance.adjusted', 'leave_balance', $id, null, ['employee' => $employee['number'], 'type' => $type['code'], 'year' => $year, 'days' => $days], $reason));
        });

        return $this->balances($property, [$employee], [$type], $year)[0];
    }

    /**
     * What an offboarding does to leave: requests that are waiting, or that end after the last day, are cancelled and the days after it are freed (the roster is closed by the offboarding itself).
     *
     * @return array{cancelled: int, files: list<string>} how many requests, and the evidence files whose retention begins now
     */
    public function closeFor(PropertyId $property, string $employeeId, string $leftOn, string $actorId): array
    {
        $now = $this->clock->nowUtc();
        $cancelled = 0;

        foreach ($this->store->openEndingAfter($property, $employeeId, $leftOn) as $r) {
            $this->store->removeDays($property, $r['id'], date('Y-m-d', strtotime($leftOn.' +1 day')));
            $this->store->updateRequest($property, $r['id'], (int) $r['lock_version'], ['status' => 'cancelled'], $now);
            $cancelled++;
            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'leave.cancelled', 'leave', $r['id'], ['status' => $r['status']], ['status' => 'cancelled', 'employee' => $r['number'], 'type' => $r['type_code'], 'reason' => 'offboarding'], null, $r['approval_id']));
        }

        return ['cancelled' => $cancelled, 'files' => $this->store->evidenceFiles($property, $employeeId)];
    }

    public function evidence(PropertyId $property, string $actorId, string $id): FileContent
    {
        $this->access->assertProperty($property);
        $r = $this->store->request($property, strtolower($id)) ?? throw Refusal::notFound('Leave request not found.');
        $mine = $this->employees->employeeOfUser($property, strtolower($actorId));

        if ($r['evidence_file_id'] === null) {
            throw Refusal::notFound('This request has no paper.');
        }

        if ($mine !== $r['employee_id'] && ! $this->access->may($property, $actorId, HrAccess::LEAVE)) {
            throw Refusal::forbidden('This person may not open the paper.');
        }

        $policy = new class($this->permissions, $property, $mine === $r['employee_id'] ? strtolower($actorId) : null) implements FileAccessPolicy
        {
            public function __construct(private PermissionChecker $permissions, private PropertyId $property, private ?string $owner) {}

            public function allows(string $actorId, StoredFile $file): bool
            {
                return $this->owner === strtolower($actorId) || $this->permissions->allowsInProperty($actorId, HrAccess::LEAVE, $this->property);
            }
        };

        try {
            $content = $this->downloadFile->execute($property, (string) $r['evidence_file_id'], strtolower($actorId), $policy);
        } catch (StoredFileNotFound) {
            throw Refusal::notFound('The paper is no longer kept.');
        } catch (FileAccessDenied) {
            throw Refusal::forbidden('This person may not open the paper.');
        }

        $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'leave.evidence_opened', 'leave', $r['id'], null, ['employee' => $r['number'], 'type' => $r['type_code']]));

        return $content;
    }

    /**
     * @param  list<array<string, mixed>>  $people
     * @param  list<array<string, mixed>>  $types  the kinds to show; only those with a balance have one
     * @return list<array<string, mixed>>
     */
    private function balances(PropertyId $property, array $people, array $types, int $year): array
    {
        $deducting = array_values(array_filter($types, static fn (array $t): bool => (bool) $t['deducts_balance']));
        $taken = [];
        $pending = [];

        foreach ($this->store->daysTaken($property, $year, count($people) === 1 ? $people[0]['id'] : null) as $r) {
            if ($r['status'] === 'approved') {
                $taken[$r['employee_id'].'|'.$r['leave_type_id']] = (int) $r['days'];
            } else {
                $pending[$r['employee_id'].'|'.$r['leave_type_id']] = (int) $r['days'];
            }
        }

        $adjusted = [];

        foreach ($this->store->adjustments($property, $year, count($people) === 1 ? $people[0]['id'] : null) as $a) {
            $key = $a['employee_id'].'|'.$a['leave_type_id'];
            $adjusted[$key] = ($adjusted[$key] ?? 0) + (int) $a['days_delta'];
        }

        return array_map(function (array $e) use ($deducting, $taken, $pending, $adjusted, $year): array {
            $items = [];

            foreach ($deducting as $t) {
                $key = $e['id'].'|'.$t['id'];
                $entitled = $this->entitlement($e, $t, $year);
                $items[] = [
                    'type_id' => $t['id'], 'code' => $t['code'], 'name' => $t['name'], 'eligible_on' => $this->eligibleOn($e, $t), 'entitlement' => $entitled, 'adjusted' => $adjusted[$key] ?? 0,
                    'taken' => $taken[$key] ?? 0, 'pending' => $pending[$key] ?? 0, 'remaining' => $entitled + ($adjusted[$key] ?? 0) - ($taken[$key] ?? 0) - ($pending[$key] ?? 0),
                ];
            }

            return ['employee' => ['id' => $e['id'], 'number' => $e['number'], 'name' => $e['full_name'], 'department' => $e['department']], 'year' => $year, 'items' => $items];
        }, $people);
    }

    /** @param array<string, mixed> $employee @param array<string, mixed> $type */
    private function remaining(PropertyId $property, array $employee, array $type, int $year, ?int $excludePending): int
    {
        return $this->balances($property, [$employee], [$type], $year)[0]['items'][0]['remaining'] + ($excludePending ?? 0);
    }

    /** @param array<string, mixed> $employee @param array<string, mixed> $type */
    private function entitlement(array $employee, array $type, int $year): int
    {
        return $this->eligibleOn($employee, $type) <= "{$year}-12-31" ? (int) $type['entitlement_days'] : 0;
    }

    /** @param array<string, mixed> $employee @param array<string, mixed> $type */
    private function eligibleOn(array $employee, array $type): string
    {
        return date('Y-m-d', strtotime(substr((string) $employee['joined_on'], 0, 10).' +'.(int) $type['eligible_after_months'].' months'));
    }

    /** Calendar days from one day to the other, less the days the roster shows a day off. */
    private function countDays(PropertyId $property, string $employeeId, string $from, string $to): int
    {
        $n = 0;

        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d.' +1 day'))) {
            $entry = $this->roster->entry($property, $employeeId, $d);
            $n += $entry !== null && (bool) $entry['is_off'] ? 0 : 1;
        }

        return $n;
    }

    /** @return array<string, mixed> */
    private function payload(string $employeeId, string $typeId, string $from, string $to): array
    {
        return ['employee_id' => $employeeId, 'leave_type_id' => $typeId, 'from' => $from, 'to' => $to];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function snapshot(array $entry): array
    {
        $row = array_intersect_key($entry, array_flip(self::ENTRY_FIELDS));
        $row['is_off'] = (bool) $row['is_off'];
        $row['work_date'] = substr((string) $row['work_date'], 0, 10);

        return $row;
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function shape(PropertyId $property, string $actorId, array $r, string $today): array
    {
        $actor = strtolower($actorId);
        $from = substr((string) $r['from_date'], 0, 10);
        $approval = $r['approval_id'] === null ? null : $this->approvals->find($property, (string) $r['approval_id']);
        $open = in_array($r['status'], ['pending_approval', 'approved'], true);
        $manages = $this->access->may($property, $actorId, HrAccess::LEAVE);

        return [
            'id' => $r['id'], 'employee' => ['id' => $r['employee_id'], 'number' => $r['number'], 'name' => $r['full_name'], 'department' => $r['department']],
            'type' => ['code' => $r['type_code'], 'name' => $r['type_name'], 'deducts_balance' => (bool) $r['deducts_balance']], 'from_date' => $from, 'to_date' => substr((string) $r['to_date'], 0, 10), 'days' => (int) $r['days'],
            'reason' => $r['reason'], 'status' => $r['status'], 'has_evidence' => $r['evidence_file_id'] !== null, 'approval' => $approval === null ? null : ['id' => $approval->id, 'status' => $approval->status, 'consumed' => $approval->consumed],
            'may' => ['release' => $r['status'] === 'pending_approval' && $r['requested_by'] === $actor, 'cancel' => $open && ($r['requested_by'] === $actor || $manages) && ($r['status'] === 'pending_approval' || $from >= $today)],
        ];
    }

    /** @return array<string, mixed> */
    private function one(PropertyId $property, string $actorId, string $id): array
    {
        return $this->shape($property, $actorId, $this->store->request($property, $id) ?? throw Refusal::notFound('Leave request not found.'), $this->businessDate->current($property)->toString());
    }
}
