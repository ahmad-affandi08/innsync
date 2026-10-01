<?php

declare(strict_types=1);

namespace App\Shared\Application\Outbox;

use RuntimeException;

final class OutboxPayloadUnreadable extends RuntimeException
{
    public static function detected(): self
    {
        return new self('The encrypted outbox payload could not be verified.');
    }
}
