<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Domain\Approval;

use DomainException;

/** A rule of maker-checker was broken. `reasonCode` is stable and language-neutral. */
final class ApprovalRuleViolation extends DomainException
{
    public const SELF_APPROVAL = 'self_approval';

    public const ALREADY_DECIDED = 'already_decided';

    public const NOT_PENDING = 'not_pending';

    public const NOT_MAKER = 'not_maker';

    public const REASON_REQUIRED = 'reason_required';

    public const NOT_APPROVED = 'not_approved';

    public const ALREADY_CONSUMED = 'already_consumed';

    public const SUBJECT_MISMATCH = 'subject_mismatch';

    public const PAYLOAD_MISMATCH = 'payload_mismatch';

    public function __construct(public readonly string $reasonCode, string $message)
    {
        parent::__construct($message);
    }
}
