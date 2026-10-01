<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability\Health;

use App\Shared\Application\Observability\Health\HealthCheck;
use App\Shared\Application\Observability\Health\HealthResult;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DatabaseCheck implements HealthCheck
{
    public function name(): string
    {
        return 'database';
    }

    public function check(): HealthResult
    {
        $start = hrtime(true);

        try {
            DB::select('select 1');
        } catch (Throwable) {
            return HealthResult::down('The database is unreachable.');
        }

        return HealthResult::ok('The database responds.', [
            'latency_ms' => (int) round((hrtime(true) - $start) / 1_000_000),
        ]);
    }
}
