<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Approval;

use App\Shared\Application\Setup\TodayTasks;
use App\Shared\Domain\Tenancy\PropertyId;

/** Requests waiting for this person's decision (leave, purchases, payments, voids ...): the service lists only the ones the person may decide. */
final readonly class ApprovalTodayTasks implements TodayTasks
{
    public function __construct(private ApprovalService $approvals) {}

    public function tasks(PropertyId $property, string $actorId): array
    {
        return [['key' => 'approvals.waiting', 'count' => count($this->approvals->pendingFor($property, $actorId, 99)), 'href' => '/approvals', 'urgent' => true]];
    }
}
