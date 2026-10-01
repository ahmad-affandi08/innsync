<?php

declare(strict_types=1);

namespace App\Shared\Application\Files;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Privacy\PrivacyRefused;
use App\Shared\Application\Retention\RetentionPolicies;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Stores an export of personal or sensitive data as a private, expiring file (NFR-24, NFR-08). The expiry comes
 * from the property's `sensitive_export_file` retention, never from the caller, so no export is permanent and no
 * public link exists. The export belongs to the person who issued it; downloads go through `DownloadFile`, which
 * audits every read. The caller has already authorized the actor to export this data (and, where the owning
 * module requires it, obtained an approval).
 */
final readonly class IssueSensitiveExport
{
    public function __construct(
        private StoreFile $store,
        private RetentionPolicies $retention,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private Clock $clock,
    ) {}

    /** @param list<string> $allowedMimeTypes */
    public function execute(PropertyId $propertyId, string $actorId, string $exportCode, string $contents, array $allowedMimeTypes, int $maxBytes, ?string $displayName, string $reason): StoredFile
    {
        if (trim($reason) === '' || mb_strlen($reason) > 500) {
            throw PrivacyRefused::invalid('An export of personal data needs the reason it is issued (at most 500 characters).', ['reason']);
        }

        $expiresAt = $this->retention->expiryFor($propertyId, 'sensitive_export_file', $this->clock->nowUtc());

        $file = $this->store->execute(new FileUpload(
            $propertyId,
            $actorId,
            'export.'.$exportCode,
            'user',
            $actorId,
            $contents,
            new FilePolicy($allowedMimeTypes, $maxBytes, FileSensitivity::Sensitive, requiresExpiry: true),
            $displayName,
            $expiresAt,
        ));

        $this->transactions->run(fn () => $this->audit->record(new AuditEntry(
            $propertyId->toString(),
            strtolower($actorId),
            'export.issued',
            'stored_file',
            $file->id,
            null,
            ['export' => $exportCode, 'expires_at' => $expiresAt->format('Y-m-d\TH:i:s\Z'), 'size_bytes' => $file->sizeBytes],
            trim($reason),
        )));

        return $file;
    }
}
