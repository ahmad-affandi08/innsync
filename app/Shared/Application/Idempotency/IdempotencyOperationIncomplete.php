<?php

declare(strict_types=1);

namespace App\Shared\Application\Idempotency;

use RuntimeException;

final class IdempotencyOperationIncomplete extends RuntimeException
{
    public static function detected(): self
    {
        return new self('The idempotency operation exists without a completed result.');
    }
}
