<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Errors;

use App\Shared\Application\Concurrency\OptimisticLockConflict;
use App\Shared\Application\Errors\ExpectedFailure;
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
        ExpectedFailure::class,
    ];

    public function make(Throwable $e): ErrorEnvelope
    {
        return match (true) {
            $e instanceof ExpectedFailure => self::expected($e),
            $e instanceof ValidationException => new ErrorEnvelope(
                422, 'validation_failed', __('errors.validation_failed'),
                fields: self::fields($e),
            ),
            $e instanceof OptimisticLockConflict => self::conflict(
                'optimistic_lock', 'refresh', __('errors.conflict_optimistic_lock'),
            ),
            $e instanceof IdempotencyConflict => self::conflict(
                'idempotency_'.$e->reasonCode, 'review', __('errors.conflict_idempotency_mismatch'),
            ),
            $e instanceof IdempotencyOperationIncomplete => self::conflict(
                'idempotency_in_progress', 'retry', __('errors.conflict_idempotency_in_progress'), retryable: true,
            ),
            $e instanceof FileRejected => new ErrorEnvelope(422, 'file_rejected', __('errors.file_rejected')),
            $e instanceof StoredFileNotFound,
            $e instanceof ModelNotFoundException => new ErrorEnvelope(404, 'not_found', __('errors.not_found')),
            $e instanceof FileAccessDenied,
            $e instanceof AuthorizationException,
            $e instanceof PropertyScopeViolation => new ErrorEnvelope(403, 'forbidden', __('errors.forbidden')),
            $e instanceof MissingPropertyContext => new ErrorEnvelope(403, 'property_context_required', __('errors.property_context_required')),
            $e instanceof AuthenticationException => new ErrorEnvelope(401, 'unauthenticated', __('errors.unauthenticated')),
            $e instanceof TokenMismatchException => new ErrorEnvelope(419, 'session_expired', __('errors.session_expired')),
            $e instanceof HttpExceptionInterface => self::http($e->getStatusCode()),
            default => new ErrorEnvelope(500, 'server_error', __('errors.server_error'), retryable: true),
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

    private static function expected(ExpectedFailure $failure): ErrorEnvelope
    {
        $message = __('errors.'.$failure->messageKey());

        if ($failure->status() === 409 && $failure->conflict() !== null) {
            return new ErrorEnvelope(409, 'conflict', $message, conflict: $failure->conflict());
        }

        if ($failure->status() === 422) {
            $fields = [];

            foreach ($failure->invalidFields() as $field) {
                $fields[$field] = [__('validation.required', ['attribute' => __("validation.attributes.{$field}") === "validation.attributes.{$field}" ? $field : __("validation.attributes.{$field}")])];
            }

            return new ErrorEnvelope(422, 'validation_failed', $message, fields: $fields === [] ? null : $fields);
        }

        return new ErrorEnvelope($failure->status(), $failure->errorCode(), $message);
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
            400 => new ErrorEnvelope(400, 'bad_request', __('errors.bad_request')),
            401 => new ErrorEnvelope(401, 'unauthenticated', __('errors.unauthenticated')),
            403 => new ErrorEnvelope(403, 'forbidden', __('errors.forbidden')),
            404 => new ErrorEnvelope(404, 'not_found', __('errors.not_found')),
            405 => new ErrorEnvelope(405, 'method_not_allowed', __('errors.method_not_allowed')),
            409 => self::conflict('unspecified', 'review', __('errors.conflict_unspecified')),
            413 => new ErrorEnvelope(413, 'payload_too_large', __('errors.payload_too_large')),
            419 => new ErrorEnvelope(419, 'session_expired', __('errors.session_expired')),
            422 => new ErrorEnvelope(422, 'unprocessable', __('errors.unprocessable')),
            429 => new ErrorEnvelope(429, 'too_many_requests', __('errors.too_many_requests'), retryable: true),
            503 => new ErrorEnvelope(503, 'unavailable', __('errors.unavailable'), retryable: true),
            default => $status >= 500
                ? new ErrorEnvelope($status, 'server_error', __('errors.server_error'), retryable: true)
                : new ErrorEnvelope($status, 'request_failed', __('errors.request_failed')),
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
