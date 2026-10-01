<?php

declare(strict_types=1);

namespace App\Shared\Application\Idempotency;

interface IdempotencyStore
{
    public function acquire(IdempotencyRequest $request): IdempotencyClaim;

    /** @param array<string, mixed> $payload */
    public function complete(
        IdempotencyRequest $request,
        string $operationId,
        array $payload,
    ): void;
}
