<?php

declare(strict_types=1);

namespace App\Shared\Application\Approval;

/** Whether an action needs approval under the property's configured policy. */
final readonly class ApprovalRequirement
{
    private function __construct(public bool $required, public ?string $policyId) {}

    public static function required(string $policyId): self
    {
        return new self(true, $policyId);
    }

    /** No policy applies to this subject and amount, and the subject is not declared mandatory. */
    public static function notRequired(): self
    {
        return new self(false, null);
    }
}
