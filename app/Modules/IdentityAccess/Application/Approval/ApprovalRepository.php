<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Approval;

use App\Modules\IdentityAccess\Domain\Approval\ApprovalRequest;
use App\Shared\Application\Concurrency\OptimisticLockConflict;
use App\Shared\Domain\Tenancy\PropertyId;

interface ApprovalRepository
{
    public function add(ApprovalRequest $request, string $correlationId): void;

    public function find(PropertyId $property, string $id): ?ApprovalRequest;

    /**
     * Compare-and-swap on the lock version, and appends the decisions made since it was loaded.
     *
     * @throws OptimisticLockConflict when someone else changed it first
     */
    public function save(ApprovalRequest $request, string $correlationId): void;

    /** @return list<ApprovalRequest> oldest first */
    public function pending(PropertyId $property, int $limit): array;

    /** @return list<ApprovalRequest> newest first */
    public function byMaker(PropertyId $property, string $makerId, int $limit): array;
}
