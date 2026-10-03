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
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The personnel papers (FR-HR-002): the contract, the identity card, certificates and the medical checks the work requires, each with the dates it holds. They are sensitive: only
 * people with the privilege for papers see them or open the file, and every time one is added or opened it is audited. A newer paper of the same kind replaces an older one, which
 * stays on record. Nothing is deleted; the files are kept for the retention period that starts when the person leaves.
 */
final readonly class DocumentService
{
    public const KINDS = ['contract', 'identity', 'certificate', 'medical', 'other'];

    public const PURPOSE = 'hr_personnel_document';

    public const MAX_BYTES = 5_242_880;

    public function __construct(
        private EmployeeStore $store,
        private HrAccess $access,
        private StaffDirectory $staff,
        private StoreFile $storeFile,
        private DownloadFile $downloadFile,
        private PermissionChecker $permissions,
        private BusinessDateProvider $businessDate,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return list<array<string, mixed>> */
    public function list(PropertyId $property, string $actorId, string $employeeId): array
    {
        $this->access->require($property, $actorId, HrAccess::DOCUMENTS, 'This person may not see personnel papers.');
        $employee = $this->store->employee($property, strtolower($employeeId)) ?? throw Refusal::notFound('Employee not found.');
        $rows = $this->store->documents($property, $employee['id']);
        $names = $this->staff->namesOf($property, array_values(array_unique(array_column($rows, 'uploaded_by'))));
        $today = $this->businessDate->current($property)->toString();

        return array_map(static fn (array $d): array => [
            'id' => $d['id'], 'kind' => $d['kind'], 'title' => $d['title'], 'issued_on' => $d['issued_on'] === null ? null : substr((string) $d['issued_on'], 0, 10), 'valid_until' => $d['valid_until'] === null ? null : substr((string) $d['valid_until'], 0, 10),
            'current' => (bool) $d['is_current'], 'expired' => (bool) $d['is_current'] && $d['valid_until'] !== null && substr((string) $d['valid_until'], 0, 10) < $today, 'by' => $names[$d['uploaded_by']] ?? null,
            'at' => (new DateTimeImmutable((string) $d['created_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
        ], $rows);
    }

    /** @return list<array<string, mixed>> the papers after the new one */
    public function add(PropertyId $property, string $actorId, string $employeeId, string $kind, string $title, ?string $issuedOn, ?string $validUntil, ?string $replacesId, ?string $contents, ?string $name): array
    {
        $this->access->require($property, $actorId, HrAccess::DOCUMENTS, 'This person may not add personnel papers.');
        $title = trim($title);

        if (! in_array($kind, self::KINDS, true)) {
            throw Refusal::invalid('Choose the kind of paper.', ['kind']);
        }

        if ($title === '' || mb_strlen($title) > 120) {
            throw Refusal::invalid('Name the paper in at most 120 characters.', ['title']);
        }

        foreach ([[$issuedOn, 'issued_on'], [$validUntil, 'valid_until']] as [$date, $field]) {
            if ($date !== null && ! $this->isDate($date)) {
                throw Refusal::invalid('Give the date as year-month-day.', [$field]);
            }
        }

        if ($issuedOn !== null && $validUntil !== null && $validUntil < $issuedOn) {
            throw Refusal::invalid('A paper cannot hold until before it was issued.', ['valid_until']);
        }

        if ($contents === null || $contents === '') {
            throw Refusal::invalid('Attach the file of the paper.', ['file']);
        }

        $actor = strtolower($actorId);
        $id = $this->ids->next();
        $employee = $this->store->employee($property, strtolower($employeeId)) ?? throw Refusal::notFound('Employee not found.');

        if ($employee['status'] !== 'active') {
            throw Refusal::stateConflict('This person has left. No paper is added to their record.');
        }

        $old = null;

        if ($replacesId !== null && $replacesId !== '') {
            $old = $this->store->document($property, strtolower($replacesId));

            if ($old === null || $old['employee_id'] !== $employee['id'] || ! (bool) $old['is_current']) {
                throw Refusal::invalid('Choose a current paper of this person to replace.', ['replaces_id']);
            }
        }

        try {
            $file = $this->storeFile->execute(new FileUpload($property, $actor, self::PURPOSE, 'employee', $employee['id'], $contents, new FilePolicy(['application/pdf', 'image/jpeg', 'image/png'], self::MAX_BYTES, FileSensitivity::Sensitive, false), $name));
        } catch (FileRejected $e) {
            throw Refusal::invalid($e->getMessage(), ['file']);
        }

        $this->transactions->run(function () use ($property, $actor, $id, $employee, $kind, $title, $issuedOn, $validUntil, $old, $file): void {
            $now = $this->clock->nowUtc();
            $this->store->lockEmployee($property, $employee['id']);
            $this->store->addDocument($property, ['id' => $id, 'employee_id' => $employee['id'], 'kind' => $kind, 'title' => $title, 'issued_on' => $issuedOn, 'valid_until' => $validUntil, 'file_id' => $file->id, 'uploaded_by' => $actor], $now);

            if ($old !== null) {
                $this->store->retireDocument($property, $old['id'], $now);
            }

            // The audit trail names the paper and the employee's number, never its content.
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'hr_document.added', 'hr_document', $id, null, ['employee' => $employee['number'], 'kind' => $kind, 'title' => $title, 'valid_until' => $validUntil, 'replaces' => $old['id'] ?? null]));
        });

        return $this->list($property, $actorId, $employee['id']);
    }

    public function download(PropertyId $property, string $actorId, string $documentId): FileContent
    {
        $this->access->require($property, $actorId, HrAccess::DOCUMENTS, 'This person may not open personnel papers.');
        $doc = $this->store->document($property, strtolower($documentId)) ?? throw Refusal::notFound('Paper not found.');
        $employee = $this->store->employee($property, $doc['employee_id']);

        $policy = new class($this->permissions, $property) implements FileAccessPolicy
        {
            public function __construct(private PermissionChecker $permissions, private PropertyId $property) {}

            public function allows(string $actorId, StoredFile $file): bool
            {
                return $this->permissions->allowsInProperty($actorId, HrAccess::DOCUMENTS, $this->property);
            }
        };

        try {
            $content = $this->downloadFile->execute($property, (string) $doc['file_id'], strtolower($actorId), $policy);
        } catch (StoredFileNotFound) {
            throw Refusal::notFound('The file is no longer kept.');
        } catch (FileAccessDenied) {
            throw Refusal::forbidden('This person may not open personnel papers.');
        }

        $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'hr_document.opened', 'hr_document', $doc['id'], null, ['employee' => $employee['number'] ?? null, 'kind' => $doc['kind']]));

        return $content;
    }

    private function isDate(string $v): bool
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v, new DateTimeZone('UTC'));

        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
