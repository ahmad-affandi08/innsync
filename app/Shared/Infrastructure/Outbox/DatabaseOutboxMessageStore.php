<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Application\Outbox\OutboxMessageStore;
use App\Shared\Application\Outbox\OutboxPayloadUnreadable;
use App\Shared\Application\Outbox\OutboxStateConflict;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseOutboxMessageStore implements OutboxMessageStore
{
    public function __construct(
        private EncryptedOutboxPayload $payload,
        private PropertyContext $propertyContext,
    ) {}

    public function find(PropertyId $propertyId, string $eventId): ?OutboxMessage
    {
        $this->assertPropertyScope($propertyId);

        $record = DB::table('outbox_messages')
            ->where('property_id', $propertyId->toString())
            ->where('id', strtolower($eventId))
            ->first();

        if ($record === null) {
            return null;
        }

        $message = $this->payload->decode((string) $record->encrypted_payload);

        if ($message->eventId !== (string) $record->id
            || $message->event->propertyId->toString() !== (string) $record->property_id
            || $message->event->eventType !== (string) $record->event_type
            || strtolower($message->event->aggregateId) !== (string) $record->aggregate_id
            || $message->event->payloadVersion !== (int) $record->payload_version
            || $message->correlationId !== (string) $record->correlation_id) {
            throw OutboxPayloadUnreadable::detected();
        }

        return $message;
    }

    public function markProcessing(PropertyId $propertyId, string $eventId, int $attempt): bool
    {
        $this->assertPropertyScope($propertyId);

        $updated = DB::table('outbox_messages')
            ->where('property_id', $propertyId->toString())
            ->where('id', strtolower($eventId))
            ->whereIn('status', ['queued', 'processing', 'retrying'])
            ->update([
                'status' => 'processing',
                'attempt_count' => $attempt,
                'last_attempt_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);

        if ($updated === 1) {
            return true;
        }

        $status = DB::table('outbox_messages')
            ->where('property_id', $propertyId->toString())
            ->where('id', strtolower($eventId))
            ->value('status');

        if ($status === 'completed') {
            return false;
        }

        throw OutboxStateConflict::detected($eventId);
    }

    public function markCompleted(PropertyId $propertyId, string $eventId): void
    {
        $this->assertPropertyScope($propertyId);

        $updated = DB::table('outbox_messages')
            ->where('property_id', $propertyId->toString())
            ->where('id', strtolower($eventId))
            ->where('status', 'processing')
            ->update([
                'status' => 'completed',
                'available_at' => null,
                'completed_at' => now('UTC'),
                'last_error_type' => null,
                'last_error_fingerprint' => null,
                'updated_at' => now('UTC'),
            ]);

        if ($updated === 1) {
            return;
        }

        $status = DB::table('outbox_messages')
            ->where('property_id', $propertyId->toString())
            ->where('id', strtolower($eventId))
            ->value('status');

        if ($status !== 'completed') {
            throw OutboxStateConflict::detected($eventId);
        }
    }

    public function markRetrying(
        PropertyId $propertyId,
        string $eventId,
        int $attempt,
        DateTimeImmutable $nextAttemptAt,
        string $errorType,
        string $errorFingerprint,
    ): void {
        $this->markFailureState(
            $propertyId,
            $eventId,
            $attempt,
            'retrying',
            $nextAttemptAt,
            null,
            $errorType,
            $errorFingerprint,
        );
    }

    public function markDeadLetter(
        PropertyId $propertyId,
        string $eventId,
        int $attempt,
        string $errorType,
        string $errorFingerprint,
    ): void {
        $this->markFailureState(
            $propertyId,
            $eventId,
            $attempt,
            'dead_letter',
            null,
            now('UTC')->toDateTimeImmutable(),
            $errorType,
            $errorFingerprint,
        );
    }

    public function requeueDeadLetter(PropertyId $propertyId, string $eventId): OutboxMessage
    {
        $this->assertPropertyScope($propertyId);

        $message = $this->find($propertyId, $eventId)
            ?? throw OutboxStateConflict::detected($eventId);

        $updated = DB::table('outbox_messages')
            ->where('property_id', $propertyId->toString())
            ->where('id', strtolower($eventId))
            ->where('status', 'dead_letter')
            ->update([
                'status' => 'pending',
                'attempt_count' => 0,
                'requeue_count' => DB::raw('requeue_count + 1'),
                'available_at' => now('UTC'),
                'queued_at' => null,
                'last_attempt_at' => null,
                'completed_at' => null,
                'dead_lettered_at' => null,
                'last_error_type' => null,
                'last_error_fingerprint' => null,
                'updated_at' => now('UTC'),
            ]);

        if ($updated !== 1) {
            throw OutboxStateConflict::detected($eventId);
        }

        return $message;
    }

    private function markFailureState(
        PropertyId $propertyId,
        string $eventId,
        int $attempt,
        string $status,
        ?DateTimeImmutable $availableAt,
        ?DateTimeImmutable $deadLetteredAt,
        string $errorType,
        string $errorFingerprint,
    ): void {
        $this->assertPropertyScope($propertyId);

        $updated = DB::table('outbox_messages')
            ->where('property_id', $propertyId->toString())
            ->where('id', strtolower($eventId))
            ->whereIn('status', ['queued', 'processing', 'retrying'])
            ->update([
                'status' => $status,
                'attempt_count' => $attempt,
                'available_at' => $availableAt,
                'dead_lettered_at' => $deadLetteredAt,
                'last_error_type' => substr($errorType, 0, 255),
                'last_error_fingerprint' => $errorFingerprint,
                'updated_at' => now('UTC'),
            ]);

        if ($updated !== 1) {
            throw OutboxStateConflict::detected($eventId);
        }
    }

    private function assertPropertyScope(PropertyId $propertyId): void
    {
        $activePropertyId = $this->propertyContext->current()->toString();

        if ($activePropertyId !== $propertyId->toString()) {
            throw PropertyScopeViolation::mismatched($activePropertyId, $propertyId->toString());
        }
    }
}
