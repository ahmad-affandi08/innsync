<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Presentation\Http\Controllers;

use App\Modules\InventoryPurchasing\Application\InventoryCatalogService;
use App\Modules\InventoryPurchasing\Application\StockMovementService;
use App\Modules\InventoryPurchasing\Application\StockService;
use App\Modules\InventoryPurchasing\Application\StockTransferService;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Stock movements and transfers between locations. Every rule and permission lives in the application services. */
final readonly class StockMovementController
{
    public function __construct(private StockMovementService $movements, private StockService $stock, private StockTransferService $transfers, private InventoryCatalogService $catalog, private PropertyContext $property) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'string', 'max:14'], 'item_id' => ['required', 'string', 'size:26'], 'location_id' => ['required', 'string', 'size:26'], 'unit' => ['required', 'string', 'max:8'],
            'quantity' => ['required', 'string', 'max:14'], 'reason_code' => ['nullable', 'string', 'max:16'], 'reference' => ['nullable', 'string', 'max:40'], 'note' => ['nullable', 'string', 'max:200'],
            'negative_reason' => ['nullable', 'string', 'max:200'],
        ]);
        $property = $this->property->current();
        $actor = $this->actor($request);
        $reason = $data['reason_code'] ?? null;
        $reference = $data['reference'] ?? null;
        $note = $data['note'] ?? null;
        $negative = $data['negative_reason'] ?? null;
        $key = IdempotencyKey::fromString((string) $request->header('Idempotency-Key'));

        $movement = match ($data['kind']) {
            'opening' => $this->stock->postOpening($property, $actor, $data['item_id'], $data['location_id'], $data['unit'], $data['quantity'], $reference, $note),
            'receipt' => $this->movements->receive($property, $actor, $data['item_id'], $data['location_id'], $data['unit'], $data['quantity'], $reference, $note, null, null, $key),
            'issue' => $this->movements->issue($property, $actor, $data['item_id'], $data['location_id'], $data['unit'], $data['quantity'], (string) $reason, $reference, $note, $negative, null, null, $key),
            'adjustment_in', 'adjustment_out' => $this->movements->adjust($property, $actor, $data['kind'] === 'adjustment_in', $data['item_id'], $data['location_id'], $data['unit'], $data['quantity'], (string) $reason, $reference, $note, $negative, $key),
            'write_off' => $this->movements->writeOff($property, $actor, $data['item_id'], $data['location_id'], $data['unit'], $data['quantity'], (string) $reason, $reference, $note, $negative, $key),
            default => throw Refusal::invalid('Choose the kind of movement.', ['kind']),
        };

        return $this->json(['movement' => $movement], 201);
    }

    public function transfersPage(Request $request): Response
    {
        $data = $request->validate(['status' => ['nullable', 'string', 'max:10']]);

        return Inertia::render('inventory-purchasing/pages/transfers', [
            'overview' => $this->transfers->overview($this->property->current(), $this->actor($request), $data['status'] ?? null),
            'catalog' => $this->catalog->overview($this->property->current(), $this->actor($request)),
            'status' => $data['status'] ?? '',
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_location_id' => ['required', 'string', 'size:26'], 'to_location_id' => ['required', 'string', 'size:26'], 'note' => ['nullable', 'string', 'max:200'],
            'lines' => ['required', 'array', 'min:1', 'max:30'], 'lines.*.item_id' => ['required', 'string', 'size:26'], 'lines.*.unit' => ['required', 'string', 'max:8'], 'lines.*.quantity' => ['required', 'string', 'max:14'],
        ]);

        return $this->json(['transfer' => $this->transfers->send($this->property->current(), $this->actor($request), $data['from_location_id'], $data['to_location_id'], array_values($data['lines']), $data['note'] ?? null, IdempotencyKey::fromString((string) $request->header('Idempotency-Key')))], 201);
    }

    public function receive(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['transfer' => $this->transfers->receive($this->property->current(), $this->actor($request), $id, (int) $data['lock_version'])]);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:200'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['transfer' => $this->transfers->reject($this->property->current(), $this->actor($request), $id, $data['note'], (int) $data['lock_version'])]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['transfer' => $this->transfers->cancel($this->property->current(), $this->actor($request), $id, (int) $data['lock_version'])]);
    }

    /** @param array<string, mixed> $body */
    private function json(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status)->header('Cache-Control', 'no-store');
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
