<?php

declare(strict_types=1);

namespace App\Shared\Application\Approval;

use App\Shared\Application\Errors\ExpectedFailure;
use RuntimeException;

/** An approved request could not be used for this action. `reasonCode` is stable and language-neutral. */
final class ApprovalNotUsable extends RuntimeException implements ExpectedFailure
{
    public function __construct(public readonly string $reasonCode, string $message)
    {
        parent::__construct($message);
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
        return 'conflict_approval_not_usable';
    }

    public function conflict(): ?array
    {
        return ['reason' => 'approval_not_usable', 'action' => 'review'];
    }

    public function invalidFields(): array
    {
        return [];
    }
}
