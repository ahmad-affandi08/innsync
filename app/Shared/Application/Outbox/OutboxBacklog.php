<?php

declare(strict_types=1);

namespace App\Shared\Application\Outbox;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** How many events of a property have waited since before a moment without being delivered, so a screen can say the background processing has stopped. */
interface OutboxBacklog
{
    public function waitingSince(PropertyId $propertyId, DateTimeImmutable $before): int;
}
