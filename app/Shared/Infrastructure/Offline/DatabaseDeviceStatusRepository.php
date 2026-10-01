<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Offline;

use App\Shared\Application\Offline\DeviceStatusRepository;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\UtcTime;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class DatabaseDeviceStatusRepository implements DeviceStatusRepository
{
    public function report(
        PropertyId $property,
        string $deviceId,
        string $actorId,
        int $pending,
        int $oldestPendingSeconds,
        DateTimeImmutable $reportedAt,
    ): void {
        DB::table('offline_device_status')->upsert(
            [[
                'property_id' => $property->toString(),
                'device_id' => strtolower($deviceId),
                'actor_id' => strtolower($actorId),
                'pending' => max(0, $pending),
                'oldest_pending_seconds' => max(0, $oldestPendingSeconds),
                'reported_at' => UtcTime::normalize($reportedAt)->format('Y-m-d H:i:s.u'),
            ]],
            ['property_id', 'device_id'],
            ['actor_id', 'pending', 'oldest_pending_seconds', 'reported_at'],
        );
    }
}
