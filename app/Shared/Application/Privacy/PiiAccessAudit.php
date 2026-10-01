<?php

declare(strict_types=1);

namespace App\Shared\Application\Privacy;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * One standard way to record that a person looked at personal data (NFR-08 "audit atas akses PII"). Modules call it
 * where a screen or API reveals identity documents, contact details or HR records. It stores which fields and why,
 * never the values.
 */
final readonly class PiiAccessAudit
{
    public function __construct(private AuditTrail $audit) {}

    /** @param list<string> $fields */
    public function record(PropertyId $property, string $actorId, string $subjectType, string $subjectId, string $purpose, array $fields): void
    {
        if ($fields === [] || trim($purpose) === '' || mb_strlen($purpose) > 500) {
            throw PrivacyRefused::invalid('Access to personal data is recorded with the fields shown and the purpose.', ['purpose']);
        }

        $this->audit->record(new AuditEntry(
            $property->toString(),
            strtolower($actorId),
            'pii.accessed',
            $subjectType,
            strtolower($subjectId),
            null,
            ['fields' => array_values($fields)],
            trim($purpose),
        ));
    }
}
