<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Approval;

use App\Modules\IdentityAccess\Domain\Approval\ApprovalPolicy;
use App\Shared\Domain\Tenancy\PropertyId;

interface ApprovalPolicyRepository
{
    /** The active policy with the highest band at or below the amount (a missing amount matches only band 0). */
    public function resolve(PropertyId $property, string $subjectType, ?int $amountMinor): ?ApprovalPolicy;

    public function find(PropertyId $property, string $policyId): ?ApprovalPolicy;

    /** Adds the next version and marks the previous one superseded, atomically. */
    public function replace(PropertyId $property, string $subjectType, int $band, ApprovalPolicy $policy, string $actorId, string $reason): void;

    public function latestVersion(PropertyId $property, string $subjectType, int $band): int;
}
