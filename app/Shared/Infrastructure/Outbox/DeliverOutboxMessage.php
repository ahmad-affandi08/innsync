<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Application\Outbox\OutboxDeliveryFailed;
use App\Shared\Application\Outbox\ProcessOutboxMessage;
use App\Shared\Application\Outbox\RecordOutboxFailure;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class DeliverOutboxMessage implements ShouldBeEncrypted, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $propertyId,
        public readonly string $eventId,
        public readonly string $correlationId,
    ) {
        PropertyId::fromString($propertyId);

        foreach (['event' => $eventId, 'correlation' => $correlationId] as $label => $value) {
            if (preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/Di', $value) !== 1) {
                throw new InvalidArgumentException(sprintf('The outbox %s ID must be a ULID.', $label));
            }
        }
    }

    public function handle(
        ProcessOutboxMessage $processor,
        RecordOutboxFailure $failureRecorder,
        PropertyContext $propertyContext,
    ): void {
        Context::scope(function () use ($processor, $failureRecorder, $propertyContext): void {
            $propertyId = PropertyId::fromString($this->propertyId);

            $propertyContext->run($propertyId, function () use ($processor, $failureRecorder, $propertyId): void {
                try {
                    $processor->execute($propertyId, $this->eventId, $this->attempts());
                } catch (Throwable $failure) {
                    $delay = $this->delayForAttempt($this->attempts());
                    $safeFailure = OutboxDeliveryFailed::fromFailure($failure);

                    $failureRecorder->retrying(
                        $propertyId,
                        $this->eventId,
                        $this->attempts(),
                        CarbonImmutable::now('UTC')->addSeconds($delay),
                        $safeFailure,
                    );

                    Log::warning('outbox_delivery_retry_scheduled', [
                        'event_id' => $this->eventId,
                        'property_id' => $this->propertyId,
                        'attempt' => $this->attempts(),
                        'retry_in_seconds' => $delay,
                        'error_type' => $safeFailure->errorType,
                    ]);

                    throw $safeFailure;
                }
            });
        }, ['correlation_id' => $this->correlationId]);
    }

    public function failed(?Throwable $failure): void
    {
        Context::scope(function () use ($failure): void {
            $propertyId = PropertyId::fromString($this->propertyId);

            app(PropertyContext::class)->run($propertyId, function () use ($failure, $propertyId): void {
                app(RecordOutboxFailure::class)->deadLetter(
                    $propertyId,
                    $this->eventId,
                    $this->tries(),
                    $failure ?? new RuntimeException('The outbox job failed without an exception.'),
                );

                Log::error('outbox_delivery_dead_lettered', [
                    'event_id' => $this->eventId,
                    'property_id' => $this->propertyId,
                    'attempts' => $this->tries(),
                    'error_type' => $failure === null ? 'unknown' : get_debug_type($failure),
                ]);
            });
        }, ['correlation_id' => $this->correlationId]);
    }

    public function tries(): int
    {
        return max(1, (int) config('outbox.max_attempts'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        $delays = config('outbox.retry_delays');

        if (! is_array($delays) || $delays === []) {
            return [60];
        }

        return array_values(array_map(
            static fn (mixed $delay): int => max(1, (int) $delay),
            $delays,
        ));
    }

    public function timeout(): int
    {
        return max(1, (int) config('outbox.job_timeout_seconds'));
    }

    private function delayForAttempt(int $attempt): int
    {
        $delays = $this->backoff();

        return $delays[min(max(0, $attempt - 1), count($delays) - 1)];
    }
}
