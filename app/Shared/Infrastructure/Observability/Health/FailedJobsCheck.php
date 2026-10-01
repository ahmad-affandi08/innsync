<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability\Health;

use App\Shared\Application\Observability\Health\HealthCheck;
use App\Shared\Application\Observability\Health\HealthResult;
use Illuminate\Support\Facades\DB;

final class FailedJobsCheck implements HealthCheck
{
    public function name(): string
    {
        return 'failed_jobs';
    }

    public function check(): HealthResult
    {
        $count = DB::table('failed_jobs')->count();
        $context = ['failed_jobs' => $count];

        return match (true) {
            $count >= (int) config('observability.failed_jobs_down_at') => HealthResult::down('Many queue jobs have failed.', $context),
            $count >= (int) config('observability.failed_jobs_degraded_at') => HealthResult::degraded('Queue jobs have failed.', $context),
            default => HealthResult::ok('No failed queue jobs.', $context),
        };
    }
}
