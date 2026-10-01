<?php

declare(strict_types=1);

namespace App\Shared\Application\Outbox;

use RuntimeException;

final class OutboxStateConflict extends RuntimeException
{
    public static function detected(string $eventId): self
    {
        return new self(sprintf('Outbox message %s is not in the required state.', $eventId));
    }
}
