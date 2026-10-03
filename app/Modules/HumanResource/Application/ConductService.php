<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Modules\Property\Application\Settings\BusinessDateProvider;
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
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Reprimands, warning letters and awards (FR-HR-023). A record names the person, the kind (a spoken reprimand, a first, second or third warning letter, or an award), the day it was issued, the reason, the letter as a
 * private file, and for a warning the day until which it holds: by default 3 months for a reprimand and 6 months for a warning letter, which is the longest a warning letter holds. A record is never deleted; one made by
 * mistake is revoked with a reason. The person sees their own records and the letters; people with the right see all of them. Warnings are active until their day passes.
 */
final readonly class ConductService
{
    public const KINDS = ['verbal', 'sp1', 'sp2', 'sp3', 'award'];

    /** Months a record holds when no day is given; a warning letter holds at most 6. @var array<string, int> */
    public const MONTHS = ['verbal' => 3, 'sp1' => 6, 'sp2' => 6, 'sp3' => 6];

    public const MAX_WARNING_MONTHS = 6;

    public const BACK_DAYS = 60;

    public const MAX_BYTES = 3_145_728;

    public function __construct(
        private ConductStore $store,
        private EmployeeStore $employees,
        private HrAccess $access,
        private PermissionChecker $permissions,
        private StoreFile $storeFile,
        private DownloadFile $downloadFile,
        private BusinessDateProvider $businessDate,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId, ?string $employeeId): array
    {
        $this->access->assertProperty($property);
        $actor = strtolower($actorId);
        $today = $this->businessDate->current($property)->toString();
        $manage = $this->access->may($property, $actorId, HrAccess::CONDUCT);
        $me = $this->employees->employeeOfUser($property, $actor);
        $filter = $employeeId === null || $employeeId === '' ? null : strtolower($employeeId);

        return [
            'today' => $today, 'kinds' => self::KINDS, 'may' => ['manage' => $manage], 'linked' => $me !== null,
            'mine' => $me === null ? [] : array_map(fn (array $r): array => $this->shape($property, $actor, $r, $today, $manage), $this->store->list($property, $me, 100)),
            'records' => $manage ? array_map(fn (array $r): array => $this->shape($property, $actor, $r, $today, true), $this->store->list($property, $filter, 300)) : null,
            'employees' => $manage ? array_map(static fn (array $e): array => ['id' => $e['id'], 'number' => $e['number'], 'name' => $e['full_name'], 'department' => $e['department']], $this->employees->employees($property, 'active')) : null,
            'default_months' => self::MONTHS, 'selected' => $filter,
        ];
    }

    /** @return array<string, mixed> */
    public function create(PropertyId $property, string $actorId, string $employeeId, string $kind, string $issuedOn, ?string $validUntil, string $reason, ?string $letter, ?string $letterName): array
    {
        $this->access->require($property, $actorId, HrAccess::CONDUCT, 'This person may not record reprimands, warnings or awards.');
        $actor = strtolower($actorId);
        $today = $this->businessDate->current($property)->toString();
        $reason = trim($reason);

        if (! in_array($kind, self::KINDS, true)) {
            throw Refusal::invalid('Choose a kind of the list.', ['kind']);
        }

        if (! ShiftTimes::isDate($issuedOn) || $issuedOn > $today || $issuedOn < date('Y-m-d', strtotime($today.' -'.self::BACK_DAYS.' days'))) {
            throw Refusal::invalid('The day it was issued is today or at most '.self::BACK_DAYS.' days back.', ['issued_on']);
        }

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw Refusal::invalid('Give the reason in at most 500 characters.', ['reason']);
        }

        if ($kind === 'award') {
            $until = null;
        } else {
            $until = $validUntil === null || $validUntil === '' ? date('Y-m-d', strtotime($issuedOn.' +'.self::MONTHS[$kind].' months')) : $validUntil;

            if (! ShiftTimes::isDate($until) || $until < $issuedOn || $until > date('Y-m-d', strtotime($issuedOn.' +'.self::MAX_WARNING_MONTHS.' months'))) {
                throw Refusal::invalid('A warning holds from the day it was issued for at most '.self::MAX_WARNING_MONTHS.' months.', ['valid_until']);
            }
        }

        $employee = $this->employees->employee($property, strtolower($employeeId)) ?? throw Refusal::notFound('Employee not found.');

        if ($employee['status'] !== 'active') {
            throw Refusal::stateConflict('This person has left.');
        }

        if ($issuedOn < substr((string) $employee['joined_on'], 0, 10)) {
            throw Refusal::invalid('This person had not joined yet.', ['issued_on']);
        }

        $file = null;

        if ($letter !== null && $letter !== '') {
            try {
                $file = $this->storeFile->execute(new FileUpload($property, $actor, DocumentService::PURPOSE, 'employee', strtolower($employeeId), $letter, new FilePolicy(['application/pdf', 'image/jpeg', 'image/png'], self::MAX_BYTES, FileSensitivity::Sensitive, false), $letterName));
            } catch (FileRejected $e) {
                throw Refusal::invalid($e->getMessage(), ['letter']);
            }
        }

        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actor, $employee, $kind, $issuedOn, $until, $reason, $file, $id): void {
            $this->store->add($property, ['id' => $id, 'employee_id' => $employee['id'], 'kind' => $kind, 'issued_on' => $issuedOn, 'valid_until' => $until, 'reason' => $reason, 'file_id' => $file?->id, 'status' => 'issued', 'issued_by' => $actor], $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'conduct.recorded', 'conduct_record', $id, null, ['employee' => $employee['number'], 'kind' => $kind, 'issued_on' => $issuedOn, 'valid_until' => $until, 'letter' => $file !== null], $reason));
            $this->outbox->publish(new OutboxEvent($property, 'hr.conduct.recorded', $id, 1, ['record_id' => $id, 'employee_id' => $employee['id'], 'kind' => $kind, 'issued_on' => $issuedOn]));
        });

        return $this->one($property, $actorId, $id);
    }

    /** A record made by mistake. @return array<string, mixed> */
    public function revoke(PropertyId $property, string $actorId, string $id, string $reason, int $lock): array
    {
        $this->access->require($property, $actorId, HrAccess::CONDUCT, 'This person may not revoke a record.');
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Give the reason in at most 200 characters.', ['reason']);
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $reason, $lock): void {
            $r = $this->store->find($property, strtolower($id)) ?? throw Refusal::notFound('Record not found.');

            if ($r['status'] === 'revoked') {
                throw Refusal::stateConflict('This record was revoked already.');
            }

            $now = $this->clock->nowUtc();

            if (! $this->store->update($property, $r['id'], $lock, ['status' => 'revoked', 'revoke_reason' => $reason, 'revoked_by' => $actor, 'revoked_at' => $now->format('Y-m-d H:i:s.u')], $now)) {
                throw Refusal::stateConflict('This record changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'conduct.revoked', 'conduct_record', $r['id'], ['status' => 'issued'], ['status' => 'revoked', 'employee' => $r['number'], 'kind' => $r['kind']], $reason));
        });

        return $this->one($property, $actorId, $id);
    }

    public function letter(PropertyId $property, string $actorId, string $id): FileContent
    {
        $this->access->assertProperty($property);
        $actor = strtolower($actorId);
        $r = $this->store->find($property, strtolower($id)) ?? throw Refusal::notFound('Record not found.');

        if ($r['file_id'] === null) {
            throw Refusal::notFound('This record has no letter.');
        }

        $owner = $r['user_id'] !== null && strtolower((string) $r['user_id']) === $actor;

        if (! $owner && ! $this->access->may($property, $actorId, HrAccess::CONDUCT)) {
            throw Refusal::forbidden('This person may not open the letter.');
        }

        $policy = new class($this->permissions, $property, $owner ? $actor : null) implements FileAccessPolicy
        {
            public function __construct(private PermissionChecker $permissions, private PropertyId $property, private ?string $owner) {}

            public function allows(string $actorId, StoredFile $file): bool
            {
                return $this->owner === strtolower($actorId) || $this->permissions->allowsInProperty($actorId, HrAccess::CONDUCT, $this->property);
            }
        };

        try {
            $content = $this->downloadFile->execute($property, (string) $r['file_id'], $actor, $policy);
        } catch (StoredFileNotFound) {
            throw Refusal::notFound('The letter is no longer kept.');
        } catch (FileAccessDenied) {
            throw Refusal::forbidden('This person may not open the letter.');
        }

        $this->audit->record(new AuditEntry($property->toString(), $actor, 'conduct.letter_opened', 'conduct_record', $r['id'], null, ['employee' => $r['number'], 'kind' => $r['kind']]));

        return $content;
    }

    /** The warnings of the person that are active on a day. @return list<array<string, mixed>> */
    public function activeWarnings(PropertyId $property, string $employeeId, string $today): array
    {
        $this->access->assertProperty($property);

        return array_values(array_filter($this->store->list($property, $employeeId, 100), static fn (array $r): bool => $r['kind'] !== 'award' && $r['status'] === 'issued' && (string) $r['valid_until'] >= $today));
    }

    /** @return array<string, mixed> */
    private function one(PropertyId $property, string $actorId, string $id): array
    {
        return $this->shape($property, strtolower($actorId), $this->store->find($property, strtolower($id)) ?? throw Refusal::notFound('Record not found.'), $this->businessDate->current($property)->toString(), $this->access->may($property, $actorId, HrAccess::CONDUCT));
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    private function shape(PropertyId $property, string $actor, array $r, string $today, bool $manage): array
    {
        $until = $r['valid_until'] === null ? null : substr((string) $r['valid_until'], 0, 10);
        $state = $r['status'] === 'revoked' ? 'revoked' : ($r['kind'] === 'award' ? 'award' : ($until !== null && $until < $today ? 'expired' : 'active'));

        return [
            'id' => $r['id'], 'employee' => ['id' => $r['employee_id'], 'number' => $r['number'], 'name' => $r['full_name'], 'department' => $r['department']], 'kind' => $r['kind'], 'issued_on' => substr((string) $r['issued_on'], 0, 10), 'valid_until' => $until, 'state' => $state,
            'reason' => $r['reason'], 'has_letter' => $r['file_id'] !== null, 'revoke_reason' => $r['revoke_reason'], 'lock_version' => (int) $r['lock_version'], 'may' => ['revoke' => $manage && $r['status'] === 'issued'],
        ];
    }
}
