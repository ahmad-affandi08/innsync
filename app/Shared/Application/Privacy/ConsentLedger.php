<?php

declare(strict_types=1);

namespace App\Shared\Application\Privacy;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Append-only record of consent and its withdrawal (NFR-08, UU 27/2022): what was agreed, for which purpose,
 * under which notice version, and when. Withdrawing consent appends a new row; nothing is edited. The caller
 * (a module screen) has already authorized the actor to record consent for this subject.
 */
final readonly class ConsentLedger
{
    public function __construct(
        private ConsentRepository $consents,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    public function record(PropertyId $property, string $actorId, string $subjectType, string $subjectId, string $purpose, string $noticeVersion, bool $granted, ?string $evidenceRef = null): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }

        if (preg_match('/^[a-z][a-z0-9_]{1,39}$/', $subjectType) !== 1 || preg_match('/^[0-9a-z]{26}$/', $subjectId) !== 1) {
            throw PrivacyRefused::invalid('Consent needs a subject type and a subject id.', ['subject']);
        }

        if (preg_match('/^[a-z][a-z0-9._-]{1,79}$/', $purpose) !== 1) {
            throw PrivacyRefused::invalid('Consent needs a purpose code.', ['purpose']);
        }

        if (trim($noticeVersion) === '' || mb_strlen($noticeVersion) > 40 || ($evidenceRef !== null && mb_strlen($evidenceRef) > 120)) {
            throw PrivacyRefused::invalid('Consent needs the privacy notice version it refers to.', ['notice_version']);
        }

        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $id, $actorId, $subjectType, $subjectId, $purpose, $noticeVersion, $granted, $evidenceRef): void {
            $this->consents->append($property, $id, $subjectType, strtolower($subjectId), $purpose, trim($noticeVersion), $granted, $evidenceRef, strtolower($actorId), $this->clock->nowUtc());
            $this->audit->record(new AuditEntry(
                $property->toString(),
                strtolower($actorId),
                $granted ? 'consent.granted' : 'consent.withdrawn',
                'consent_record',
                $id,
                null,
                ['subject_type' => $subjectType, 'subject_id' => strtolower($subjectId), 'purpose' => $purpose, 'notice_version' => trim($noticeVersion), 'granted' => $granted],
            ));
        });
    }

    /** True only when the latest record for this purpose is a grant. No record means no consent. */
    public function isGranted(PropertyId $property, string $subjectType, string $subjectId, string $purpose): bool
    {
        return $this->consents->latest($property, $subjectType, strtolower($subjectId), $purpose) === true;
    }
}
