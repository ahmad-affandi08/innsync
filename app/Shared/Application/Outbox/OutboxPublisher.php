<?php

declare(strict_types=1);

namespace App\Shared\Application\Outbox;

interface OutboxPublisher
{
    /**
     * The caller must invoke this inside the same transaction as its source mutation.
     */
    public function publish(OutboxEvent $event): OutboxMessage;
}
