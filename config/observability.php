<?php

use App\Shared\Application\Observability\Health\HealthCheck;
use App\Shared\Infrastructure\Integration\IntegrationHealthCheck;
use App\Shared\Infrastructure\Offline\SyncBacklogCheck;
use App\Shared\Infrastructure\Privacy\PrivacyRequestsCheck;

// Operational thresholds, tunable per hosting plan. They are not hotel business policy.
return [
    // Bearer secret for GET /health/details. Null disables the endpoint (404).
    'health_token' => env('HEALTH_TOKEN'),

    'scheduler_heartbeat_max_age_seconds' => max(60, (int) env('HEALTH_SCHEDULER_MAX_AGE_SECONDS', 180)),

    'failed_jobs_degraded_at' => max(1, (int) env('HEALTH_FAILED_JOBS_DEGRADED_AT', 1)),
    'failed_jobs_down_at' => max(1, (int) env('HEALTH_FAILED_JOBS_DOWN_AT', 10)),

    'outbox_stale_degraded_seconds' => max(60, (int) env('HEALTH_OUTBOX_STALE_DEGRADED_SECONDS', 300)),
    'outbox_stale_down_seconds' => max(60, (int) env('HEALTH_OUTBOX_STALE_DOWN_SECONDS', 1800)),

    'storage_free_degraded_percent' => (float) env('HEALTH_STORAGE_FREE_DEGRADED_PERCENT', 15),
    'storage_free_down_percent' => (float) env('HEALTH_STORAGE_FREE_DOWN_PERCENT', 5),

    'error_rate_window_minutes' => min(60, max(1, (int) env('HEALTH_ERROR_WINDOW_MINUTES', 5))),
    'error_rate_degraded_count' => max(1, (int) env('HEALTH_ERROR_DEGRADED_COUNT', 10)),
    'error_rate_down_count' => max(1, (int) env('HEALTH_ERROR_DOWN_COUNT', 50)),

    /**
     * Extra checks owned by other tasks (payment unknown, sync backlog, backup failure,
     * night-audit failure, provider degradation).
     *
     * @var list<class-string<HealthCheck>>
     */
    'checks' => [
        SyncBacklogCheck::class,
        PrivacyRequestsCheck::class,
        IntegrationHealthCheck::class,
    ],
];
