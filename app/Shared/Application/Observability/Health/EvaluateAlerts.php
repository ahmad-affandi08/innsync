<?php

declare(strict_types=1);

namespace App\Shared\Application\Observability\Health;

use App\Shared\Application\Time\Clock;

/**
 * Turns health results into alert lifecycle events: one open alert per key, notified when raised,
 * escalated, or resolved, so a persistent fault does not spam every scheduler tick.
 */
final readonly class EvaluateAlerts
{
    public function __construct(
        private AlertStore $store,
        private AlertNotifier $notifier,
        private Clock $clock,
    ) {}

    public function execute(HealthReport $report): void
    {
        $now = $this->clock->nowUtc();

        foreach ($report->results as $key => $result) {
            $open = $this->store->openStatus($key);

            if ($result->status === HealthStatus::Ok) {
                if ($open !== null) {
                    $this->store->resolve($key, $now);
                    $this->notifier->notify('resolved', $key, $result);
                }

                continue;
            }

            if ($open === null) {
                $this->store->open($key, $result, $now);
                $this->notifier->notify('raised', $key, $result);

                continue;
            }

            $this->store->touch($key, $result, $now);

            if ($result->status->rank() > $open->rank()) {
                $this->notifier->notify('escalated', $key, $result);
            }
        }
    }
}
