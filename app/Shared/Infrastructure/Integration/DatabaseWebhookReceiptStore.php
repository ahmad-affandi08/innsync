<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Integration;

use App\Shared\Application\Integration\WebhookReceiptStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class DatabaseWebhookReceiptStore implements WebhookReceiptStore
{
    public function __construct(private Encrypter $encrypter) {}

    public function claim(PropertyId $property, string $receiptId, string $provider, string $eventId, string $body, DateTimeImmutable $at): bool
    {
        try {
            DB::table('webhook_receipts')->insert([
                'id' => $receiptId,
                'property_id' => $property->toString(),
                'provider' => $provider,
                'event_id' => $eventId,
                'payload' => $this->encrypter->encryptString($body),
                'received_at' => $at,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function body(PropertyId $property, string $receiptId): ?string
    {
        $payload = DB::table('webhook_receipts')->where('property_id', $property->toString())->where('id', $receiptId)->value('payload');

        if (! is_string($payload)) {
            return null;
        }

        try {
            return $this->encrypter->decryptString($payload);
        } catch (Throwable) {
            return null;
        }
    }
}
