<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Approval;

use App\Modules\IdentityAccess\Domain\Approval\ApprovalRuleViolation;
use App\Shared\Application\Errors\ExpectedFailure;
use RuntimeException;

/** A maker-checker rule refused the action. Carries the domain reason code; rendered as a standard error. */
final class ApprovalRefused extends RuntimeException implements ExpectedFailure
{
    private function __construct(public readonly string $reasonCode, string $message)
    {
        parent::__construct($message);
    }

    public static function from(ApprovalRuleViolation $violation): self
    {
        return new self($violation->reasonCode, $violation->getMessage());
    }

    public function status(): int
    {
        return match ($this->reasonCode) {
            ApprovalRuleViolation::SELF_APPROVAL, ApprovalRuleViolation::NOT_MAKER => 403,
            ApprovalRuleViolation::REASON_REQUIRED => 422,
            default => 409,
        };
    }

    public function errorCode(): string
    {
        return match ($this->status()) {
            403 => 'forbidden',
            422 => 'validation_failed',
            default => 'conflict',
        };
    }

    public function messageKey(): string
    {
        return match ($this->status()) {
            403 => 'forbidden',
            422 => 'validation_failed',
            default => in_array($this->reasonCode, [ApprovalRuleViolation::ALREADY_DECIDED, ApprovalRuleViolation::NOT_PENDING], true)
                ? 'conflict_approval_state'
                : 'conflict_approval_not_usable',
        };
    }

    public function conflict(): ?array
    {
        if ($this->status() !== 409) {
            return null;
        }

        return in_array($this->reasonCode, [ApprovalRuleViolation::ALREADY_DECIDED, ApprovalRuleViolation::NOT_PENDING], true)
            ? ['reason' => 'approval_state', 'action' => 'refresh']
            : ['reason' => 'approval_not_usable', 'action' => 'review'];
    }

    public function invalidFields(): array
    {
        return $this->reasonCode === ApprovalRuleViolation::REASON_REQUIRED ? ['reason'] : [];
    }
}
