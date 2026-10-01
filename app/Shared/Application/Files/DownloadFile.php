<?php

declare(strict_types=1);

namespace App\Shared\Application\Files;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Security\SecurityEvent;
use App\Shared\Application\Security\SecurityEventOutcome;
use App\Shared\Application\Security\SecurityLog;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/** Every successful read is audited (BR-009); every denial is a security event. */
final readonly class DownloadFile
{
    public function __construct(
        private StoredFileRepository $files,
        private PrivateFileStorage $storage,
        private Clock $clock,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private SecurityLog $securityLog,
    ) {}

    /**
     * @throws StoredFileNotFound when absent, expired, or in another property
     * @throws FileAccessDenied when the module policy refuses the actor
     * @throws StoredFileUnreadable when the blob is missing or fails integrity checks
     */
    public function execute(
        PropertyId $propertyId,
        string $fileId,
        string $actorId,
        FileAccessPolicy $policy,
    ): FileContent {
        $file = $this->files->find($propertyId, strtolower($fileId));

        if ($file->isExpiredAt($this->clock->nowUtc())) {
            $this->recordDenial($file, $actorId, 'expired');

            throw StoredFileNotFound::forId($file->id);
        }

        if (! $policy->allows($actorId, $file)) {
            $this->recordDenial($file, $actorId, 'forbidden');

            throw FileAccessDenied::forFile($file->id);
        }

        $contents = $this->storage->get($file->storageKey);

        if (! hash_equals($file->checksumSha256, hash('sha256', $contents))) {
            $this->securityLog->record(new SecurityEvent(
                'file.integrity-failed',
                SecurityEventOutcome::Failure,
                $actorId,
                $file->propertyId->toString(),
                ['file_id' => $file->id],
            ));

            throw StoredFileUnreadable::detected();
        }

        $this->transactions->run(fn () => $this->audit->record(new AuditEntry(
            $file->propertyId->toString(),
            $actorId,
            'file.downloaded',
            'stored_file',
            $file->id,
            null,
            [
                'purpose' => $file->purpose,
                'sensitivity' => $file->sensitivity->value,
                'owner_type' => $file->ownerType,
                'owner_id' => $file->ownerId,
            ],
        )));

        return new FileContent($file, $contents);
    }

    private function recordDenial(StoredFile $file, string $actorId, string $reason): void
    {
        $this->securityLog->record(new SecurityEvent(
            'file.download-denied',
            SecurityEventOutcome::Denied,
            $actorId,
            $file->propertyId->toString(),
            ['file_id' => $file->id, 'purpose' => $file->purpose, 'reason' => $reason],
        ));
    }
}
