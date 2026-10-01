<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability\Health;

use App\Shared\Application\Observability\Health\HealthCheck;
use App\Shared\Application\Observability\Health\HealthResult;

/** Covers file storage and logs. Database size is quota-specific on shared hosting and is not guessed here. */
final class StorageCapacityCheck implements HealthCheck
{
    public function name(): string
    {
        return 'storage_capacity';
    }

    public function check(): HealthResult
    {
        $path = storage_path();
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        if ($free === false || $total === false || $total <= 0) {
            return HealthResult::degraded('Storage capacity could not be measured.');
        }

        $percent = round($free / $total * 100, 1);
        $context = ['free_percent' => $percent];

        return match (true) {
            ! is_writable($path) => HealthResult::down('The storage directory is not writable.', $context),
            $percent <= (float) config('observability.storage_free_down_percent') => HealthResult::down('Storage is almost full.', $context),
            $percent <= (float) config('observability.storage_free_degraded_percent') => HealthResult::degraded('Storage is running low.', $context),
            default => HealthResult::ok('Storage capacity is sufficient.', $context),
        };
    }
}
