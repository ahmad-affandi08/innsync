<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Offline;

use App\Shared\Application\Observability\Health\HealthCheck;
use App\Shared\Application\Observability\Health\HealthResult;
use App\Shared\Application\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * NFR-20 "sync backlog": offline items waiting for a person (conflicts and
 * rejections not yet reconciled) and devices that report an old unsent queue.
 * A device that is offline cannot report, so a stale report is itself a signal
 * once its age passes the threshold.
 */
final readonly class SyncBacklogCheck implements HealthCheck
{
    public function __construct(private Clock $clock) {}

    public function name(): string
    {
        return 'sync_backlog';
    }

    public function check(): HealthResult
    {
        $now = $this->clock->nowUtc();

        $open = DB::table('offline_sync_exceptions')->where('status', 'open');
        $openCount = (clone $open)->count();
        $oldestOpen = (clone $open)->min('received_at');
        $openAge = is_string($oldestOpen)
            ? max(0, $now->getTimestamp() - CarbonImmutable::parse($oldestOpen, 'UTC')->getTimestamp())
            : 0;

        $fresh = $now->modify('-'.(int) config('offline.device_report_ttl_hours').' hours')->format('Y-m-d H:i:s.u');
        $devices = DB::table('offline_device_status')->where('reported_at', '>=', $fresh)->where('pending', '>', 0);
        $stuckDevices = (clone $devices)->count();
        $oldestPending = (int) (clone $devices)->max('oldest_pending_seconds');

        $context = [
            'open_exceptions' => $openCount,
            'oldest_open_exception_seconds' => $openAge,
            'devices_with_pending' => $stuckDevices,
            'oldest_device_pending_seconds' => $oldestPending,
        ];

        return match (true) {
            $openAge >= (int) config('offline.exception_down_age_seconds'),
            $oldestPending >= (int) config('offline.device_pending_down_seconds') => HealthResult::down('Offline items are waiting too long to be synchronized or reconciled.', $context),
            $openCount > 0,
            $oldestPending >= (int) config('offline.device_pending_degraded_seconds') => HealthResult::degraded('Offline items need synchronization or reconciliation.', $context),
            default => HealthResult::ok('No offline backlog.', $context),
        };
    }
}
