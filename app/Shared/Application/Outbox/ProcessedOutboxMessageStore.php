<?php

declare(strict_types=1);

namespace App\Shared\Application\Outbox;

use App\Shared\Domain\Tenancy\PropertyId;

interface ProcessedOutboxMessageStore
{
    /**
     * Claims the consumer/message pair in the caller's transaction.
     * A false result means this consumer already committed the message.
     */
    public function claim(PropertyId $propertyId, string $eventId, string $consumer): bool;
}
