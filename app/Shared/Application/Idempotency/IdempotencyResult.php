<?php

declare(strict_types=1);

namespace App\Shared\Application\Idempotency;

final readonly class IdempotencyResult
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public array $payload,
        public bool $replayed,
    ) {}
}
