<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability\Health;

use App\Shared\Application\Observability\Health\HealthCheck;
use App\Shared\Application\Observability\Health\HealthResult;
use App\Shared\Application\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class OutboxBacklogCheck implements HealthCheck
{
    public function __construct(private Clock $clock) {}

    public function name(): string
    {
        return 'outbox_backlog';
    }

    public function check(): HealthResult
    {
        $now = $this->clock->nowUtc();
        $oldest = DB::table('outbox_messages')
            ->whereIn('status', ['pending', 'queued', 'retrying'])
            ->where('available_at', '<=', $now)
            ->min('available_at');
        $deadLetters = DB::table('outbox_messages')->where('status', 'dead_letter')->count();
        $age = is_string($oldest)
            ? max(0, $now->getTimestamp() - CarbonImmutable::parse($oldest, 'UTC')->getTimestamp())
            : 0;
        $context = ['oldest_due_age_seconds' => $age, 'dead_letters' => $deadLetters];

        return match (true) {
            $age >= (int) config('observability.outbox_stale_down_seconds') => HealthResult::down('Outbox delivery is stalled.', $context),
            $age >= (int) config('observability.outbox_stale_degraded_seconds') => HealthResult::degraded('Outbox delivery is delayed.', $context),
            $deadLetters > 0 => HealthResult::degraded('Dead-letter outbox messages need review.', $context),
            default => HealthResult::ok('The outbox is draining.', $context),
        };
    }
}
