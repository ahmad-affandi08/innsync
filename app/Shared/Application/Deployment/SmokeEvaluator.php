<?php

declare(strict_types=1);

namespace App\Shared\Application\Deployment;

/**
 * Judges a released site from the outside. This is the proof that the secure
 * directory layout holds: the domain must expose Laravel `public/` only, never
 * the repository root (docs/ARCHITECTURE/15, docs/SKILL/07).
 */
final class SmokeEvaluator
{
    public const LIVENESS = '/up';

    public const READINESS = '/health';

    public const DETAILS = '/health/details';

    public const LOGIN = '/login';

    /**
     * Paths that exist in the repository but must never be served.
     *
     * @var list<string>
     */
    public const FORBIDDEN_PATHS = [
        '/.env',
        '/.env.example',
        '/composer.json',
        '/composer.lock',
        '/artisan',
        '/package.json',
        '/vendor/autoload.php',
        '/bootstrap/app.php',
        '/config/app.php',
        '/storage/logs/laravel.log',
        '/storage/app/private-files',
        '/.git/config',
        '/app/Providers/AppServiceProvider.php',
    ];

    /** @return list<string> every path the evaluator needs, for the collector */
    public static function paths(): array
    {
        return [...[self::LIVENESS, self::READINESS, self::DETAILS, self::LOGIN], ...self::FORBIDDEN_PATHS];
    }

    /** @param array<string, SmokeResponse> $responses path => response */
    public function evaluate(string $baseUrl, array $responses, bool $allowInsecure = false): Findings
    {
        $findings = [];
        $https = str_starts_with(strtolower($baseUrl), 'https://');

        $findings[] = $https || $allowInsecure
            ? Finding::ok('transport', $https ? 'The site is served over HTTPS.' : 'Plain HTTP allowed explicitly for this run.')
            : Finding::failure('transport', 'The site must be served over HTTPS.');

        $findings[] = $this->expectStatus($responses, self::LIVENESS, [200], 'liveness', 'The application is up.', 'The liveness endpoint /up did not return 200.');
        $findings[] = $this->expectStatus($responses, self::READINESS, [200], 'readiness', 'Readiness reports no critical failure.', 'The readiness endpoint /health did not return 200 (a critical check is down or the site is unreachable).');
        $findings[] = $this->expectStatus($responses, self::DETAILS, [404], 'health.details', 'Detailed health is hidden without the secret.', 'GET /health/details must be 404 without the bearer secret.');

        foreach (self::FORBIDDEN_PATHS as $path) {
            $findings[] = $this->forbidden($path, $responses[$path] ?? null);
        }

        $login = $responses[self::LOGIN] ?? null;
        $findings[] = $login === null || $login->status !== 200
            ? Finding::failure('login.page', 'The sign-in page did not return 200.')
            : Finding::ok('login.page', 'The sign-in page is served.');

        if ($login !== null) {
            $findings[] = $login->header('x-correlation-id') !== null
                ? Finding::ok('correlation.id', 'Responses carry a correlation ID.')
                : Finding::failure('correlation.id', 'Responses carry no X-Correlation-ID header.');
            $findings[] = $this->cookies($login, $https);
        }

        return new Findings($findings);
    }

    /**
     * @param  array<string, SmokeResponse>  $responses
     * @param  list<int>  $accepted
     */
    private function expectStatus(array $responses, string $path, array $accepted, string $check, string $okMessage, string $badMessage): Finding
    {
        $response = $responses[$path] ?? null;

        return $response !== null && in_array($response->status, $accepted, true)
            ? Finding::ok($check, $okMessage)
            : Finding::failure($check, $badMessage.($response?->error !== null ? ' ('.$response->error.')' : ''));
    }

    private function forbidden(string $path, ?SmokeResponse $response): Finding
    {
        $check = 'exposure'.$path;

        if ($response === null || $response->status === 0) {
            return Finding::failure($check, "{$path} could not be checked: ".($response?->error ?? 'no response').'.');
        }

        $leaksContent = $response->status === 200
            || preg_match('/^\s*(APP_KEY=|DB_PASSWORD=|<\?php)/m', $response->body) === 1;

        return $leaksContent
            ? Finding::failure($check, "{$path} is publicly served. The document root exposes more than Laravel public/.")
            : Finding::ok($check, "{$path} is not served ({$response->status}).");
    }

    private function cookies(SmokeResponse $login, bool $https): Finding
    {
        $session = array_values(array_filter(
            $login->setCookies(),
            // Laravel names it `<app-slug>-session` by default; configurations may use an underscore.
            static fn (string $cookie): bool => preg_match('/^\s*[^=;]*session=/i', $cookie) === 1,
        ));

        if ($session === []) {
            return Finding::failure('cookie.session', 'The sign-in page set no session cookie to inspect.');
        }

        $problems = [];

        foreach ($session as $cookie) {
            if (stripos($cookie, 'httponly') === false) {
                $problems[] = 'HttpOnly';
            }
            if (stripos($cookie, 'samesite=') === false) {
                $problems[] = 'SameSite';
            }
            if ($https && stripos($cookie, 'secure') === false) {
                $problems[] = 'Secure';
            }
        }

        return $problems === []
            ? Finding::ok('cookie.session', 'The session cookie is HttpOnly, SameSite'.($https ? ' and Secure' : '').'.')
            : Finding::failure('cookie.session', 'The session cookie lacks: '.implode(', ', array_unique($problems)).'.');
    }
}
