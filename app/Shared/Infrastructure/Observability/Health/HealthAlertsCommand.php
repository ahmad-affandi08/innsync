<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability\Health;

use App\Shared\Application\Observability\Health\EvaluateAlerts;
use App\Shared\Application\Observability\Health\RunHealthChecks;
use Illuminate\Console\Command;

final class HealthAlertsCommand extends Command
{
    protected $signature = 'health:alerts';

    protected $description = 'Evaluate health checks and raise or resolve operational alerts';

    public function handle(RunHealthChecks $checks, EvaluateAlerts $alerts): int
    {
        $alerts->execute($checks->execute());

        return self::SUCCESS;
    }
}
