<?php

declare(strict_types=1);

namespace App\Shared\Application\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class OutboxMessage
{
    public function __construct(
        public string $eventId,
        public OutboxEvent $event,
        public DateTimeImmutable $occurredAt,
        public string $correlationId,
    ) {
        self::assertUlid($eventId, 'event');
        self::assertUlid($correlationId, 'correlation');

        if ($occurredAt->getOffset() !== 0) {
            throw new InvalidArgumentException('The outbox occurrence timestamp must use UTC.');
        }
    }

    /** @return array<string, mixed> */
    public function envelope(): array
    {
        return [
            'event_id' => strtolower($this->eventId),
            'event_type' => $this->event->eventType,
            'aggregate_id' => strtolower($this->event->aggregateId),
            'property_id' => $this->event->propertyId->toString(),
            'occurred_at' => $this->occurredAt
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.u\Z'),
            'correlation_id' => strtolower($this->correlationId),
            'payload_version' => $this->event->payloadVersion,
            'data' => $this->event->data,
        ];
    }

    private static function assertUlid(string $value, string $label): void
    {
        if (preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/Di', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('The outbox %s ID must be a ULID.', $label));
        }
    }
}
