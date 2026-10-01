<?php

declare(strict_types=1);

namespace App\Shared\Application\Approval;

use App\Shared\Application\Errors\ExpectedFailure;
use RuntimeException;

final class MissingApprovalPolicy extends RuntimeException implements ExpectedFailure
{
    public static function for(string $subjectType): self
    {
        return new self("No approval policy is configured for the mandatory action {$subjectType}.");
    }

    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'conflict';
    }

    public function messageKey(): string
    {
        return 'conflict_approval_policy_missing';
    }

    public function conflict(): ?array
    {
        return ['reason' => 'approval_policy_missing', 'action' => 'review'];
    }

    public function invalidFields(): array
    {
        return [];
    }
}
