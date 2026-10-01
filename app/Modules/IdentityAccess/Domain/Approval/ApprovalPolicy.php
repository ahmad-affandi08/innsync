<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Domain\Approval;

use InvalidArgumentException;

/**
 * A property's approver chain for one subject type and amount band (BR-004:
 * thresholds and chains are configured per property). Immutable; a change is a
 * new version, and requests keep the snapshot they were opened under.
 */
final readonly class ApprovalPolicy
{
    public const MAX_STEPS = 5;

    /** @param list<ApprovalStep> $steps */
    public function __construct(
        public string $id,
        public string $subjectType,
        public int $bandMinAmountMinor,
        public array $steps,
        public int $version = 1,
    ) {
        if ($bandMinAmountMinor < 0) {
            throw new InvalidArgumentException('An amount band starts at zero or more.');
        }

        if ($steps === [] || count($steps) > self::MAX_STEPS || ! array_is_list($steps)) {
            throw new InvalidArgumentException('A policy needs between 1 and '.self::MAX_STEPS.' steps.');
        }
    }

    /**
     * Every approval comes from a different person (distinct approvers across all steps),
     * so the total number of people needed is the sum of the steps' approvals.
     */
    public function totalApprovals(): int
    {
        return array_sum(array_map(static fn (ApprovalStep $step): int => $step->approvalsRequired, $this->steps));
    }
}
