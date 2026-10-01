<?php

declare(strict_types=1);

namespace App\Shared\Application\Outbox;

use RuntimeException;

final class OutboxMessageUnhandled extends RuntimeException
{
    public static function forType(string $eventType): self
    {
        return new self(sprintf('No outbox consumer is registered for event type %s.', $eventType));
    }
}
