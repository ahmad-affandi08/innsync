<?php

use App\Modules\Finance\Application\CompanyReceivableConsumer;
use App\Modules\Finance\Application\FnbRefundConsumer;
use App\Modules\Finance\Application\FnbSalesRevenueConsumer;
use App\Modules\Finance\Application\FrontOfficeRevenueConsumer;
use App\Modules\Finance\Application\PurchasingPayableConsumer;
use App\Modules\FnbSales\Application\KitchenProgressConsumer;
use App\Modules\FrontOffice\Application\Companies\CompanyReceiptConsumer;
use App\Modules\HumanResource\Application\SopCompletionConsumer;
use App\Modules\InventoryPurchasing\Application\KitchenWasteConsumer;
use App\Modules\InventoryPurchasing\Application\MaintenancePartConsumer;
use App\Modules\InventoryPurchasing\Application\RecipeConsumptionConsumer;
use App\Modules\Kitchen\Application\SaleConsumptionConsumer;
use App\Modules\Kitchen\Application\TicketIntakeConsumer;
use App\Shared\Application\Outbox\OutboxConsumer;

$retryDelays = array_values(array_filter(
    array_map(
        static fn (string $delay): int => min(86400, (int) trim($delay)),
        explode(',', (string) env('OUTBOX_RETRY_DELAYS', '60,300,900,1800')),
    ),
    static fn (int $delay): bool => $delay > 0,
));

$jobTimeout = min(
    45,
    max(1, (int) env('DB_QUEUE_RETRY_AFTER', 90) - 1),
    max(1, (int) env('OUTBOX_JOB_TIMEOUT_SECONDS', 45)),
);

return [
    'queue_connection' => env('OUTBOX_QUEUE_CONNECTION', 'database'),
    'queue_name' => env('OUTBOX_QUEUE_NAME', 'outbox'),
    'drain_batch_size' => min(1000, max(1, (int) env('OUTBOX_DRAIN_BATCH_SIZE', 50))),
    'max_attempts' => min(25, max(1, (int) env('OUTBOX_MAX_ATTEMPTS', 5))),
    'retry_delays' => $retryDelays === [] ? [60] : $retryDelays,
    'job_timeout_seconds' => $jobTimeout,
    'worker_max_time_seconds' => min(
        55,
        max($jobTimeout + 1, (int) env('OUTBOX_WORKER_MAX_TIME_SECONDS', 50)),
    ),

    /** @var list<class-string<OutboxConsumer>> */
    'consumers' => [
        CompanyReceiptConsumer::class,
        CompanyReceivableConsumer::class,
        FnbRefundConsumer::class,
        FnbSalesRevenueConsumer::class,
        FrontOfficeRevenueConsumer::class,
        KitchenProgressConsumer::class,
        PurchasingPayableConsumer::class,
        KitchenWasteConsumer::class,
        MaintenancePartConsumer::class,
        RecipeConsumptionConsumer::class,
        SopCompletionConsumer::class,
        SaleConsumptionConsumer::class,
        TicketIntakeConsumer::class,
    ],
];
