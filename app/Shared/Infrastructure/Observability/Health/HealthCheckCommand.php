<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability\Health;

use App\Shared\Application\Observability\Health\HealthStatus;
use App\Shared\Application\Observability\Health\RunHealthChecks;
use Illuminate\Console\Command;

final class HealthCheckCommand extends Command
{
    protected $signature = 'health:check {--json : Print the report as JSON}';

    protected $description = 'Run operational health checks; exits non-zero when any check is down';

    public function handle(RunHealthChecks $checks): int
    {
        $report = $checks->execute();

        if ($this->option('json')) {
            $this->line(json_encode($report->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            foreach ($report->results as $name => $result) {
                $this->line(sprintf('%-18s %-9s %s', $name, $result->status->value, $result->summary));
            }
        }

        return $report->status() === HealthStatus::Down ? self::FAILURE : self::SUCCESS;
    }
}
