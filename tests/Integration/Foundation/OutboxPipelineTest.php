<?php

declare(strict_types=1);

namespace Tests\Integration\Foundation;

use App\Modules\Property\Infrastructure\Persistence\Eloquent\PropertyRecord;
use App\Shared\Application\Outbox\DrainOutbox;
use App\Shared\Application\Outbox\OutboxConsumer;
use App\Shared\Application\Outbox\OutboxConsumerRegistry;
use App\Shared\Application\Outbox\OutboxDeliveryFailed;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Outbox\ProcessOutboxMessage;
use App\Shared\Application\Outbox\RecordOutboxFailure;
use App\Shared\Application\Tenancy\MissingPropertyContext;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Infrastructure\Outbox\ConfiguredOutboxConsumerRegistry;
use App\Shared\Infrastructure\Outbox\DeliverOutboxMessage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\TestCase;

final class OutboxPipelineTest extends TestCase
{
    use RefreshDatabase;

    private const AGGREGATE_ID = '01arz3ndektsv4rrffq69g5fax';

    private const CORRELATION_ID = '01arz3ndektsv4rrffq69g5fat';

    private const PROPERTY_A = '01arz3ndektsv4rrffq69g5fav';

    private const PROPERTY_B = '01arz3ndektsv4rrffq69g5faw';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql'
            || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Integration tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createProperty(self::PROPERTY_A, 'Property A');
        $this->createProperty(self::PROPERTY_B, 'Property B');
        Context::add('correlation_id', self::CORRELATION_ID);
        app(PropertyContext::class)->activateFromString(self::PROPERTY_A);
        config([
            'outbox.queue_connection' => 'database',
            'outbox.queue_name' => 'outbox',
            'outbox.max_attempts' => 5,
            'outbox.retry_delays' => [60, 300, 900, 1800],
        ]);
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    public function test_publish_requires_matching_property_context(): void
    {
        $publisher = app(OutboxPublisher::class);

        app(PropertyContext::class)->clear();

        try {
            DB::transaction(fn () => $publisher->publish($this->event(self::PROPERTY_A)));
            self::fail('An outbox message was published without property context.');
        } catch (MissingPropertyContext) {
            self::assertSame(0, DB::table('outbox_messages')->count());
        }

        app(PropertyContext::class)->activateFromString(self::PROPERTY_B);

        $this->expectException(PropertyScopeViolation::class);
        DB::transaction(fn () => $publisher->publish($this->event(self::PROPERTY_A)));
    }

    public function test_publish_requires_the_source_transaction(): void
    {
        config([
            'database.connections.mysql_untransacted' => config('database.connections.mysql'),
        ]);
        DB::setDefaultConnection('mysql_untransacted');

        try {
            app(OutboxPublisher::class)->publish($this->event(self::PROPERTY_A));
            self::fail('An outbox message was published outside its source transaction.');
        } catch (LogicException) {
            self::assertTrue(true);
        } finally {
            DB::disconnect('mysql_untransacted');
            DB::setDefaultConnection('mysql');
        }
    }

    public function test_source_mutation_and_encrypted_outbox_message_commit_or_rollback_together(): void
    {
        $publisher = app(OutboxPublisher::class);

        try {
            DB::transaction(function () use ($publisher): void {
                DB::table('cache')->insert([
                    'key' => 'rolled-back-source',
                    'value' => 'must-not-commit',
                    'expiration' => time() + 3600,
                ]);
                $publisher->publish($this->event(self::PROPERTY_A));

                throw new RuntimeException('Force atomic rollback.');
            });
        } catch (RuntimeException) {
            // Expected.
        }

        self::assertSame(0, DB::table('cache')->where('key', 'rolled-back-source')->count());
        self::assertSame(0, DB::table('outbox_messages')->count());

        $message = DB::transaction(function () use ($publisher): OutboxMessage {
            DB::table('cache')->insert([
                'key' => 'committed-source',
                'value' => 'committed',
                'expiration' => time() + 3600,
            ]);

            return $publisher->publish($this->event(self::PROPERTY_A));
        });

        $record = DB::table('outbox_messages')->where('id', $message->eventId)->firstOrFail();
        self::assertSame('pending', $record->status);
        self::assertSame(self::CORRELATION_ID, $record->correlation_id);
        self::assertStringNotContainsString('room-101', $record->encrypted_payload);
        self::assertStringNotContainsString('checked-in', $record->encrypted_payload);
        self::assertSame(1, DB::table('cache')->where('key', 'committed-source')->count());
    }

    public function test_drain_atomically_enqueues_encrypted_job_and_idempotent_consumer_runs_once(): void
    {
        $this->useConsumers([new RecordingOutboxConsumer]);
        $message = $this->publish();

        self::assertSame(1, app(DrainOutbox::class)->execute(50));
        self::assertSame(0, app(DrainOutbox::class)->execute(50));

        $queuedMessage = DB::table('outbox_messages')->where('id', $message->eventId)->firstOrFail();
        $jobRecord = DB::table('jobs')->where('queue', 'outbox')->firstOrFail();
        self::assertSame('queued', $queuedMessage->status);
        self::assertStringNotContainsString('room-101', $jobRecord->payload);
        self::assertStringNotContainsString($message->eventId, $jobRecord->payload);

        $this->artisan('queue:work', [
            'connection' => 'database',
            '--queue' => 'outbox',
            '--once' => true,
            '--tries' => 5,
            '--timeout' => 45,
        ])->assertSuccessful();

        $this->assertDatabaseHas('outbox_messages', [
            'id' => $message->eventId,
            'property_id' => self::PROPERTY_A,
            'status' => 'completed',
            'attempt_count' => 1,
        ]);
        $this->assertDatabaseHas('processed_outbox_messages', [
            'event_id' => $message->eventId,
            'consumer' => 'tests.recording-consumer',
        ]);
        self::assertSame(1, DB::table('cache')->where('key', 'consumed-'.$message->eventId)->count());

        DB::table('outbox_messages')->where('id', $message->eventId)->update([
            'status' => 'queued',
            'available_at' => now('UTC'),
            'completed_at' => null,
        ]);

        $duplicate = $this->job($message);
        $duplicate->withFakeQueueInteractions();
        $duplicate->handle(
            app(ProcessOutboxMessage::class),
            app(RecordOutboxFailure::class),
            app(PropertyContext::class),
        );

        self::assertSame(1, DB::table('cache')->where('key', 'consumed-'.$message->eventId)->count());
        self::assertSame(1, DB::table('processed_outbox_messages')->where('event_id', $message->eventId)->count());
    }

    public function test_committed_consumers_are_not_repeated_when_a_later_consumer_retries(): void
    {
        $retryingConsumer = new RetryOnceOutboxConsumer;
        $this->useConsumers([new RecordingOutboxConsumer, $retryingConsumer]);
        $message = $this->publish();
        app(DrainOutbox::class)->execute(50);
        $job = $this->job($message);
        $job->withFakeQueueInteractions();

        try {
            $job->handle(
                app(ProcessOutboxMessage::class),
                app(RecordOutboxFailure::class),
                app(PropertyContext::class),
            );
            self::fail('A later consumer failure was treated as successful.');
        } catch (OutboxDeliveryFailed) {
            self::assertSame(1, $retryingConsumer->calls);
        }

        self::assertSame(1, DB::table('cache')->where('key', 'consumed-'.$message->eventId)->count());
        $this->assertDatabaseHas('processed_outbox_messages', [
            'event_id' => $message->eventId,
            'consumer' => 'tests.recording-consumer',
        ]);
        $this->assertDatabaseMissing('processed_outbox_messages', [
            'event_id' => $message->eventId,
            'consumer' => 'tests.retry-once-consumer',
        ]);

        app(ProcessOutboxMessage::class)->execute(
            PropertyId::fromString(self::PROPERTY_A),
            $message->eventId,
            2,
        );

        self::assertSame(1, DB::table('cache')->where('key', 'consumed-'.$message->eventId)->count());
        self::assertSame(2, $retryingConsumer->calls);
        $this->assertDatabaseHas('processed_outbox_messages', [
            'event_id' => $message->eventId,
            'consumer' => 'tests.retry-once-consumer',
        ]);
        $this->assertDatabaseHas('outbox_messages', [
            'id' => $message->eventId,
            'status' => 'completed',
            'attempt_count' => 2,
        ]);
    }

    public function test_drain_rejects_a_non_database_queue_without_changing_message_state(): void
    {
        $message = $this->publish();
        config(['outbox.queue_connection' => 'sync']);

        try {
            app(DrainOutbox::class)->execute(50);
            self::fail('A non-database outbox queue was accepted.');
        } catch (LogicException) {
            $this->assertDatabaseHas('outbox_messages', [
                'id' => $message->eventId,
                'status' => 'pending',
            ]);
            self::assertSame(0, DB::table('jobs')->count());
        }
    }

    public function test_failure_is_sanitized_retried_with_backoff_dead_lettered_and_manually_requeued(): void
    {
        $this->useConsumers([new FailingOutboxConsumer]);
        $message = $this->publish();
        app(DrainOutbox::class)->execute(50);
        $job = $this->job($message);
        $job->withFakeQueueInteractions();
        self::assertSame(5, $job->tries());
        self::assertSame([60, 300, 900, 1800], $job->backoff());
        self::assertSame(45, $job->timeout());

        try {
            $job->handle(
                app(ProcessOutboxMessage::class),
                app(RecordOutboxFailure::class),
                app(PropertyContext::class),
            );
            self::fail('A failed consumer was treated as successful.');
        } catch (OutboxDeliveryFailed $failure) {
            self::assertSame('Outbox delivery failed.', $failure->getMessage());
            self::assertSame(RuntimeException::class, $failure->errorType);
            self::assertSame(64, strlen($failure->errorFingerprint));

            $record = DB::table('outbox_messages')->where('id', $message->eventId)->firstOrFail();
            self::assertSame('retrying', $record->status);
            self::assertSame(1, $record->attempt_count);
            self::assertSame(RuntimeException::class, $record->last_error_type);
            self::assertStringNotContainsString('credential-secret', (string) $record->last_error_fingerprint);

            $job->failed($failure);
        }

        $this->assertDatabaseHas('outbox_messages', [
            'id' => $message->eventId,
            'status' => 'dead_letter',
            'attempt_count' => 5,
        ]);
        $this->assertDatabaseHas('security_events', [
            'property_id' => self::PROPERTY_A,
            'event_type' => 'outbox.message.dead-lettered',
            'outcome' => 'failure',
        ]);

        $this->artisan('outbox:retry', [
            'property_id' => self::PROPERTY_A,
            'event_id' => $message->eventId,
        ])->assertSuccessful();

        $this->assertDatabaseHas('outbox_messages', [
            'id' => $message->eventId,
            'status' => 'pending',
            'attempt_count' => 0,
            'requeue_count' => 1,
        ]);
        $this->assertDatabaseHas('security_events', [
            'property_id' => self::PROPERTY_A,
            'event_type' => 'outbox.dead-letter.requeued',
            'outcome' => 'success',
        ]);
    }

    public function test_tampered_encrypted_envelope_fails_closed_without_running_consumer(): void
    {
        $this->useConsumers([new RecordingOutboxConsumer]);
        $message = $this->publish();
        app(DrainOutbox::class)->execute(50);
        DB::table('outbox_messages')
            ->where('id', $message->eventId)
            ->update(['encrypted_payload' => 'tampered']);
        $job = $this->job($message);
        $job->withFakeQueueInteractions();

        try {
            $job->handle(
                app(ProcessOutboxMessage::class),
                app(RecordOutboxFailure::class),
                app(PropertyContext::class),
            );
            self::fail('A tampered outbox envelope was delivered.');
        } catch (OutboxDeliveryFailed $failure) {
            self::assertSame(
                'App\\Shared\\Application\\Outbox\\OutboxPayloadUnreadable',
                $failure->errorType,
            );
        }

        self::assertSame(0, DB::table('processed_outbox_messages')->count());
        self::assertSame(0, DB::table('cache')->where('key', 'consumed-'.$message->eventId)->count());
    }

    public function test_database_prevents_cross_property_consumer_receipt(): void
    {
        $message = $this->publish();

        $this->expectException(QueryException::class);
        DB::table('processed_outbox_messages')->insert([
            'id' => '01arz3ndektsv4rrffq69g5faz',
            'property_id' => self::PROPERTY_B,
            'event_id' => $message->eventId,
            'consumer' => 'tests.invalid-cross-property',
            'processed_at' => now('UTC'),
        ]);
    }

    private function publish(): OutboxMessage
    {
        return DB::transaction(
            fn (): OutboxMessage => app(OutboxPublisher::class)->publish($this->event(self::PROPERTY_A)),
        );
    }

    private function event(string $propertyId): OutboxEvent
    {
        return new OutboxEvent(
            PropertyId::fromString($propertyId),
            'front-office.stay.checked-in',
            self::AGGREGATE_ID,
            1,
            ['room_id' => 'room-101', 'nights' => 2],
        );
    }

    /** @param list<OutboxConsumer> $consumers */
    private function useConsumers(array $consumers): void
    {
        $this->app->instance(
            OutboxConsumerRegistry::class,
            new ConfiguredOutboxConsumerRegistry($consumers),
        );
    }

    private function job(OutboxMessage $message): DeliverOutboxMessage
    {
        return new DeliverOutboxMessage(
            $message->event->propertyId->toString(),
            $message->eventId,
            $message->correlationId,
        );
    }

    private function createProperty(string $id, string $name): void
    {
        $property = new PropertyRecord([
            'name' => $name,
            'timezone' => 'Asia/Jakarta',
            'currency_code' => 'IDR',
        ]);
        $property->id = $id;
        $property->save();
    }
}

final class RecordingOutboxConsumer implements OutboxConsumer
{
    public function name(): string
    {
        return 'tests.recording-consumer';
    }

    public function supports(string $eventType): bool
    {
        return $eventType === 'front-office.stay.checked-in';
    }

    public function consume(OutboxMessage $message): void
    {
        DB::table('cache')->insert([
            'key' => 'consumed-'.$message->eventId,
            'value' => 'consumed-once',
            'expiration' => time() + 3600,
        ]);
    }
}

final class FailingOutboxConsumer implements OutboxConsumer
{
    public function name(): string
    {
        return 'tests.failing-consumer';
    }

    public function supports(string $eventType): bool
    {
        return true;
    }

    public function consume(OutboxMessage $message): void
    {
        throw new RuntimeException('Provider rejected credential-secret.');
    }
}

final class RetryOnceOutboxConsumer implements OutboxConsumer
{
    public int $calls = 0;

    public function name(): string
    {
        return 'tests.retry-once-consumer';
    }

    public function supports(string $eventType): bool
    {
        return true;
    }

    public function consume(OutboxMessage $message): void
    {
        $this->calls++;

        if ($this->calls === 1) {
            throw new RuntimeException('Transient consumer failure.');
        }
    }
}
