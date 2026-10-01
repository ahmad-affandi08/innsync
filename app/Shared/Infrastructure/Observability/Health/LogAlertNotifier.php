<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability\Health;

use App\Shared\Application\Observability\Health\AlertNotifier;
use App\Shared\Application\Observability\Health\HealthResult;
use App\Shared\Application\Observability\Health\HealthStatus;
use Illuminate\Support\Facades\Log;

/**
 * Delivery is a structured log line carrying the correlation context. Pushing to e-mail/chat needs an
 * approved provider and recipients (TASK-FND-020); until then operators watch logs and `operational_alerts`.
 */
final class LogAlertNotifier implements AlertNotifier
{
    public function notify(string $transition, string $key, HealthResult $result): void
    {
        $context = [
            'alert_key' => $key,
            'transition' => $transition,
            'severity' => $result->status->value,
            'health' => $result->context,
        ];

        match (true) {
            $transition === 'resolved' => Log::info('Operational alert resolved: '.$result->summary, $context),
            $result->status === HealthStatus::Down => Log::critical('Operational alert: '.$result->summary, $context),
            default => Log::warning('Operational alert: '.$result->summary, $context),
        };
    }
}
