<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability\Health;

use App\Shared\Application\Observability\Health\HealthCheck;
use App\Shared\Application\Observability\Health\HealthResult;
use App\Shared\Application\Time\Clock;
use Illuminate\Support\Facades\Cache;

/** Cron is the only driver of the outbox and queue on shared hosting, so a silent cron is critical. */
final readonly class SchedulerHeartbeat implements HealthCheck
{
    public const CACHE_KEY = 'observability.scheduler_heartbeat';

    public function __construct(private Clock $clock) {}

    public function name(): string
    {
        return 'scheduler';
    }

    public function record(): void
    {
        Cache::forever(self::CACHE_KEY, $this->clock->nowUtc()->getTimestamp());
    }

    public function check(): HealthResult
    {
        $last = Cache::get(self::CACHE_KEY);

        if (! is_int($last)) {
            return HealthResult::degraded('No scheduler heartbeat has been recorded yet.');
        }

        $age = max(0, $this->clock->nowUtc()->getTimestamp() - $last);
        $max = (int) config('observability.scheduler_heartbeat_max_age_seconds');

        return $age > $max
            ? HealthResult::down('The scheduler heartbeat is stale; verify the cron job.', ['age_seconds' => $age])
            : HealthResult::ok('The scheduler is running.', ['age_seconds' => $age]);
    }
}
