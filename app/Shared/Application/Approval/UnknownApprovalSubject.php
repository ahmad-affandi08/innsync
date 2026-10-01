<?php

declare(strict_types=1);

namespace App\Shared\Application\Approval;

use RuntimeException;

final class UnknownApprovalSubject extends RuntimeException
{
    public static function for(string $subjectType): self
    {
        return new self("The approval subject {$subjectType} is not declared in config/approvals.php.");
    }
}
