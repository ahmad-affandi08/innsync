<?php

declare(strict_types=1);

namespace App\Shared\Application\Integration;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface WebhookReceiptStore
{
    /** Stores a verified delivery once per (provider, event id). @return bool false when it was already received */
    public function claim(PropertyId $property, string $receiptId, string $provider, string $eventId, string $body, DateTimeImmutable $at): bool;

    /** The stored raw body, for the module handler that processes the receipt. */
    public function body(PropertyId $property, string $receiptId): ?string;
}
