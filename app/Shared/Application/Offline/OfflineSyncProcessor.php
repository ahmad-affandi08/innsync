<?php

declare(strict_types=1);

namespace App\Shared\Application\Offline;

use App\Shared\Application\Concurrency\OptimisticLockConflict;
use App\Shared\Application\Idempotency\IdempotencyConflict;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Idempotency\IdempotencyOperationIncomplete;
use App\Shared\Application\Idempotency\IdempotencyRequest;
use App\Shared\Application\Idempotency\IdempotencyResultUnreadable;
use App\Shared\Application\Idempotency\IdempotentExecutor;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Security\SecurityEvent;
use App\Shared\Application\Security\SecurityEventOutcome;
use App\Shared\Application\Security\SecurityLog;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Time\Clock;
use Throwable;

/**
 * Applies a batch of offline items (NFR-04, NFR-18, NFR-19, BR-005, BR-010).
 *
 * Per item: property scope, handler and payload version, server-side
 * authorization, then the handler inside one idempotent transaction keyed by
 * the client's operation ID. A retry of an item that already ran returns the
 * same logical result and applies nothing twice. A conflict or rejection is
 * recorded durably for reconciliation and is never silently dropped.
 *
 * Items run in each device's own sequence order. After a transient failure the
 * rest of that device's batch is deferred, so a later item can never overtake
 * an earlier one that is still pending. Conflicts and rejections do not halt
 * the device: handlers decide whether a dependent item is still valid.
 */
final readonly class OfflineSyncProcessor
{
    public function __construct(
        private OfflineHandlerRegistry $handlers,
        private IdempotentExecutor $executor,
        private PermissionChecker $permissions,
        private SyncExceptionRepository $exceptions,
        private DeviceStatusRepository $devices,
        private PropertyContext $property,
        private SecurityLog $securityLog,
        private UnexpectedFailureReporter $failures,
        private Clock $clock,
        private int $maxPayloadBytes,
    ) {}

    /**
     * @param  list<array<array-key, mixed>>  $rawItems  every item must have a well-formed operation_id (the caller checks)
     * @return list<OfflineItemResult> in the order the items were received
     */
    public function process(
        string $actorId,
        string $correlationId,
        array $rawItems,
        ?string $deviceId = null,
        ?ClientStatus $clientStatus = null,
    ): array {
        $receivedAt = $this->clock->nowUtc();
        $context = new OfflineContext($actorId, $this->property->current(), $correlationId, $receivedAt);

        if ($deviceId !== null && $clientStatus !== null) {
            $this->devices->report($context->propertyId, strtolower($deviceId), $actorId, $clientStatus->pending, $clientStatus->oldestPendingSeconds, $receivedAt);
        }

        $results = [];
        $queue = [];

        foreach ($rawItems as $index => $raw) {
            $operationId = OfflineEnvelope::operationIdOf($raw)
                ?? throw new InvalidOfflineEnvelope('invalid_envelope', 'operation_id must be a ULID.');

            try {
                $queue[] = [$index, OfflineEnvelope::fromArray($raw, $this->maxPayloadBytes)];
            } catch (InvalidOfflineEnvelope $invalid) {
                $results[$index] = OfflineItemResult::rejected($operationId, $invalid->reasonCode);
            }
        }

        // Each device's own order; arrival order breaks ties.
        usort($queue, static fn (array $a, array $b): int => [$a[1]->deviceId, $a[1]->clientSequence, $a[0]] <=> [$b[1]->deviceId, $b[1]->clientSequence, $b[0]]);

        $halted = [];

        foreach ($queue as [$index, $envelope]) {
            if (isset($halted[$envelope->deviceId])) {
                $results[$index] = OfflineItemResult::deferred($envelope->operationId);

                continue;
            }

            $result = $this->processOne($envelope, $context);

            if ($result->status === OfflineItemResult::RETRY_LATER) {
                $halted[$envelope->deviceId] = true;
            }

            $results[$index] = $result;
        }

        ksort($results);

        return array_values($results);
    }

    private function processOne(OfflineEnvelope $envelope, OfflineContext $context): OfflineItemResult
    {
        if (! $envelope->propertyId->equals($context->propertyId)) {
            $this->deny($envelope, $context, 'offline.sync.property_mismatch');

            return $this->problem($envelope, $context, OfflineItemResult::rejected($envelope->operationId, 'property_mismatch'));
        }

        // A shared device may hold items recorded by someone else. They are applied only when their own
        // author is signed in, so attribution (audit, idempotency actor) is never silently changed.
        if ($envelope->actorId !== strtolower($context->actorId)) {
            $this->deny($envelope, $context, 'offline.sync.actor_mismatch');

            return $this->problem($envelope, $context, OfflineItemResult::rejected($envelope->operationId, 'actor_mismatch'));
        }

        $handler = $this->handlers->find($envelope->type);

        if ($handler === null) {
            return $this->problem($envelope, $context, OfflineItemResult::rejected($envelope->operationId, 'unknown_operation'));
        }

        if (! in_array($envelope->payloadVersion, $handler->payloadVersions(), true)) {
            return $this->problem($envelope, $context, OfflineItemResult::rejected($envelope->operationId, 'unsupported_payload_version'));
        }

        $permission = $handler->permission();

        if ($permission !== null && ! $this->permissions->allowsInProperty($context->actorId, $permission, $context->propertyId)) {
            $this->deny($envelope, $context, 'offline.sync.denied');

            return $this->problem($envelope, $context, OfflineItemResult::rejected($envelope->operationId, 'forbidden'));
        }

        try {
            $outcome = $this->executor->execute(
                new IdempotencyRequest(
                    $context->propertyId,
                    IdempotencyKey::fromString($envelope->operationId),
                    'offline.'.$envelope->type,
                    $envelope->fingerprint(),
                    $context->actorId,
                ),
                static fn (): array => $handler->handle($envelope, $context)->toArray(),
            );
        } catch (IdempotencyConflict) {
            // The same operation ID arrived with different content or from another actor.
            return $this->problem($envelope, $context, OfflineItemResult::rejected($envelope->operationId, 'idempotency_mismatch'));
        } catch (IdempotencyOperationIncomplete|IdempotencyResultUnreadable) {
            return OfflineItemResult::retryLater($envelope->operationId, 'operation_in_progress');
        } catch (OptimisticLockConflict) {
            return $this->problem($envelope, $context, OfflineItemResult::fromOutcome(
                $envelope->operationId,
                OfflineOutcome::conflict('optimistic_lock', ConflictAction::Refresh),
                false,
            ));
        } catch (Throwable $failure) {
            // The transaction rolled back, so nothing was applied; a retry runs again.
            $this->failures->report($failure);

            return OfflineItemResult::retryLater($envelope->operationId, 'server_error');
        }

        return $this->problem($envelope, $context, OfflineItemResult::fromOutcome(
            $envelope->operationId,
            OfflineOutcome::fromArray($outcome->payload),
            $outcome->replayed,
        ));
    }

    /** Conflicts and rejections are recorded for reconciliation, whether first seen or replayed. */
    private function problem(OfflineEnvelope $envelope, OfflineContext $context, OfflineItemResult $result): OfflineItemResult
    {
        if ($result->isFinalProblem()) {
            $this->exceptions->ensureOpen(
                $context->propertyId,
                $envelope,
                $result->status,
                $result->code ?? 'unspecified',
                $result->action,
                $result->serverVersion,
                $context->actorId,
                $context->correlationId,
                $context->receivedAt,
            );
        }

        return $result;
    }

    private function deny(OfflineEnvelope $envelope, OfflineContext $context, string $event): void
    {
        $this->securityLog->record(new SecurityEvent(
            $event,
            SecurityEventOutcome::Denied,
            $context->actorId,
            $context->propertyId->toString(),
            ['operation_type' => $envelope->type, 'device_id' => $envelope->deviceId],
        ));
    }
}
