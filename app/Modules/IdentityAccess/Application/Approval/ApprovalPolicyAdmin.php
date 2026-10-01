<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Approval;

use App\Modules\IdentityAccess\Domain\Approval\ApprovalPolicy;
use App\Modules\IdentityAccess\Domain\Approval\ApprovalStep;
use App\Shared\Application\Approval\ApprovalSubjects;
use App\Shared\Application\Approval\UnknownApprovalSubject;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use InvalidArgumentException;

/**
 * Defines a property's approver chains (BR-004: thresholds and chains are
 * configured per property). A change is a new version with its reason, audited
 * with the previous and new chain; open requests keep the policy they were
 * opened under. Changing a policy is itself sensitive: dual control for policy
 * changes is an open owner decision and is not assumed here.
 */
final readonly class ApprovalPolicyAdmin
{
    public const MANAGE_PERMISSION = 'identity.approval-policy.manage';

    public function __construct(
        private ApprovalPolicyRepository $policies,
        private ApprovalSubjects $subjects,
        private PermissionChecker $permissions,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private PropertyContext $property,
        private IdentifierGenerator $ids,
    ) {}

    /**
     * Every declared approval subject with whether it is mandatory and the property's current policies for it.
     *
     * @return list<array{subject: string, mandatory: bool, policies: list<array<string, mixed>>}>
     */
    public function overview(PropertyId $propertyId, string $actorId): array
    {
        $current = $this->property->current();

        if (! $current->equals($propertyId)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $propertyId->toString());
        }

        if (! $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $propertyId)) {
            throw new NotAnEligibleApprover('This person may not manage approval policies.');
        }

        $policies = $this->policies->current($propertyId);
        $rows = [];

        foreach ($this->subjects->all() as $subject => $mandatory) {
            $rows[] = [
                'subject' => $subject,
                'mandatory' => $mandatory,
                'policies' => array_values(array_map(fn (ApprovalPolicy $p): array => $this->describe($p) + ['id' => $p->id], array_filter($policies, static fn (ApprovalPolicy $p): bool => $p->subjectType === $subject))),
            ];
        }

        return $rows;
    }

    /** @param list<array{permission: string, approvals_required?: int}> $steps */
    public function define(PropertyId $propertyId, string $actorId, string $subjectType, int $bandMinAmountMinor, array $steps, string $reason): ApprovalPolicy
    {
        $current = $this->property->current();

        if (! $current->equals($propertyId)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $propertyId->toString());
        }

        if (! $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $propertyId)) {
            throw new NotAnEligibleApprover('This person may not manage approval policies.');
        }

        if (! $this->subjects->isDeclared($subjectType)) {
            throw UnknownApprovalSubject::for($subjectType);
        }

        if (trim($reason) === '' || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('A policy change needs a reason of at most 500 characters.');
        }

        return $this->transactions->run(function () use ($propertyId, $actorId, $subjectType, $bandMinAmountMinor, $steps, $reason): ApprovalPolicy {
            $version = $this->policies->latestVersion($propertyId, $subjectType, $bandMinAmountMinor) + 1;
            $policy = new ApprovalPolicy(
                $this->ids->next(),
                $subjectType,
                $bandMinAmountMinor,
                array_values(array_map(static fn (array $step): ApprovalStep => ApprovalStep::fromArray($step), $steps)),
                $version,
            );

            $previous = $this->policies->resolve($propertyId, $subjectType, $bandMinAmountMinor === 0 ? null : $bandMinAmountMinor);
            $previous = $previous !== null && $previous->bandMinAmountMinor === $bandMinAmountMinor ? $previous : null;

            $this->policies->replace($propertyId, $subjectType, $bandMinAmountMinor, $policy, $actorId, trim($reason));

            $this->audit->record(new AuditEntry(
                $propertyId->toString(),
                strtolower($actorId),
                'approval.policy.changed',
                'approval_policy',
                $policy->id,
                $previous === null ? null : $this->describe($previous),
                $this->describe($policy),
                trim($reason),
            ));

            return $policy;
        });
    }

    /** @return array<string, mixed> */
    private function describe(ApprovalPolicy $policy): array
    {
        return [
            'subject_type' => $policy->subjectType,
            'band_min_amount_minor' => $policy->bandMinAmountMinor,
            'version' => $policy->version,
            'steps' => array_map(static fn (ApprovalStep $step): array => $step->toArray(), $policy->steps),
        ];
    }
}
