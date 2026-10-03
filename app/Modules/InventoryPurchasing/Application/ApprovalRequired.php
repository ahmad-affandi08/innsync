<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Shared\Application\Errors\ExpectedFailure;
use RuntimeException;

/** The stock adjustment is large enough for the property's policy to require an approved request first (BR-004, FR-INV-010). */
final class ApprovalRequired extends RuntimeException implements ExpectedFailure
{
    public function __construct()
    {
        parent::__construct('This adjustment needs an approval first.');
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
        return 'conflict_approval_required';
    }

    public function conflict(): ?array
    {
        return ['reason' => 'approval_required', 'action' => 'review'];
    }

    public function invalidFields(): array
    {
        return [];
    }
}
