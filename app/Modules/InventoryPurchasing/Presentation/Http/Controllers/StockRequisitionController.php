<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Presentation\Http\Controllers;

use App\Modules\InventoryPurchasing\Application\StockRequisitionService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** What a department asks of the main store. Every rule and permission lives in `StockRequisitionService`. */
final readonly class StockRequisitionController
{
    public function __construct(private StockRequisitionService $requisitions, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['status' => ['nullable', 'string', 'max:10']]);

        return Inertia::render('inventory-purchasing/pages/requisitions', ['overview' => $this->requisitions->overview($this->property->current(), $this->actor($request), $data['status'] ?? null), 'status' => $data['status'] ?? '']);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'requesting_location_id' => ['required', 'string', 'size:26'], 'supplying_location_id' => ['required', 'string', 'size:26'], 'note' => ['nullable', 'string', 'max:200'],
            'lines' => ['required', 'array', 'min:1', 'max:'.StockRequisitionService::MAX_LINES], 'lines.*.item_id' => ['required', 'string', 'size:26'], 'lines.*.unit' => ['required', 'string', 'max:8'], 'lines.*.quantity' => ['required', 'string', 'max:14'],
        ]);

        return $this->json(['requisition' => $this->requisitions->request($this->property->current(), $this->actor($request), $data['requesting_location_id'], $data['supplying_location_id'], array_values($data['lines']), $data['note'] ?? null)], 201);
    }

    public function fulfil(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0'], 'quantities' => ['nullable', 'array'], 'quantities.*' => ['nullable', 'string', 'max:14']]);

        return $this->json(['requisition' => $this->requisitions->fulfil($this->property->current(), $this->actor($request), $id, (int) $data['lock_version'], array_filter($data['quantities'] ?? [], static fn ($v): bool => $v !== null && $v !== ''))]);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:200'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['requisition' => $this->requisitions->reject($this->property->current(), $this->actor($request), $id, $data['note'], (int) $data['lock_version'])]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['requisition' => $this->requisitions->cancel($this->property->current(), $this->actor($request), $id, (int) $data['lock_version'])]);
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
