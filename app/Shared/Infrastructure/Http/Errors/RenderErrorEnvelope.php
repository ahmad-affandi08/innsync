<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Errors;

use App\Shared\Application\Errors\ExpectedFailure;
use App\Shared\Application\Observability\CorrelationId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * JSON/API requests get the envelope. Inertia mutations get a redirect back carrying the same
 * conflict/forbidden message in the `error` bag so forms can show it. Everything else keeps
 * framework behavior (validation redirects, error pages).
 */
final readonly class RenderErrorEnvelope
{
    public function __construct(
        private ErrorEnvelopeFactory $factory,
        private CorrelationId $correlationId,
    ) {}

    public function __invoke(Throwable $e, Request $request): ?Response
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            $envelope = $this->factory->make($e);

            return new JsonResponse(
                $envelope->toArray($this->correlationId->current()),
                $envelope->status,
                $this->factory->headers($e),
            );
        }

        if ($request->header('X-Inertia') !== null
            && ! $request->isMethodSafe()
            && ! $e instanceof ValidationException
            && in_array($e::class, ErrorEnvelopeFactory::EXPECTED, true)) {
            return back(303)->withErrors(['error' => $this->factory->make($e)->message]);
        }

        // A person who opens a screen they may not use sees a page that says so, in the frame of the application, not a 500.
        if ($e instanceof ExpectedFailure) {
            $status = $e->status();
            $message = __('errors.'.$e->messageKey());

            if ($request->isMethodSafe() && $request->hasSession()) {
                return Inertia::render('foundation/pages/error', ['status' => $status, 'message' => $message])->toResponse($request)->setStatusCode($status);
            }

            $view = "errors::{$status}";

            return view()->exists($view)
                ? response()->view($view, ['exception' => new HttpException($status)], $status)
                : response($message, $status);
        }

        return null;
    }
}
