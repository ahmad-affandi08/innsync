<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Offline;

use App\Shared\Application\Observability\CorrelationId;
use App\Shared\Application\Offline\ClientStatus;
use App\Shared\Application\Offline\OfflineEnvelope;
use App\Shared\Application\Offline\OfflineItemResult;
use App\Shared\Application\Offline\OfflineSyncProcessor;
use App\Shared\Application\Time\Clock;
use App\Shared\Domain\Time\UtcTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * POST /sync/batch: receives queued offline items. The response is 200 with a
 * result per item, even when every item is a conflict or rejection; only a
 * malformed batch, a missing session or a missing property fails as a whole.
 * An empty batch is a valid heartbeat that reports the device's queue status.
 */
final class SyncController
{
    public function __invoke(
        Request $request,
        OfflineSyncProcessor $processor,
        CorrelationId $correlation,
        Clock $clock,
    ): JsonResponse {
        abort_if(strlen($request->getContent()) > (int) config('offline.max_request_bytes'), 413);

        $data = $request->validate([
            'device_id' => ['nullable', 'string', 'regex:'.OfflineEnvelope::ULID_PATTERN],
            'items' => ['present', 'array', 'max:'.(int) config('offline.max_batch_items')],
            'client_status' => ['nullable', 'array'],
            'client_status.pending' => ['required_with:client_status', 'integer', 'min:0', 'max:1000000'],
            'client_status.oldest_pending_seconds' => ['required_with:client_status', 'integer', 'min:0', 'max:31536000'],
        ]);

        $seen = [];

        foreach ($data['items'] as $position => $item) {
            $operationId = OfflineEnvelope::operationIdOf($item);

            if ($operationId === null || isset($seen[$operationId])) {
                throw ValidationException::withMessages([
                    "items.{$position}.operation_id" => [__('validation.ulid', ['attribute' => 'operation_id'])],
                ]);
            }

            $seen[$operationId] = true;
        }

        $status = isset($data['client_status'])
            ? new ClientStatus((int) $data['client_status']['pending'], (int) $data['client_status']['oldest_pending_seconds'])
            : null;

        $results = $processor->process(
            (string) $request->user()->getAuthIdentifier(),
            $correlation->current(),
            array_values($data['items']),
            $data['device_id'] ?? null,
            $status,
        );

        return response()->json([
            'server_time' => UtcTime::format($clock->nowUtc()),
            'results' => array_map(static fn (OfflineItemResult $result): array => $result->toArray(), $results),
        ])->header('Cache-Control', 'no-store');
    }
}
