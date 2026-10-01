<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Deployment;

use App\Shared\Application\Deployment\PreflightEvaluator;
use Illuminate\Console\Command;

final class PreflightCommand extends Command
{
    protected $signature = 'deploy:preflight {--strict : Treat warnings as failures} {--json : Machine-readable output}';

    protected $description = 'Check that this environment can safely receive a release (run on the target host before migrating)';

    public function handle(PreflightCollector $collector, PreflightEvaluator $evaluator): int
    {
        $findings = $evaluator->evaluate($collector->collect());

        FindingsPrinter::print($this, $findings, (bool) $this->option('json'));

        return $findings->passes((bool) $this->option('strict')) ? self::SUCCESS : self::FAILURE;
    }
}
