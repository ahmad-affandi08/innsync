<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Shared\Application\Deployment\SmokeEvaluator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class DeploymentCommandsTest extends TestCase
{
    private const COOKIE = 'innsync_session=abc; path=/; httponly; secure; samesite=lax';

    /** @return array<string, mixed> */
    private function report(string $command, array $arguments = []): array
    {
        Artisan::call($command, [...$arguments, '--json' => true]);

        $decoded = json_decode(trim(Artisan::output()), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function test_preflight_reports_findings_for_the_real_environment_as_json(): void
    {
        $report = $this->report('deploy:preflight');

        self::assertArrayHasKey('findings', $report);
        self::assertSame(0, $report['failures'], json_encode($report['findings']));

        $checks = array_column($report['findings'], 'severity', 'check');
        self::assertSame('ok', $checks['php.version']);
        self::assertSame('ok', $checks['php.extensions']);
        self::assertSame('ok', $checks['database.version']);
        self::assertSame('ok', $checks['inertia.ssr']);
        // The test environment is not production, so production-only rules downgrade to warnings.
        self::assertSame('warning', $checks['app.env']);
    }

    public function test_preflight_exit_code_follows_failures_and_strictness(): void
    {
        self::assertSame(0, Artisan::call('deploy:preflight'));
        // Warnings (non-production, no backup) fail a strict run.
        self::assertSame(1, Artisan::call('deploy:preflight', ['--strict' => true]));

        config(['app.env' => 'production', 'app.debug' => true]);
        self::assertSame(1, Artisan::call('deploy:preflight'));
        self::assertStringContainsString('app.debug', Artisan::output());
    }

    public function test_preflight_never_prints_secrets(): void
    {
        config(['app.key' => 'base64:SUPERSECRETKEYVALUE', 'database.connections.mysql.password' => 'hunter2']);
        Artisan::call('deploy:preflight', ['--json' => true]);

        self::assertStringNotContainsString('SUPERSECRETKEYVALUE', Artisan::output());
        self::assertStringNotContainsString('hunter2', Artisan::output());
    }

    /** @param array<string, array{0: int, 1?: array<string, mixed>, 2?: string}> $overrides */
    private function fakeSite(array $overrides = []): void
    {
        Http::fake(function (Request $request) use ($overrides) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $path = is_string($path) ? $path : '/';

            [$status, $headers, $body] = ($overrides[$path] ?? match (true) {
                $path === '/up', $path === '/health' => [200],
                $path === '/login' => [200, ['X-Correlation-ID' => '01ARZ', 'Set-Cookie' => self::COOKIE]],
                default => [404],
            }) + [1 => [], 2 => ''];

            return Http::response($body, $status, $headers);
        });
    }

    public function test_smoke_passes_against_a_correctly_released_site(): void
    {
        $this->fakeSite();

        $report = $this->report('deploy:smoke', ['url' => 'https://hotel.example']);

        self::assertTrue($report['passed'], json_encode($report['findings']));
        self::assertSame(0, $report['failures']);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://hotel.example/'));
    }

    public function test_smoke_checks_every_path_the_evaluator_requires_and_never_follows_redirects(): void
    {
        $this->fakeSite();
        Artisan::call('deploy:smoke', ['url' => 'https://hotel.example/']);

        foreach (SmokeEvaluator::paths() as $path) {
            Http::assertSent(fn (Request $request): bool => $request->url() === 'https://hotel.example'.$path);
        }
    }

    public function test_smoke_fails_when_the_environment_file_is_served(): void
    {
        $this->fakeSite(['/.env' => [200, [], "APP_KEY=base64:leak\n"]]);

        self::assertSame(1, Artisan::call('deploy:smoke', ['url' => 'https://hotel.example']));
        self::assertStringContainsString('/.env is publicly served', Artisan::output());
    }

    public function test_smoke_refuses_plain_http_unless_allowed_and_rejects_bad_urls(): void
    {
        $this->fakeSite(['/login' => [200, ['X-Correlation-ID' => 'x', 'Set-Cookie' => 'innsync_session=a; httponly; samesite=lax']]]);

        self::assertSame(1, Artisan::call('deploy:smoke', ['url' => 'http://hotel.example']));
        self::assertSame(0, Artisan::call('deploy:smoke', ['url' => 'http://hotel.example', '--allow-insecure' => true]));

        foreach (['not a url', 'ftp://hotel.example', 'hotel.example'] as $bad) {
            self::assertSame(2, Artisan::call('deploy:smoke', ['url' => $bad]), $bad);
        }
    }

    public function test_smoke_treats_an_unreachable_site_as_failure(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 6'));

        self::assertSame(1, Artisan::call('deploy:smoke', ['url' => 'https://hotel.example']));
        self::assertStringNotContainsString('cURL', Artisan::output());
    }
}
