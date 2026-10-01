<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Idempotency;

use App\Shared\Application\Idempotency\IdempotencyContext;
use App\Shared\Application\Idempotency\IdempotencyKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireIdempotencyKey
{
    public function __construct(
        private IdempotencyContext $idempotencyContext,
        private IdempotencyPayloadCodec $codec,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $key = IdempotencyKey::fromString((string) $request->header('Idempotency-Key'));
        } catch (InvalidArgumentException) {
            abort(400, 'A valid Idempotency-Key header is required.');
        }

        $this->idempotencyContext->activate($key);
        Context::add('idempotency_key_hash', $this->codec->keyHash($key->toString()));

        try {
            return $next($request);
        } finally {
            $this->idempotencyContext->clear();
        }
    }
}
