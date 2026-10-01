<?php

declare(strict_types=1);

namespace App\Shared\Application\Approval;

/** The approval subject types modules have declared, and which of them must never skip approval. */
interface ApprovalSubjects
{
    public function isDeclared(string $subjectType): bool;

    /** A mandatory subject fails closed when no policy applies (FR-FBS-005 void and cancel, FR-FIN-018 payments...). */
    public function isMandatory(string $subjectType): bool;
}
