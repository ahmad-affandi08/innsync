<?php

declare(strict_types=1);

namespace App\Shared\Application\Idempotency;

use RuntimeException;

final class IdempotencyResultUnreadable extends RuntimeException
{
    public static function detected(): self
    {
        return new self('The stored idempotency result cannot be read safely.');
    }
}
