<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Idempotency;

use App\Shared\Application\Idempotency\IdempotencyContext;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Idempotency\IdempotencyRequest;
use App\Shared\Application\Idempotency\IdempotentExecutor;
use App\Shared\Application\Tenancy\PropertyContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires the `Idempotency-Key` header. With `idempotent:once` the key is also kept: the first successful answer is stored with the key and every retry of the same request gets that answer back,
 * with nothing done twice (CON-OP-01/02). A request that fails is rolled back and stored nowhere, so it can be tried again with the same key. Services that keep the key themselves use plain `idempotent`.
 */
final readonly class RequireIdempotencyKey
{
    public function __construct(
        private IdempotencyContext $idempotencyContext,
        private IdempotencyPayloadCodec $codec,
        private IdempotentExecutor $executor,
        private PropertyContext $property,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string $mode = ''): Response
    {
        try {
            $key = IdempotencyKey::fromString((string) $request->header('Idempotency-Key'));
        } catch (InvalidArgumentException) {
            abort(400, 'A valid Idempotency-Key header is required.');
        }

        $this->idempotencyContext->activate($key);
        Context::add('idempotency_key_hash', $this->codec->keyHash($key->toString()));

        try {
            return $mode === 'once' && $this->property->hasActiveProperty() && $request->user() !== null ? $this->once($request, $next, $key) : $next($request);
        } finally {
            $this->idempotencyContext->clear();
        }
    }

    /** @param Closure(Request): Response $next */
    private function once(Request $request, Closure $next, IdempotencyKey $key): Response
    {
        $failed = null;
        $original = null;
        $name = 'http.'.preg_replace('/[^a-z0-9._-]+/', '-', strtolower((string) ($request->route()?->getName() ?? $request->path())));

        try {
            $once = $this->executor->execute(
                new IdempotencyRequest($this->property->current(), $key, substr($name, 0, 100), ['path' => $request->path(), 'body' => $request->isJson() ? $request->json()->all() : $request->post()], strtolower((string) $request->user()->getKey())),
                function () use ($request, $next, &$failed, &$original): array {
                    $response = $original = $next($request);

                    if ($response->getStatusCode() >= 400) {
                        // Nothing of a refused request is kept, and the key stays free for the next try.
                        $failed = $response;

                        throw new RuntimeException('Refused: rolled back.');
                    }

                    return $response instanceof JsonResponse
                        ? ['status' => $response->getStatusCode(), 'json' => $response->getData(true)]
                        : ['status' => $response->getStatusCode(), 'location' => $response instanceof RedirectResponse ? $response->getTargetUrl() : null];
                },
            );
        } catch (RuntimeException $exception) {
            return $failed ?? throw $exception;
        }

        // The first answer goes out as it is made; a retry gets the stored copy.
        return $once->replayed ? $this->rebuild($once->payload) : $original;
    }

    /** @param array<string, mixed> $answer */
    private function rebuild(array $answer): Response
    {
        $headers = ['Idempotent-Replayed' => 'true', 'Cache-Control' => 'no-store, private'];

        return array_key_exists('json', $answer)
            ? new JsonResponse($answer['json'], (int) $answer['status'], $headers)
            : (isset($answer['location']) ? new RedirectResponse((string) $answer['location'], (int) $answer['status'], $headers) : new Response('', (int) $answer['status'], $headers));
    }
}
