<?php

declare(strict_types=1);

namespace App\Shared\Application\Offline;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Server-side facts handed to a handler. None of it comes from the client. */
final readonly class OfflineContext
{
    public function __construct(
        public string $actorId,
        public PropertyId $propertyId,
        public string $correlationId,
        public DateTimeImmutable $receivedAt,
    ) {}
}
