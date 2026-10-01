<?php

declare(strict_types=1);

namespace App\Shared\Application\Outbox;

use RuntimeException;

final class OutboxMessageNotFound extends RuntimeException
{
    public static function forId(string $eventId): self
    {
        return new self(sprintf('Outbox message %s was not found in the active property.', $eventId));
    }
}
