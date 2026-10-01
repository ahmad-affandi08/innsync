<?php

declare(strict_types=1);

namespace App\Shared\Application\Outbox;

interface OutboxConsumerRegistry
{
    /** @return list<OutboxConsumer> */
    public function forEvent(string $eventType): array;
}
