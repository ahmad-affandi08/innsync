<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Errors;

use App\Shared\Application\Concurrency\OptimisticLockConflict;
use App\Shared\Application\Files\FileAccessDenied;
use App\Shared\Application\Files\FileRejected;
use App\Shared\Application\Files\StoredFileNotFound;
use App\Shared\Application\Idempotency\IdempotencyConflict;
use App\Shared\Application\Idempotency\IdempotencyOperationIncomplete;
use App\Shared\Application\Tenancy\MissingPropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ErrorEnvelopeFactory
{
    /** Exceptions that are expected outcomes, not defects: never reported as errors. */
    public const EXPECTED = [
        OptimisticLockConflict::class,
        IdempotencyConflict::class,
        IdempotencyOperationIncomplete::class,
        FileRejected::class,
        FileAccessDenied::class,
        StoredFileNotFound::class,
        MissingPropertyContext::class,
        PropertyScopeViolation::class,
    ];

    public function make(Throwable $e): ErrorEnvelope
    {
        return match (true) {
            $e instanceof ValidationException => new ErrorEnvelope(
                422, 'validation_failed', 'The submitted data is invalid.',
                fields: self::fields($e),
            ),
            $e instanceof OptimisticLockConflict => self::conflict(
                'optimistic_lock', 'refresh', 'This record was changed by someone else. Refresh and review before retrying.',
            ),
            $e instanceof IdempotencyConflict => self::conflict(
                'idempotency_'.$e->reasonCode, 'review', 'This request key was already used for a different request.',
            ),
            $e instanceof IdempotencyOperationIncomplete => self::conflict(
                'idempotency_in_progress', 'retry', 'The original request is still being processed. Retry shortly.', retryable: true,
            ),
            $e instanceof FileRejected => new ErrorEnvelope(422, 'file_rejected', 'The file was rejected.'),
            $e instanceof StoredFileNotFound,
            $e instanceof ModelNotFoundException => new ErrorEnvelope(404, 'not_found', 'The resource was not found.'),
            $e instanceof FileAccessDenied,
            $e instanceof AuthorizationException,
            $e instanceof PropertyScopeViolation => new ErrorEnvelope(403, 'forbidden', 'You are not allowed to perform this action.'),
            $e instanceof MissingPropertyContext => new ErrorEnvelope(403, 'property_context_required', 'Select a property to continue.'),
            $e instanceof AuthenticationException => new ErrorEnvelope(401, 'unauthenticated', 'Authentication is required.'),
            $e instanceof TokenMismatchException => new ErrorEnvelope(419, 'session_expired', 'The session expired. Reload and try again.'),
            $e instanceof HttpExceptionInterface => self::http($e->getStatusCode()),
            default => new ErrorEnvelope(500, 'server_error', 'An unexpected error occurred.', retryable: true),
        };
    }

    /** @return array<string, string> */
    public function headers(Throwable $e): array
    {
        if (! $e instanceof HttpExceptionInterface) {
            return [];
        }

        return array_intersect_key(
            array_map(static fn (mixed $v): string => implode(',', (array) $v), $e->getHeaders()),
            array_flip(['Retry-After', 'Allow', 'WWW-Authenticate']),
        );
    }

    private static function conflict(string $reason, string $action, string $message, bool $retryable = false): ErrorEnvelope
    {
        return new ErrorEnvelope(
            409, 'conflict', $message, $retryable,
            conflict: ['reason' => $reason, 'action' => $action],
        );
    }

    private static function http(int $status): ErrorEnvelope
    {
        return match ($status) {
            400 => new ErrorEnvelope(400, 'bad_request', 'The request is malformed.'),
            401 => new ErrorEnvelope(401, 'unauthenticated', 'Authentication is required.'),
            403 => new ErrorEnvelope(403, 'forbidden', 'You are not allowed to perform this action.'),
            404 => new ErrorEnvelope(404, 'not_found', 'The resource was not found.'),
            405 => new ErrorEnvelope(405, 'method_not_allowed', 'This method is not allowed.'),
            409 => self::conflict('unspecified', 'review', 'The request conflicts with the current state.'),
            413 => new ErrorEnvelope(413, 'payload_too_large', 'The request is too large.'),
            419 => new ErrorEnvelope(419, 'session_expired', 'The session expired. Reload and try again.'),
            422 => new ErrorEnvelope(422, 'unprocessable', 'The request could not be processed.'),
            429 => new ErrorEnvelope(429, 'too_many_requests', 'Too many requests. Retry later.', retryable: true),
            503 => new ErrorEnvelope(503, 'unavailable', 'The service is temporarily unavailable.', retryable: true),
            default => $status >= 500
                ? new ErrorEnvelope($status, 'server_error', 'An unexpected error occurred.', retryable: true)
                : new ErrorEnvelope($status, 'request_failed', 'The request failed.'),
        };
    }

    /** @return array<string, list<string>> */
    private static function fields(ValidationException $e): array
    {
        $fields = [];

        foreach ($e->errors() as $name => $messages) {
            $fields[(string) $name] = array_values(array_map('strval', $messages));
        }

        return $fields;
    }
}
