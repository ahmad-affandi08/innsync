<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability\Health;

use Illuminate\Console\Command;

final class HeartbeatCommand extends Command
{
    protected $signature = 'health:heartbeat';

    protected $description = 'Record that the scheduler is running';

    public function handle(SchedulerHeartbeat $heartbeat): int
    {
        $heartbeat->record();

        return self::SUCCESS;
    }
}
