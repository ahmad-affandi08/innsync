<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Approval;

use App\Shared\Application\Errors\ExpectedFailure;
use RuntimeException;

/** Also raised for a request in another property, so existence is never revealed across properties. */
final class ApprovalRequestNotFound extends RuntimeException implements ExpectedFailure
{
    public function status(): int
    {
        return 404;
    }

    public function errorCode(): string
    {
        return 'not_found';
    }

    public function messageKey(): string
    {
        return 'not_found';
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
