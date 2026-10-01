<?php

declare(strict_types=1);

namespace App\Shared\Application\Idempotency;

use RuntimeException;

final class IdempotencyContext
{
    private ?IdempotencyKey $key = null;

    public function activate(IdempotencyKey $key): void
    {
        $this->key = $key;
    }

    public function clear(): void
    {
        $this->key = null;
    }

    public function current(): IdempotencyKey
    {
        return $this->key ?? throw new RuntimeException(
            'An idempotency key is required for this operation.',
        );
    }

    public function hasActiveKey(): bool
    {
        return $this->key !== null;
    }
}
