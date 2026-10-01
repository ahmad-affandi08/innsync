<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Deployment;

use App\Shared\Application\Deployment\Findings;
use App\Shared\Application\Deployment\Severity;
use App\Shared\Application\Deployment\SmokeEvaluator;
use App\Shared\Application\Deployment\SmokeResponse;
use PHPUnit\Framework\TestCase;

final class SmokeEvaluatorTest extends TestCase
{
    private const GOOD_COOKIE = 'innsync_session=abc; path=/; httponly; secure; samesite=lax';

    /** @return array<string, SmokeResponse> */
    private function healthy(array $override = []): array
    {
        $responses = [
            SmokeEvaluator::LIVENESS => new SmokeResponse(200),
            SmokeEvaluator::READINESS => new SmokeResponse(200),
            SmokeEvaluator::DETAILS => new SmokeResponse(404),
            SmokeEvaluator::LOGIN => new SmokeResponse(200, ['x-correlation-id' => ['01ARZ'], 'set-cookie' => [self::GOOD_COOKIE]]),
        ];

        foreach (SmokeEvaluator::FORBIDDEN_PATHS as $path) {
            $responses[$path] = new SmokeResponse(404);
        }

        return array_merge($responses, $override);
    }

    private function evaluate(array $responses, string $url = 'https://hotel.example', bool $allowInsecure = false): Findings
    {
        return (new SmokeEvaluator)->evaluate($url, $responses, $allowInsecure);
    }

    private function severity(Findings $findings, string $check): ?Severity
    {
        foreach ($findings->items as $finding) {
            if ($finding->check === $check) {
                return $finding->severity;
            }
        }

        return null;
    }

    public function test_a_correctly_released_site_passes(): void
    {
        $findings = $this->evaluate($this->healthy());

        self::assertTrue($findings->passes(strict: true));
        self::assertGreaterThan(15, count($findings->items));
    }

    public function test_every_requested_path_is_covered_by_the_evaluator(): void
    {
        $paths = SmokeEvaluator::paths();

        self::assertSame(count($paths), count(array_unique($paths)));
        self::assertContains('/.env', $paths);
        self::assertContains('/storage/logs/laravel.log', $paths);
        self::assertContains('/.git/config', $paths);
    }

    public function test_a_served_repository_file_fails_with_its_path(): void
    {
        foreach (SmokeEvaluator::FORBIDDEN_PATHS as $path) {
            $findings = $this->evaluate($this->healthy([$path => new SmokeResponse(200, [], 'anything')]));

            self::assertSame(Severity::Failure, $this->severity($findings, 'exposure'.$path), $path);
            self::assertFalse($findings->passes(), $path);
        }
    }

    public function test_leaked_content_fails_even_when_the_status_looks_harmless(): void
    {
        foreach (["APP_KEY=base64:xyz\nDB_PASSWORD=p", '<?php return [];'] as $body) {
            $findings = $this->evaluate($this->healthy(['/config/app.php' => new SmokeResponse(403, [], $body)]));

            self::assertSame(Severity::Failure, $this->severity($findings, 'exposure/config/app.php'));
        }
    }

    public function test_denial_and_redirect_statuses_count_as_not_served(): void
    {
        foreach ([301, 302, 401, 403, 404, 405, 410] as $status) {
            $findings = $this->evaluate($this->healthy(['/.env' => new SmokeResponse($status)]));

            self::assertSame(Severity::Ok, $this->severity($findings, 'exposure/.env'), (string) $status);
        }
    }

    public function test_an_unreachable_path_is_a_failure_not_a_pass(): void
    {
        $findings = $this->evaluate($this->healthy(['/.env' => new SmokeResponse(0, error: 'connection failed')]));

        self::assertSame(Severity::Failure, $this->severity($findings, 'exposure/.env'));
    }

    public function test_health_endpoints(): void
    {
        self::assertSame(Severity::Failure, $this->severity($this->evaluate($this->healthy([SmokeEvaluator::LIVENESS => new SmokeResponse(500)])), 'liveness'));
        self::assertSame(Severity::Failure, $this->severity($this->evaluate($this->healthy([SmokeEvaluator::READINESS => new SmokeResponse(503)])), 'readiness'));
        // Detailed health must stay hidden without the secret.
        self::assertSame(Severity::Failure, $this->severity($this->evaluate($this->healthy([SmokeEvaluator::DETAILS => new SmokeResponse(200)])), 'health.details'));
        self::assertSame(Severity::Failure, $this->severity($this->evaluate($this->healthy([SmokeEvaluator::DETAILS => new SmokeResponse(401)])), 'health.details'));
    }

    public function test_plain_http_is_refused_unless_explicitly_allowed(): void
    {
        $http = 'http://hotel.example';
        $cookie = ['set-cookie' => ['innsync_session=abc; httponly; samesite=lax'], 'x-correlation-id' => ['x']];
        $responses = $this->healthy([SmokeEvaluator::LOGIN => new SmokeResponse(200, $cookie)]);

        self::assertSame(Severity::Failure, $this->severity($this->evaluate($responses, $http), 'transport'));
        self::assertTrue($this->evaluate($responses, $http, allowInsecure: true)->passes());
    }

    public function test_session_cookie_flags_are_enforced(): void
    {
        $login = static fn (string $cookie) => new SmokeResponse(200, ['x-correlation-id' => ['x'], 'set-cookie' => [$cookie]]);

        $cases = [
            'innsync_session=a; secure; samesite=lax' => 'HttpOnly',
            'innsync_session=a; httponly; secure' => 'SameSite',
            'innsync_session=a; httponly; samesite=lax' => 'Secure',
        ];

        foreach ($cases as $cookie => $missing) {
            $findings = $this->evaluate($this->healthy([SmokeEvaluator::LOGIN => $login($cookie)]));
            $message = '';
            foreach ($findings->items as $finding) {
                if ($finding->check === 'cookie.session') {
                    $message = $finding->message;
                }
            }

            self::assertSame(Severity::Failure, $this->severity($findings, 'cookie.session'), $cookie);
            self::assertStringContainsString($missing, $message);
        }

        // No session cookie at all cannot be inspected and is not a pass.
        $none = $this->evaluate($this->healthy([SmokeEvaluator::LOGIN => new SmokeResponse(200, ['x-correlation-id' => ['x']])]));
        self::assertSame(Severity::Failure, $this->severity($none, 'cookie.session'));
    }

    public function test_the_session_cookie_is_found_under_laravels_default_hyphenated_name(): void
    {
        $login = static fn (array $cookies) => new SmokeResponse(200, ['x-correlation-id' => ['x'], 'set-cookie' => $cookies]);

        foreach (['innsync-session=a; httponly; secure; samesite=lax', 'laravel_session=a; httponly; secure; samesite=lax'] as $cookie) {
            $findings = $this->evaluate($this->healthy([SmokeEvaluator::LOGIN => $login(['XSRF-TOKEN=t; secure; samesite=lax', $cookie])]));

            self::assertSame(Severity::Ok, $this->severity($findings, 'cookie.session'), $cookie);
        }

        // The XSRF cookie alone is not the session cookie and must not satisfy the check.
        $only = $this->evaluate($this->healthy([SmokeEvaluator::LOGIN => $login(['XSRF-TOKEN=t; secure; samesite=lax'])]));
        self::assertSame(Severity::Failure, $this->severity($only, 'cookie.session'));
    }

    public function test_missing_correlation_id_fails(): void
    {
        $findings = $this->evaluate($this->healthy([SmokeEvaluator::LOGIN => new SmokeResponse(200, ['set-cookie' => [self::GOOD_COOKIE]])]));

        self::assertSame(Severity::Failure, $this->severity($findings, 'correlation.id'));
    }

    public function test_header_lookup_is_case_insensitive_by_contract(): void
    {
        $response = new SmokeResponse(200, ['x-correlation-id' => ['a', 'b']]);

        self::assertSame('a, b', $response->header('X-Correlation-ID'));
        self::assertNull($response->header('missing'));
    }
}
