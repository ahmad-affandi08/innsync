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
    private const COLUMNS = ['id', 'operation_type', 'device_id', 'actor_id', 'kind', 'reason_code', 'conflict_action', 'received_at', 'status', 'resolved_at', 'resolved_by', 'resolution_note', 'lock_version'];

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

    public function forReview(PropertyId $property, ?string $status, int $limit): array
    {
        $query = DB::table('offline_sync_exceptions')->where('property_id', $property->toString())->select(self::COLUMNS);

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->orderByDesc('received_at')->orderByDesc('id')->limit($limit)->get()->map(static fn (object $r): array => (array) $r)->all();
    }

    public function openCount(PropertyId $property): int
    {
        return DB::table('offline_sync_exceptions')->where('property_id', $property->toString())->where('status', 'open')->count();
    }

    public function find(PropertyId $property, string $id): ?array
    {
        $row = DB::table('offline_sync_exceptions')->where('property_id', $property->toString())->where('id', $id)->select(self::COLUMNS)->first();

        return $row === null ? null : (array) $row;
    }

    public function resolve(PropertyId $property, string $id, int $lock, string $by, string $note, DateTimeImmutable $at): bool
    {
        $when = UtcTime::normalize($at)->format('Y-m-d H:i:s.u');

        return DB::table('offline_sync_exceptions')->where('property_id', $property->toString())->where('id', $id)->where('status', 'open')->where('lock_version', $lock)
            ->update(['status' => 'resolved', 'resolved_at' => $when, 'resolved_by' => $by, 'resolution_note' => $note, 'lock_version' => $lock + 1, 'updated_at' => $when]) === 1;
    }
}
