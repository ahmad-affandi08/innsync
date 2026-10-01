<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Idempotency;

use App\Shared\Application\Idempotency\IdempotencyClaim;
use App\Shared\Application\Idempotency\IdempotencyConflict;
use App\Shared\Application\Idempotency\IdempotencyOperationIncomplete;
use App\Shared\Application\Idempotency\IdempotencyRequest;
use App\Shared\Application\Idempotency\IdempotencyResultUnreadable;
use App\Shared\Application\Idempotency\IdempotencyStore;
use App\Shared\Application\Observability\CorrelationId;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class DatabaseIdempotencyStore implements IdempotencyStore
{
    private const UNIQUE_INDEX = 'idem_property_operation_key_unique';

    public function __construct(
        private IdempotencyPayloadCodec $codec,
        private CorrelationId $correlationId,
        private PropertyContext $propertyContext,
    ) {}

    public function acquire(IdempotencyRequest $request): IdempotencyClaim
    {
        $this->assertPropertyScope($request);

        $operationId = strtolower((string) Str::ulid());
        $propertyId = $request->propertyId->toString();
        $keyHash = $this->codec->keyHash($request->key->toString());
        $requestHash = $this->codec->requestHash($request->payload);

        try {
            DB::table('idempotency_operations')->insert([
                'id' => $operationId,
                'property_id' => $propertyId,
                'actor_id' => $request->actorId,
                'operation' => $request->operation,
                'key_hash' => $keyHash,
                'request_hash' => $requestHash,
                'result_payload' => null,
                'result_version' => 1,
                'correlation_id' => $this->correlationId->current(),
                'last_replay_correlation_id' => null,
                'replay_count' => 0,
                'first_requested_at' => now('UTC'),
                'completed_at' => null,
                'last_replayed_at' => null,
            ]);

            return IdempotencyClaim::acquired($operationId);
        } catch (QueryException $exception) {
            if (! $this->isOperationKeyCollision($exception)) {
                throw $exception;
            }
        }

        $record = DB::table('idempotency_operations')
            ->where('property_id', $propertyId)
            ->where('operation', $request->operation)
            ->where('key_hash', $keyHash)
            ->lockForUpdate()
            ->first();

        if ($record === null) {
            throw new RuntimeException('The colliding idempotency operation could not be loaded.');
        }

        if (! hash_equals((string) $record->request_hash, $requestHash)) {
            throw IdempotencyConflict::requestMismatch();
        }

        $recordActorId = is_string($record->actor_id) ? $record->actor_id : null;
        if ($recordActorId !== $request->actorId) {
            throw IdempotencyConflict::actorMismatch();
        }

        if (! is_string($record->result_payload) || $record->completed_at === null) {
            throw IdempotencyOperationIncomplete::detected();
        }

        if ((int) $record->result_version !== 1) {
            throw IdempotencyResultUnreadable::detected();
        }

        DB::table('idempotency_operations')
            ->where('id', $record->id)
            ->where('property_id', $propertyId)
            ->update([
                'replay_count' => DB::raw('replay_count + 1'),
                'last_replay_correlation_id' => $this->correlationId->current(),
                'last_replayed_at' => now('UTC'),
            ]);

        return IdempotencyClaim::replayed(
            (string) $record->id,
            $this->codec->decryptResult($record->result_payload),
        );
    }

    public function complete(
        IdempotencyRequest $request,
        string $operationId,
        array $payload,
    ): void {
        $this->assertPropertyScope($request);

        $updated = DB::table('idempotency_operations')
            ->where('id', $operationId)
            ->where('property_id', $request->propertyId->toString())
            ->whereNull('result_payload')
            ->whereNull('completed_at')
            ->update([
                'result_payload' => $this->codec->encryptResult($payload),
                'completed_at' => now('UTC'),
            ]);

        if ($updated !== 1) {
            throw IdempotencyOperationIncomplete::detected();
        }
    }

    private function assertPropertyScope(IdempotencyRequest $request): void
    {
        $propertyId = $request->propertyId->toString();
        $activePropertyId = $this->propertyContext->current()->toString();

        if ($activePropertyId !== $propertyId) {
            throw PropertyScopeViolation::mismatched($activePropertyId, $propertyId);
        }
    }

    private function isOperationKeyCollision(QueryException $exception): bool
    {
        return ($exception->errorInfo[1] ?? null) === 1062
            && str_contains($exception->getMessage(), self::UNIQUE_INDEX);
    }
}
