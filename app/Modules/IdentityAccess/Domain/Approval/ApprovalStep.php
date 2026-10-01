<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Domain\Approval;

use App\Modules\IdentityAccess\Domain\Authorization\PermissionCode;
use InvalidArgumentException;

/** One level of an approver chain: who may approve (a permission) and how many distinct approvals it needs. */
final readonly class ApprovalStep
{
    public const MAX_APPROVALS = 5;

    public function __construct(public string $permission, public int $approvalsRequired = 1)
    {
        PermissionCode::fromString($permission);

        if ($approvalsRequired < 1 || $approvalsRequired > self::MAX_APPROVALS) {
            throw new InvalidArgumentException('A step needs between 1 and '.self::MAX_APPROVALS.' approvals.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self((string) ($data['permission'] ?? ''), (int) ($data['approvals_required'] ?? 1));
    }

    /** @return array{permission: string, approvals_required: int} */
    public function toArray(): array
    {
        return ['permission' => $this->permission, 'approvals_required' => $this->approvalsRequired];
    }
}
