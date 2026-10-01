<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Approval;

use App\Shared\Application\Approval\ApprovalSubjects;

final class ConfiguredApprovalSubjects implements ApprovalSubjects
{
    public function isDeclared(string $subjectType): bool
    {
        return array_key_exists($subjectType, (array) config('approvals.subjects'));
    }

    public function isMandatory(string $subjectType): bool
    {
        $subjects = (array) config('approvals.subjects');

        return (bool) ($subjects[$subjectType]['mandatory'] ?? false);
    }
}
