<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Domain\Approval;

use DateTimeImmutable;

/** One recorded decision. Decisions are append-only evidence. */
final readonly class ApprovalDecision
{
    public const APPROVE = 'approve';

    public const REJECT = 'reject';

    public function __construct(
        public int $step,
        public string $approverId,
        public string $decision,
        public ?string $reason,
        public DateTimeImmutable $decidedAt,
    ) {}
}
