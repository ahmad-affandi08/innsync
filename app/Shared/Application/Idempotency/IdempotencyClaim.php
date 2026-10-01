<?php

declare(strict_types=1);

namespace App\Shared\Application\Idempotency;

final readonly class IdempotencyClaim
{
    /** @param array<string, mixed>|null $payload */
    private function __construct(
        public string $operationId,
        public bool $replayed,
        public ?array $payload,
    ) {}

    public static function acquired(string $operationId): self
    {
        return new self($operationId, false, null);
    }

    /** @param array<string, mixed> $payload */
    public static function replayed(string $operationId, array $payload): self
    {
        return new self($operationId, true, $payload);
    }
}
