<?php

declare(strict_types=1);

namespace App\Shared\Application\Retention;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Privacy\PrivacyRefused;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/** Places and releases legal holds. Both need a reason and are audited; holds are never deleted. */
final readonly class LegalHolds
{
    public const MANAGE_PERMISSION = 'privacy.legal-hold.manage';

    public function __construct(
        private LegalHoldRepository $holds,
        private PermissionChecker $permissions,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    public function place(PropertyId $property, string $actorId, string $scopeType, ?string $purpose, ?string $ownerType, ?string $ownerId, string $reason): LegalHold
    {
        $this->authorize($property, $actorId);

        $valid = match ($scopeType) {
            LegalHold::PROPERTY => $purpose === null && $ownerType === null && $ownerId === null,
            LegalHold::PURPOSE => $purpose !== null && $purpose !== '' && $ownerType === null && $ownerId === null,
            LegalHold::OWNER => $purpose === null && $ownerType !== null && $ownerType !== '' && $ownerId !== null && preg_match('/^[0-9a-z]{26}$/', $ownerId) === 1,
            default => false,
        };

        if (! $valid) {
            throw PrivacyRefused::invalid('A legal hold covers the whole property, one purpose, or one owner record.', ['scope_type']);
        }

        if (trim($reason) === '' || mb_strlen($reason) > 500) {
            throw PrivacyRefused::invalid('A legal hold needs a reason of at most 500 characters.', ['reason']);
        }

        $hold = new LegalHold($this->ids->next(), $scopeType, $purpose, $ownerType, $ownerId, trim($reason), strtolower($actorId), $this->clock->nowUtc(), null);

        $this->transactions->run(function () use ($property, $hold): void {
            $this->holds->add($property, $hold);
            $this->audit->record(new AuditEntry($property->toString(), $hold->placedBy, 'legal_hold.placed', 'legal_hold', $hold->id, null, $this->describe($hold), $hold->reason));
        });

        return $hold;
    }

    public function release(PropertyId $property, string $actorId, string $holdId, string $reason): void
    {
        $this->authorize($property, $actorId);

        if (trim($reason) === '' || mb_strlen($reason) > 500) {
            throw PrivacyRefused::invalid('Releasing a legal hold needs a reason of at most 500 characters.', ['reason']);
        }

        $hold = $this->holds->find($property, strtolower($holdId)) ?? throw PrivacyRefused::notFound('Legal hold not found.');

        $this->transactions->run(function () use ($property, $actorId, $hold, $reason): void {
            if (! $this->holds->release($property, $hold->id, strtolower($actorId), trim($reason), $this->clock->nowUtc())) {
                throw PrivacyRefused::stateConflict('This legal hold was already released.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'legal_hold.released', 'legal_hold', $hold->id, $this->describe($hold), ['released' => true], trim($reason)));
        });
    }

    private function authorize(PropertyId $property, string $actorId): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }

        if (! $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property)) {
            throw PrivacyRefused::forbidden('This person may not manage legal holds.');
        }
    }

    /** @return array<string, mixed> */
    private function describe(LegalHold $hold): array
    {
        return array_filter([
            'scope_type' => $hold->scopeType,
            'purpose' => $hold->purpose,
            'owner_type' => $hold->ownerType,
            'owner_id' => $hold->ownerId,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
