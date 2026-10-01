<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Application\Observability\CorrelationId;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final readonly class DatabaseOutboxPublisher implements OutboxPublisher
{
    public function __construct(
        private EncryptedOutboxPayload $payload,
        private CorrelationId $correlationId,
        private PropertyContext $propertyContext,
    ) {}

    public function publish(OutboxEvent $event): OutboxMessage
    {
        $this->assertPropertyScope($event);

        if (DB::transactionLevel() < 1) {
            throw new LogicException('Outbox messages must be published inside the source transaction.');
        }

        $message = new OutboxMessage(
            strtolower((string) Str::ulid()),
            $event,
            CarbonImmutable::now('UTC'),
            $this->correlationId->current(),
        );

        DB::table('outbox_messages')->insert([
            'id' => $message->eventId,
            'property_id' => $event->propertyId->toString(),
            'event_type' => $event->eventType,
            'aggregate_id' => strtolower($event->aggregateId),
            'payload_version' => $event->payloadVersion,
            'encrypted_payload' => $this->payload->encode($message),
            'correlation_id' => $message->correlationId,
            'status' => 'pending',
            'attempt_count' => 0,
            'requeue_count' => 0,
            'available_at' => $message->occurredAt,
            'queued_at' => null,
            'last_attempt_at' => null,
            'completed_at' => null,
            'dead_lettered_at' => null,
            'last_error_type' => null,
            'last_error_fingerprint' => null,
            'occurred_at' => $message->occurredAt,
            'created_at' => $message->occurredAt,
            'updated_at' => $message->occurredAt,
        ]);

        return $message;
    }

    private function assertPropertyScope(OutboxEvent $event): void
    {
        $propertyId = $event->propertyId->toString();
        $activePropertyId = $this->propertyContext->current()->toString();

        if ($activePropertyId !== $propertyId) {
            throw PropertyScopeViolation::mismatched($activePropertyId, $propertyId);
        }
    }
}
