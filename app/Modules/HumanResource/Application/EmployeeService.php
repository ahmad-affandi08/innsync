<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Documents\DocumentNumbers;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Files\StoredFileRepository;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Retention\RetentionPolicies;
use App\Shared\Application\Security\StaffAccess;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\DateMath;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The people who work in the property (FR-HR-001, -003, -005). A manager keeps the employee records (number, name, department, position, the day they joined, the kind of contract
 * and when it ends, the direct supervisor, and the account they sign in with), warns of contracts and papers about to lapse, and offboards people who leave: the person's access to the
 * property ends, the things they had to hand back are recorded, their papers start their retention period, and the record stays, with everything that names it.
 */
final readonly class EmployeeService
{
    public const DEPARTMENTS = ['front_office', 'housekeeping', 'laundry', 'fnb', 'kitchen', 'maintenance', 'security', 'purchasing', 'finance', 'hr', 'management', 'general'];

    public const CONTRACTS = ['permanent', 'contract', 'probation', 'daily', 'intern'];

    public const OFFBOARD_KINDS = ['resigned', 'terminated', 'contract_ended', 'retired', 'other'];

    public const BASELINE_WARN_DAYS = 30;

    public const BASELINE_REQUIRED = ['contract', 'identity'];

    private const MAX_ITEMS = 20;

    public function __construct(
        private EmployeeStore $store,
        private HrAccess $access,
        private StaffDirectory $staff,
        private StaffAccess $staffAccess,
        private RosterService $roster,
        private LeaveService $leave,
        private FaceService $faces,
        private ConductStore $conduct,
        private BusinessDateProvider $businessDate,
        private DocumentNumbers $numbers,
        private StoredFileRepository $files,
        private RetentionPolicies $retention,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId, ?string $status): array
    {
        $caps = $this->capabilities($property, $actorId);

        if ($status !== null && $status !== '' && ! in_array($status, ['active', 'offboarded'], true)) {
            throw Refusal::invalid('Choose active or offboarded.', ['status']);
        }

        $rows = $this->store->employees($property, $status === '' ? null : $status);
        $names = $this->staff->namesOf($property, array_values(array_filter(array_column($rows, 'user_id'))));
        $linked = array_filter(array_column($rows, 'user_id'));

        return [
            'employees' => array_map(fn (array $e): array => $this->head($e, $names), $rows),
            'counts' => ['active' => count(array_filter($rows, static fn (array $e): bool => $e['status'] === 'active')), 'offboarded' => count(array_filter($rows, static fn (array $e): bool => $e['status'] === 'offboarded'))],
            'warnings' => $caps['manage'] || $caps['documents'] ? $this->warnings($property) : [],
            'settings' => $this->settings($property),
            'departments' => self::DEPARTMENTS, 'contracts' => self::CONTRACTS, 'offboard_kinds' => self::OFFBOARD_KINDS, 'document_kinds' => DocumentService::KINDS,
            'accounts' => $caps['manage'] ? array_values(array_filter($this->staff->members($property), static fn (array $m): bool => ! in_array($m['id'], $linked, true))) : [],
            'business_date' => $this->businessDate->current($property)->toString(),
            'may' => $caps,
        ];
    }

    /** @return array<string, mixed> */
    public function show(PropertyId $property, string $actorId, string $id): array
    {
        $caps = $this->capabilities($property, $actorId);
        $e = $this->employee($property, $id);
        $names = $this->staff->namesOf($property, array_values(array_filter([$e['user_id'], $e['offboarded_by'], $e['created_by']])));
        $items = $this->store->offboardItems($property, $e['id']);

        return [
            ...$this->head($e, $names),
            'subordinates' => count($this->store->subordinatesOf($property, $e['id'])), 'offboard_reason' => $e['offboard_reason'], 'offboarded_by' => $names[$e['offboarded_by'] ?? ''] ?? null,
            'offboard_items' => array_map(static fn (array $i): array => ['item' => $i['item'], 'returned' => (bool) $i['returned'], 'note' => $i['note']], $items),
            'supervisors' => $caps['manage'] ? array_values(array_map(static fn (array $s): array => ['id' => $s['id'], 'number' => $s['number'], 'name' => $s['full_name']], array_filter($this->store->employees($property, 'active'), static fn (array $s): bool => $s['id'] !== $e['id']))) : [],
            'may' => ['edit' => $caps['manage'] && $e['status'] === 'active', 'offboard' => $caps['manage'] && $e['status'] === 'active', 'documents' => $caps['documents']],
        ];
    }

    /** @return array<string, mixed> */
    public function create(PropertyId $property, string $actorId, array $v): array
    {
        $this->access->require($property, $actorId, HrAccess::MANAGE, 'This person may not write employee records.');
        $clean = $this->clean($property, $v, null);
        $actor = strtolower($actorId);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actor, $id, $clean): void {
            $number = $this->numbers->next($property, 'EMP');

            if (! $this->store->addEmployee($property, [...$clean, 'id' => $id, 'number' => $number, 'created_by' => $actor], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This account or number belongs to another employee. Try again.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'employee.created', 'employee', $id, null, ['number' => $number, 'department' => $clean['department'], 'position' => $clean['position'], 'contract_type' => $clean['contract_type'], 'joined_on' => $clean['joined_on'], 'has_account' => $clean['user_id'] !== null]));
        });

        return $this->show($property, $actorId, $id);
    }

    /** @return array<string, mixed> */
    public function update(PropertyId $property, string $actorId, string $id, array $v, int $lock): array
    {
        $this->access->require($property, $actorId, HrAccess::MANAGE, 'This person may not write employee records.');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $v, $lock): void {
            $this->store->lockEmployee($property, strtolower($id));
            $before = $this->employee($property, $id);
            $clean = $this->clean($property, $v, $before);

            if ($before['status'] !== 'active') {
                throw Refusal::stateConflict('This person has left. Their record is no longer changed.');
            }

            if (! $this->store->updateEmployee($property, $before['id'], $lock, $clean, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This record changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'employee.changed', 'employee', $before['id'], $this->audited($before), ['number' => $before['number'], ...$this->audited($clean)]));
        });

        return $this->show($property, $actorId, $id);
    }

    /**
     * @param  list<array{item: string, returned: bool, note?: string|null}>  $items
     * @return array<string, mixed>
     */
    public function offboard(PropertyId $property, string $actorId, string $id, string $kind, string $on, ?string $reason, array $items, ?string $reassignTo, int $lock): array
    {
        $this->access->require($property, $actorId, HrAccess::MANAGE, 'This person may not offboard employees.');
        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);

        if (! in_array($kind, self::OFFBOARD_KINDS, true)) {
            throw Refusal::invalid('Choose why the person leaves.', ['kind']);
        }

        if ($reason !== null && mb_strlen($reason) > 200) {
            throw Refusal::invalid('The reason is at most 200 characters.', ['reason']);
        }

        if (in_array($kind, ['terminated', 'other'], true) && $reason === null) {
            throw Refusal::invalid('Say what happened.', ['reason']);
        }

        $today = $this->businessDate->current($property)->toString();

        if (! $this->isDate($on) || $on > $today) {
            throw Refusal::invalid('Give the day the person left, not after today.', ['offboarded_on']);
        }

        if (count($items) > self::MAX_ITEMS) {
            throw Refusal::invalid('At most '.self::MAX_ITEMS.' things to hand back.', ['items']);
        }

        $clean = [];

        foreach ($items as $i) {
            $text = trim((string) ($i['item'] ?? ''));
            $note = isset($i['note']) && trim((string) $i['note']) !== '' ? trim((string) $i['note']) : null;

            if ($text === '' || mb_strlen($text) > 120 || ($note !== null && mb_strlen($note) > 200)) {
                throw Refusal::invalid('Name each thing to hand back in at most 120 characters, with a note of at most 200.', ['items']);
            }

            $clean[] = ['item' => $text, 'returned' => (bool) ($i['returned'] ?? false), 'note' => $note];
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $kind, $on, $reason, $clean, $reassignTo, $lock): void {
            $this->store->lockEmployee($property, strtolower($id));
            $e = $this->employee($property, $id);

            if ($e['status'] !== 'active') {
                throw Refusal::stateConflict('This person has left already.');
            }

            if ($on < substr((string) $e['joined_on'], 0, 10)) {
                throw Refusal::invalid('The person cannot leave before they joined.', ['offboarded_on']);
            }

            $team = $this->store->subordinatesOf($property, $e['id']);
            $now = $this->clock->nowUtc();

            if ($team !== []) {
                $target = $reassignTo === null || $reassignTo === '' ? null : $this->store->employee($property, strtolower($reassignTo));

                if ($target === null || $target['status'] !== 'active' || $target['id'] === $e['id']) {
                    throw Refusal::invalid('Choose who supervises their '.count($team).' people from now on.', ['reassign_to']);
                }

                $this->store->reassign($property, $e['id'], $target['id'], $now);
            }

            if (! $this->store->updateEmployee($property, $e['id'], $lock, ['status' => 'offboarded', 'offboarded_on' => $on, 'offboard_kind' => $kind, 'offboard_reason' => $reason, 'offboarded_by' => $actor], $now)) {
                throw Refusal::stateConflict('This record changed after you opened it. Reload it.');
            }

            foreach ($clean as $i) {
                $this->store->addOffboardItem($property, ['id' => $this->ids->next(), 'employee_id' => $e['id'], ...$i], $now);
            }

            $closed = $this->roster->closeFor($property, $e['id'], $on);
            $leave = $this->leave->closeFor($property, $e['id'], $on, $actor);
            $revoked = $e['user_id'] === null ? 0 : $this->staffAccess->revokeInProperty($property, (string) $e['user_id']);
            $this->faces->erase($property, $actor, $e['id'], 'Left the property');
            $anchor = new DateTimeImmutable($on.' 00:00:00', new DateTimeZone('UTC'));

            foreach ([...$this->store->fileIdsOf($property, $e['id']), ...$leave['files'], ...$this->conduct->fileIdsOf($property, $e['id'])] as $fileId) {
                $this->files->setExpiryOnce($property, $fileId, $this->retention->expiryFor($property, DocumentService::PURPOSE, $anchor));
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'employee.offboarded', 'employee', $e['id'], ['status' => 'active'], [
                'status' => 'offboarded', 'number' => $e['number'], 'kind' => $kind, 'offboarded_on' => $on, 'access_ended' => $revoked, 'items' => count($clean), 'not_returned' => count(array_filter($clean, static fn (array $i): bool => ! $i['returned'])), 'reassigned' => count($team), 'shifts_closed' => $closed, 'leave_cancelled' => $leave['cancelled'],
            ], $reason));
        });

        return $this->show($property, $actorId, $id);
    }

    /** @return array{warn_days: int, required_kinds: list<string>, is_baseline: bool, lock_version: int|null} */
    public function settings(PropertyId $property): array
    {
        $row = $this->store->settings($property);

        return ['warn_days' => (int) ($row['expiry_warn_days'] ?? self::BASELINE_WARN_DAYS), 'required_kinds' => $row === null ? self::BASELINE_REQUIRED : array_values(array_filter(explode(',', (string) $row['required_kinds']))), 'is_baseline' => $row === null, 'lock_version' => $row === null ? null : (int) $row['lock_version']];
    }

    /**
     * @param  list<string>  $requiredKinds
     * @return array{warn_days: int, required_kinds: list<string>, is_baseline: bool, lock_version: int|null}
     */
    public function saveSettings(PropertyId $property, string $actorId, int $warnDays, array $requiredKinds, ?int $lock): array
    {
        $this->access->require($property, $actorId, HrAccess::MANAGE, 'This person may not set the warning period.');

        if ($warnDays < 1 || $warnDays > 365) {
            throw Refusal::invalid('Warn from 1 to 365 days before.', ['warn_days']);
        }

        $kinds = array_values(array_unique($requiredKinds));

        if (array_diff($kinds, DocumentService::KINDS) !== []) {
            throw Refusal::invalid('Choose kinds of paper from the list.', ['required_kinds']);
        }

        $before = $this->settings($property);
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $warnDays, $kinds, $lock, $before): void {
            if ($before['lock_version'] !== $lock || ! $this->store->saveSettings($property, ['warn_days' => $warnDays, 'required_kinds' => $kinds], $before['lock_version'], $actor, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('These settings changed after you opened them. Reload them.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'hr_settings.changed', 'hr_settings', $property->toString(), ['warn_days' => $before['warn_days'], 'required_kinds' => $before['required_kinds']], ['warn_days' => $warnDays, 'required_kinds' => $kinds]));
        });

        return $this->settings($property);
    }

    /**
     * Contracts and papers about to lapse or lapsed, and the papers an employee must have and does not: for the people still working.
     *
     * @return list<array<string, mixed>> the most urgent first
     */
    public function warnings(PropertyId $property): array
    {
        $settings = $this->settings($property);
        $today = $this->businessDate->current($property)->toString();
        $until = DateMath::format('Y-m-d', $today.' +'.$settings['warn_days'].' days');
        $out = [];
        $active = $this->store->employees($property, 'active');

        foreach ($active as $e) {
            if ($e['contract_end_on'] !== null && substr((string) $e['contract_end_on'], 0, 10) <= $until) {
                $out[] = $this->warning('contract_end', $e, null, substr((string) $e['contract_end_on'], 0, 10), $today);
            }
        }

        $byId = array_column($active, null, 'id');

        foreach ($this->store->expiringDocuments($property, $until) as $d) {
            $out[] = $this->warning('document', $byId[$d['employee_id']] ?? $d, $d, substr((string) $d['valid_until'], 0, 10), $today);
        }

        $kinds = $this->store->currentKinds($property);

        foreach ($active as $e) {
            foreach (array_diff($settings['required_kinds'], $kinds[$e['id']] ?? []) as $missing) {
                $out[] = ['type' => 'missing', 'status' => 'missing', 'employee_id' => $e['id'], 'number' => $e['number'], 'name' => $e['full_name'], 'department' => $e['department'], 'kind' => $missing, 'title' => null, 'date' => null, 'days' => null];
            }
        }

        usort($out, static fn (array $a, array $b): int => [$a['date'] ?? '0000-00-00', $a['name']] <=> [$b['date'] ?? '0000-00-00', $b['name']]);

        return $out;
    }

    /**
     * @param  array<string, mixed>  $e
     * @param  array<string, mixed>|null  $d
     * @return array<string, mixed>
     */
    private function warning(string $type, array $e, ?array $d, string $date, string $today): array
    {
        $days = DateMath::daysBetween($today, $date);

        return [
            'type' => $type, 'status' => $days < 0 ? 'expired' : 'expiring', 'employee_id' => $e['employee_id'] ?? $e['id'], 'number' => $e['number'], 'name' => $e['full_name'], 'department' => $e['department'],
            'kind' => $d['kind'] ?? 'contract', 'title' => $d['title'] ?? null, 'date' => $date, 'days' => $days,
        ];
    }

    /**
     * @param  array<string, mixed>  $v
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>
     */
    private function clean(PropertyId $property, array $v, ?array $existing): array
    {
        $name = trim((string) ($v['full_name'] ?? ''));
        $position = trim((string) ($v['position'] ?? ''));
        $phone = isset($v['phone']) && trim((string) $v['phone']) !== '' ? trim((string) $v['phone']) : null;
        $email = isset($v['email']) && trim((string) $v['email']) !== '' ? trim((string) $v['email']) : null;
        $joined = (string) ($v['joined_on'] ?? '');
        $contract = (string) ($v['contract_type'] ?? '');
        $end = isset($v['contract_end_on']) && $v['contract_end_on'] !== '' ? (string) $v['contract_end_on'] : null;
        $today = $this->businessDate->current($property)->toString();

        if ($name === '' || mb_strlen($name) > 120) {
            throw Refusal::invalid('Give the full name in at most 120 characters.', ['full_name']);
        }

        if (! in_array($v['department'] ?? null, self::DEPARTMENTS, true)) {
            throw Refusal::invalid('Choose the department.', ['department']);
        }

        if ($position === '' || mb_strlen($position) > 80) {
            throw Refusal::invalid('Give the position in at most 80 characters.', ['position']);
        }

        if (! $this->isDate($joined) || $joined > DateMath::format('Y-m-d', $today.' +90 days')) {
            throw Refusal::invalid('Give the day the person joins, at most 90 days ahead.', ['joined_on']);
        }

        if (! in_array($contract, self::CONTRACTS, true)) {
            throw Refusal::invalid('Choose the kind of contract.', ['contract_type']);
        }

        if ($contract === 'permanent' && $end !== null) {
            throw Refusal::invalid('A permanent contract has no end date.', ['contract_end_on']);
        }

        if ($contract !== 'permanent' && ($end === null || ! $this->isDate($end) || $end < $joined)) {
            throw Refusal::invalid('Give the day the contract ends, not before the person joins.', ['contract_end_on']);
        }

        if ($phone !== null && preg_match('/^[0-9+\-() ]{5,30}$/', $phone) !== 1) {
            throw Refusal::invalid('Give a phone number of digits, spaces and + - ( ).', ['phone']);
        }

        if ($email !== null && (mb_strlen($email) > 120 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            throw Refusal::invalid('Give a valid e-mail address.', ['email']);
        }

        $supervisorId = isset($v['supervisor_id']) && $v['supervisor_id'] !== '' ? strtolower((string) $v['supervisor_id']) : null;

        if ($supervisorId !== null) {
            $sup = $this->store->employee($property, $supervisorId);

            if ($sup === null || $sup['status'] !== 'active') {
                throw Refusal::invalid('Choose a supervisor who is working here.', ['supervisor_id']);
            }

            // Nobody supervises themselves, directly or through the people above them.
            for ($up = $sup, $depth = 0; $up !== null && $depth < 50; $depth++) {
                if ($existing !== null && $up['id'] === $existing['id']) {
                    throw Refusal::invalid('That person is under this one, so cannot be their supervisor.', ['supervisor_id']);
                }

                $up = $up['supervisor_id'] === null ? null : $this->store->employee($property, $up['supervisor_id']);
            }
        }

        $userId = isset($v['user_id']) && $v['user_id'] !== '' ? strtolower((string) $v['user_id']) : null;

        if ($userId !== null) {
            if (! in_array($userId, array_column($this->staff->members($property), 'id'), true)) {
                throw Refusal::invalid('Choose an account of someone who works in this property.', ['user_id']);
            }

            $owner = $this->store->employeeOfUser($property, $userId);

            if ($owner !== null && ($existing === null || $owner !== $existing['id'])) {
                throw Refusal::invalid('This account belongs to another employee.', ['user_id']);
            }
        }

        return ['full_name' => $name, 'department' => $v['department'], 'position' => $position, 'joined_on' => $joined, 'contract_type' => $contract, 'contract_end_on' => $end, 'supervisor_id' => $supervisorId, 'user_id' => $userId, 'phone' => $phone, 'email' => $email];
    }

    /**
     * What an audit entry keeps of a record: the facts of the job, not the contact details.
     *
     * @param  array<string, mixed>  $e
     * @return array<string, mixed>
     */
    private function audited(array $e): array
    {
        return ['department' => $e['department'], 'position' => $e['position'], 'joined_on' => substr((string) $e['joined_on'], 0, 10), 'contract_type' => $e['contract_type'], 'contract_end_on' => $e['contract_end_on'] === null ? null : substr((string) $e['contract_end_on'], 0, 10), 'supervisor_id' => $e['supervisor_id'], 'has_account' => $e['user_id'] !== null];
    }

    /** @return array<string, mixed> */
    private function employee(PropertyId $property, string $id): array
    {
        return $this->store->employee($property, strtolower($id)) ?? throw Refusal::notFound('Employee not found.');
    }

    /**
     * @param  array<string, mixed>  $e
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    private function head(array $e, array $names): array
    {
        return [
            'id' => $e['id'], 'number' => $e['number'], 'full_name' => $e['full_name'], 'department' => $e['department'], 'position' => $e['position'], 'joined_on' => substr((string) $e['joined_on'], 0, 10),
            'contract_type' => $e['contract_type'], 'contract_end_on' => $e['contract_end_on'] === null ? null : substr((string) $e['contract_end_on'], 0, 10), 'supervisor_id' => $e['supervisor_id'], 'supervisor' => $e['supervisor_name'] ?? null,
            'user_id' => $e['user_id'], 'account' => $names[$e['user_id'] ?? ''] ?? null, 'phone' => $e['phone'], 'email' => $e['email'], 'status' => $e['status'],
            'offboarded_on' => $e['offboarded_on'] === null ? null : substr((string) $e['offboarded_on'], 0, 10), 'offboard_kind' => $e['offboard_kind'], 'lock_version' => (int) $e['lock_version'],
        ];
    }

    /** @return array{view: bool, manage: bool, documents: bool} */
    private function capabilities(PropertyId $property, string $actorId): array
    {
        $this->access->assertProperty($property);
        $manage = $this->access->may($property, $actorId, HrAccess::MANAGE);
        $documents = $this->access->may($property, $actorId, HrAccess::DOCUMENTS);
        $view = $manage || $documents || $this->access->may($property, $actorId, HrAccess::VIEW);

        if (! $view) {
            throw Refusal::forbidden('This person may not see the employees.');
        }

        return ['view' => $view, 'manage' => $manage, 'documents' => $documents];
    }

    private function isDate(string $v): bool
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v, new DateTimeZone('UTC'));

        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
