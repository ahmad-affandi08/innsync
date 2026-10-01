<?php

declare(strict_types=1);

namespace App\Shared\Application\Outbox;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface OutboxMessageStore
{
    public function find(PropertyId $propertyId, string $eventId): ?OutboxMessage;

    /** Returns false when a duplicate queue delivery finds an already completed message. */
    public function markProcessing(PropertyId $propertyId, string $eventId, int $attempt): bool;

    public function markCompleted(PropertyId $propertyId, string $eventId): void;

    public function markRetrying(
        PropertyId $propertyId,
        string $eventId,
        int $attempt,
        DateTimeImmutable $nextAttemptAt,
        string $errorType,
        string $errorFingerprint,
    ): void;

    public function markDeadLetter(
        PropertyId $propertyId,
        string $eventId,
        int $attempt,
        string $errorType,
        string $errorFingerprint,
    ): void;

    public function requeueDeadLetter(PropertyId $propertyId, string $eventId): OutboxMessage;
}
