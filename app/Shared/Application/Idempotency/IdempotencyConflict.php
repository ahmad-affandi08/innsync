<?php

declare(strict_types=1);

namespace App\Shared\Application\Idempotency;

use RuntimeException;

final class IdempotencyConflict extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $reasonCode,
    ) {
        parent::__construct($message);
    }

    public static function requestMismatch(): self
    {
        return new self(
            'The idempotency key was already used with a different request.',
            'request_mismatch',
        );
    }

    public static function actorMismatch(): self
    {
        return new self(
            'The idempotency key was already used by a different actor.',
            'actor_mismatch',
        );
    }
}
