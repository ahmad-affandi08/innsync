<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Deployment;

use App\Shared\Application\Deployment\SmokeEvaluator;
use App\Shared\Application\Deployment\SmokeResponse;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class SmokeCommand extends Command
{
    protected $signature = 'deploy:smoke {url : Public base URL of the released site} {--allow-insecure : Permit http:// for a rehearsal} {--json : Machine-readable output}';

    protected $description = 'Verify a released site from the outside: health, secure layout, session cookie flags';

    public function handle(SmokeEvaluator $evaluator): int
    {
        $base = rtrim((string) $this->argument('url'), '/');

        if (filter_var($base, FILTER_VALIDATE_URL) === false || ! in_array(parse_url($base, PHP_URL_SCHEME), ['http', 'https'], true)) {
            $this->error('The URL must be an absolute http(s) address.');

            return self::INVALID;
        }

        $responses = [];

        foreach (SmokeEvaluator::paths() as $path) {
            $responses[$path] = $this->request($base.$path);
        }

        $findings = $evaluator->evaluate($base, $responses, (bool) $this->option('allow-insecure'));

        FindingsPrinter::print($this, $findings, (bool) $this->option('json'));

        return $findings->passes() ? self::SUCCESS : self::FAILURE;
    }

    private function request(string $url): SmokeResponse
    {
        try {
            $response = Http::timeout(15)
                ->withoutRedirecting()
                ->withHeaders(['Accept' => 'text/html,application/json', 'User-Agent' => 'InnSYnc-release-smoke'])
                ->get($url);
        } catch (ConnectionException) {
            return new SmokeResponse(0, error: 'connection failed');
        }

        // Only the start of the body is needed to recognise leaked secrets or source.
        return new SmokeResponse(
            $response->status(),
            array_change_key_case($response->headers(), CASE_LOWER),
            substr($response->body(), 0, 2048),
        );
    }
}
