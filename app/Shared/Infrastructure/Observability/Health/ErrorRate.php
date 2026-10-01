<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability\Health;

use App\Shared\Application\Observability\Health\HealthCheck;
use App\Shared\Application\Observability\Health\HealthResult;
use App\Shared\Application\Time\Clock;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Counts reported (unexpected) exceptions per minute bucket; expected conflicts/denials are not counted. */
final readonly class ErrorRate implements HealthCheck
{
    public function __construct(private Clock $clock) {}

    public function name(): string
    {
        return 'error_rate';
    }

    public function record(): void
    {
        try {
            $key = $this->bucketKey($this->clock->nowUtc()->getTimestamp());
            Cache::add($key, 0, 3600 + 120);
            Cache::increment($key);
        } catch (Throwable) {
            // Observability must never turn an error into a second error.
        }
    }

    public function check(): HealthResult
    {
        $now = $this->clock->nowUtc()->getTimestamp();
        $window = (int) config('observability.error_rate_window_minutes');
        $count = 0;

        for ($i = 0; $i < $window; $i++) {
            $count += (int) Cache::get($this->bucketKey($now - $i * 60), 0);
        }

        $context = ['errors' => $count, 'window_minutes' => $window];

        return match (true) {
            $count >= (int) config('observability.error_rate_down_count') => HealthResult::down('The unexpected error rate is critical.', $context),
            $count >= (int) config('observability.error_rate_degraded_count') => HealthResult::degraded('The unexpected error rate is elevated.', $context),
            default => HealthResult::ok('The unexpected error rate is normal.', $context),
        };
    }

    private function bucketKey(int $timestamp): string
    {
        return 'observability.errors.'.intdiv($timestamp, 60);
    }
}
