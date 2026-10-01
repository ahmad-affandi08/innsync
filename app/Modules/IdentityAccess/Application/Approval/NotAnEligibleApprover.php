<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Approval;

use App\Shared\Application\Errors\ExpectedFailure;
use RuntimeException;

final class NotAnEligibleApprover extends RuntimeException implements ExpectedFailure
{
    public function status(): int
    {
        return 403;
    }

    public function errorCode(): string
    {
        return 'forbidden';
    }

    public function messageKey(): string
    {
        return 'forbidden';
    }

    public function conflict(): ?array
    {
        return null;
    }

    public function invalidFields(): array
    {
        return [];
    }
}
