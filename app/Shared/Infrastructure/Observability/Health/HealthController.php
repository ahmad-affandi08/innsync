<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability\Health;

use App\Shared\Application\Observability\Health\HealthStatus;
use App\Shared\Application\Observability\Health\RunHealthChecks;
use App\Shared\Application\Security\SecurityEvent;
use App\Shared\Application\Security\SecurityEventOutcome;
use App\Shared\Application\Security\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class HealthController
{
    public function __construct(
        private RunHealthChecks $checks,
        private SecurityLog $securityLog,
    ) {}

    /** Public readiness probe: overall status only, nothing about internals. */
    public function summary(): JsonResponse
    {
        $status = $this->checks->execute()->status();

        return $this->respond(['status' => $status->value], $status);
    }

    /** Per-check detail for monitors holding the bearer secret; 404 when no secret is configured. */
    public function details(Request $request): JsonResponse
    {
        $secret = config('observability.health_token');

        if (! is_string($secret) || $secret === '') {
            abort(404);
        }

        $presented = (string) $request->bearerToken();

        if (! hash_equals(hash('sha256', $secret), hash('sha256', $presented))) {
            $this->securityLog->record(new SecurityEvent('health.details-denied', SecurityEventOutcome::Denied));

            abort(404);
        }

        $report = $this->checks->execute();

        return $this->respond($report->toArray(), $report->status());
    }

    /** @param array<string, mixed> $body */
    private function respond(array $body, HealthStatus $status): JsonResponse
    {
        return new JsonResponse(
            $body,
            $status === HealthStatus::Down ? 503 : 200,
            ['Cache-Control' => 'no-store'],
        );
    }
}
