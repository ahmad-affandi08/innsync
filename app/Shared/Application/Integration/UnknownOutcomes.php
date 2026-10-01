<?php

declare(strict_types=1);

namespace App\Shared\Application\Integration;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Privacy\PrivacyRefused;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Manual reconciliation of calls whose result is unknown (NFR-25, NFR-30). A person establishes what the provider did
 * (from its dashboard or statement) and records it with a reason; the owning module then posts the correction. Nothing
 * here changes business facts.
 */
final readonly class UnknownOutcomes
{
    public const RECONCILE_PERMISSION = 'integration.reconcile';

    public function __construct(
        private UnknownOutcomeRepository $unknowns,
        private PermissionChecker $permissions,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    /** @return list<UnknownOutcome> */
    public function open(PropertyId $property, string $actorId, int $limit = 100): array
    {
        $this->authorize($property, $actorId);

        return $this->unknowns->open($property, max(1, min($limit, 500)));
    }

    public function resolve(PropertyId $property, string $actorId, string $id, bool $providerAppliedIt, string $reason): void
    {
        $this->authorize($property, $actorId);

        if (trim($reason) === '' || mb_strlen($reason) > 500) {
            throw PrivacyRefused::invalid('Resolving an unknown outcome needs the evidence used (at most 500 characters).', ['reason']);
        }

        $outcome = $this->unknowns->find($property, strtolower($id)) ?? throw PrivacyRefused::notFound('Unknown outcome not found.');
        $status = $providerAppliedIt ? UnknownOutcome::SUCCEEDED : UnknownOutcome::FAILED;

        $this->transactions->run(function () use ($property, $actorId, $outcome, $status, $reason): void {
            if (! $this->unknowns->resolve($property, $outcome->id, $status, strtolower($actorId), trim($reason), $this->clock->nowUtc())) {
                throw PrivacyRefused::stateConflict('This outcome was already resolved.');
            }

            $this->audit->record(new AuditEntry(
                $property->toString(),
                strtolower($actorId),
                'integration.unknown.resolved',
                'integration_unknown',
                $outcome->id,
                ['status' => UnknownOutcome::OPEN, 'provider' => $outcome->provider, 'operation' => $outcome->operation],
                ['status' => $status],
                trim($reason),
            ));
        });
    }

    private function authorize(PropertyId $property, string $actorId): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }

        if (! $this->permissions->allowsInProperty($actorId, self::RECONCILE_PERMISSION, $property)) {
            throw PrivacyRefused::forbidden('This person may not reconcile integration outcomes.');
        }
    }
}
