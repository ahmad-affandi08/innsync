<?php

use App\Modules\FnbSales\Application\OfflineSaleHandler;
use App\Shared\Application\Offline\OfflineOperationHandler;
use App\Shared\Infrastructure\Offline\SystemEchoHandler;

// Offline operation envelope (TASK-FND-017). Technical limits and monitoring thresholds, tunable per
// hosting plan and device fleet. They are not hotel business policy; Q-14 (devices, realistic offline
// duration) is still open and should be answered with evidence from the diagnostic operation.
return [
    /**
     * Handlers by owning module (POS sale, room status, ...). Each declares its type, payload versions,
     * and permission, and owns only the business rule.
     *
     * @var list<class-string<OfflineOperationHandler>>
     */
    'handlers' => [
        SystemEchoHandler::class,
        OfflineSaleHandler::class,
    ],

    'max_batch_items' => max(1, (int) env('OFFLINE_MAX_BATCH_ITEMS', 50)),
    'max_payload_bytes' => max(1024, (int) env('OFFLINE_MAX_PAYLOAD_BYTES', 65536)),
    'max_request_bytes' => max(65536, (int) env('OFFLINE_MAX_REQUEST_BYTES', 2097152)),

    // Health thresholds (check `sync_backlog`).
    'exception_degraded_age_seconds' => max(60, (int) env('OFFLINE_EXCEPTION_DEGRADED_SECONDS', 900)),
    'exception_down_age_seconds' => max(60, (int) env('OFFLINE_EXCEPTION_DOWN_SECONDS', 86400)),
    'device_pending_degraded_seconds' => max(60, (int) env('OFFLINE_DEVICE_PENDING_DEGRADED_SECONDS', 900)),
    // NFR-04 requires at least 4 hours offline; a queue still unsent after that is serious.
    'device_pending_down_seconds' => max(60, (int) env('OFFLINE_DEVICE_PENDING_DOWN_SECONDS', 14400)),
    'device_report_ttl_hours' => max(1, (int) env('OFFLINE_DEVICE_REPORT_TTL_HOURS', 24)),
];
