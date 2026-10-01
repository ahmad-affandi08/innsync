<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Application\Outbox\OutboxPayloadUnreadable;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Encryption\Encrypter;
use Throwable;

final readonly class EncryptedOutboxPayload
{
    public function __construct(private Encrypter $encrypter) {}

    public function encode(OutboxMessage $message): string
    {
        return $this->encrypter->encryptString(json_encode(
            $message->envelope(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    public function decode(string $payload): OutboxMessage
    {
        try {
            $decoded = json_decode(
                $this->encrypter->decryptString($payload),
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            if (! is_array($decoded)) {
                throw OutboxPayloadUnreadable::detected();
            }

            return $this->messageFromEnvelope($decoded);
        } catch (OutboxPayloadUnreadable $exception) {
            throw $exception;
        } catch (Throwable) {
            throw OutboxPayloadUnreadable::detected();
        }
    }

    /** @param array<array-key, mixed> $envelope */
    private function messageFromEnvelope(array $envelope): OutboxMessage
    {
        $requiredStrings = [
            'event_id',
            'event_type',
            'aggregate_id',
            'property_id',
            'occurred_at',
            'correlation_id',
        ];

        foreach ($requiredStrings as $key) {
            if (! isset($envelope[$key]) || ! is_string($envelope[$key])) {
                throw OutboxPayloadUnreadable::detected();
            }
        }

        if (! isset($envelope['payload_version']) || ! is_int($envelope['payload_version'])) {
            throw OutboxPayloadUnreadable::detected();
        }

        if (! isset($envelope['data']) || ! is_array($envelope['data'])) {
            throw OutboxPayloadUnreadable::detected();
        }

        $occurredAt = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i:s.u\Z',
            $envelope['occurred_at'],
            new DateTimeZone('UTC'),
        );

        if ($occurredAt === false) {
            throw OutboxPayloadUnreadable::detected();
        }

        return new OutboxMessage(
            $envelope['event_id'],
            new OutboxEvent(
                PropertyId::fromString($envelope['property_id']),
                $envelope['event_type'],
                $envelope['aggregate_id'],
                $envelope['payload_version'],
                $envelope['data'],
            ),
            $occurredAt,
            $envelope['correlation_id'],
        );
    }
}
