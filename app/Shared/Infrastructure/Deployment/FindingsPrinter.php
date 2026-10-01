<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Deployment;

use App\Shared\Application\Deployment\Findings;
use App\Shared\Application\Deployment\Severity;
use Illuminate\Console\Command;

final class FindingsPrinter
{
    public static function print(Command $command, Findings $findings, bool $json): void
    {
        if ($json) {
            $command->line((string) json_encode([
                'passed' => $findings->passes(),
                'failures' => $findings->count(Severity::Failure),
                'warnings' => $findings->count(Severity::Warning),
                'findings' => $findings->toArray(),
            ], JSON_UNESCAPED_SLASHES));

            return;
        }

        foreach ($findings->items as $finding) {
            $label = match ($finding->severity) {
                Severity::Ok => '<info> OK   </info>',
                Severity::Warning => '<comment> WARN </comment>',
                Severity::Failure => '<error> FAIL </error>',
            };
            $command->line("{$label} {$finding->check}: {$finding->message}");
        }

        $command->newLine();
        $command->line(sprintf(
            '%d failure(s), %d warning(s).',
            $findings->count(Severity::Failure),
            $findings->count(Severity::Warning),
        ));
    }
}
