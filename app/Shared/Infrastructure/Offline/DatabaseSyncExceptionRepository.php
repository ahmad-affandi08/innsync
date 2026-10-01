<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Offline;

use App\Shared\Application\Offline\OfflineEnvelope;
use App\Shared\Application\Offline\SyncExceptionRepository;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\UtcTime;
use DateTimeImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DatabaseSyncExceptionRepository implements SyncExceptionRepository
{
    public function ensureOpen(
        PropertyId $property,
        OfflineEnvelope $envelope,
        string $kind,
        string $reasonCode,
        ?string $conflictAction,
        ?int $serverVersion,
        string $actorId,
        string $correlationId,
        DateTimeImmutable $receivedAt,
    ): void {
        $now = UtcTime::normalize($receivedAt)->format('Y-m-d H:i:s.u');

        // The unique (property, operation) key makes a replayed conflict a no-op.
        DB::table('offline_sync_exceptions')->insertOrIgnore([
            'id' => strtolower((string) Str::ulid()),
            'property_id' => $property->toString(),
            'operation_id' => $envelope->operationId,
            'operation_type' => $envelope->type,
            'device_id' => $envelope->deviceId,
            'client_sequence' => $envelope->clientSequence,
            'actor_id' => strtolower($actorId),
            'kind' => $kind,
            'reason_code' => $reasonCode,
            'conflict_action' => $conflictAction,
            'server_version' => $serverVersion,
            'payload_version' => $envelope->payloadVersion,
            'encrypted_payload' => Crypt::encryptString((string) json_encode($envelope->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'device_time' => UtcTime::normalize($envelope->deviceTime)->format('Y-m-d H:i:s.u'),
            'received_at' => $now,
            'correlation_id' => strtolower($correlationId),
            'status' => 'open',
            'lock_version' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
