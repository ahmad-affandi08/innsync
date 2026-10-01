<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AssignCorrelationId
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get('X-Correlation-ID');
        $correlationId = is_string($incoming) && Str::isUlid($incoming)
            ? strtolower($incoming)
            : strtolower((string) Str::ulid());

        Context::add('correlation_id', $correlationId);

        $ipAddress = $request->ip();
        if (is_string($ipAddress) && $ipAddress !== '') {
            Context::addHidden(
                'security_source_ip_hash',
                hash_hmac('sha256', $ipAddress, (string) config('app.key')),
            );
        }

        $response = $next($request);
        $response->headers->set('X-Correlation-ID', $correlationId);

        return $response;
    }
}
