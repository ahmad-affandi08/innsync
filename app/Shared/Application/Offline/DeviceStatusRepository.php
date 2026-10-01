<?php

declare(strict_types=1);

namespace App\Shared\Application\Offline;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Latest queue depth a device reported, so a stuck device is noticed (alert: sync backlog, NFR-20). */
interface DeviceStatusRepository
{
    public function report(
        PropertyId $property,
        string $deviceId,
        string $actorId,
        int $pending,
        int $oldestPendingSeconds,
        DateTimeImmutable $reportedAt,
    ): void;
}
