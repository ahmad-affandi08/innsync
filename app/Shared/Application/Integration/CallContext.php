<?php

declare(strict_types=1);

namespace App\Shared\Application\Integration;

/** What an adapter needs for one call: bounded timeouts and the key the provider must treat as idempotent. */
final readonly class CallContext
{
    public function __construct(
        public string $provider,
        public string $operation,
        public string $idempotencyKey,
        public string $correlationId,
        public int $connectTimeoutSeconds,
        public int $readTimeoutSeconds,
    ) {}
}
