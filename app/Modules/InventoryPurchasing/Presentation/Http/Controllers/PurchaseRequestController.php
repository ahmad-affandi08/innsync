<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Presentation\Http\Controllers;

use App\Modules\InventoryPurchasing\Application\PurchaseRequestService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Purchase requests. Every rule and permission lives in the application service. */
final readonly class PurchaseRequestController
{
    public function __construct(private PurchaseRequestService $requests, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['status' => ['nullable', 'string', 'max:16'], 'department' => ['nullable', 'string', 'max:16']]);

        return Inertia::render('inventory-purchasing/pages/requests', ['overview' => $this->requests->overview($this->property->current(), $this->actor($request), $data['status'] ?? null, $data['department'] ?? null), 'status' => $data['status'] ?? '', 'department' => $data['department'] ?? '']);
    }

    public function show(Request $request, string $id): Response
    {
        return Inertia::render('inventory-purchasing/pages/purchase-request', ['purchaseRequest' => $this->requests->show($this->property->current(), $this->actor($request), $id)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'department' => ['required', 'string', 'max:16'], 'urgency' => ['required', 'string', 'max:8'], 'reason' => ['required', 'string', 'max:200'], 'needed_by' => ['required', 'date_format:Y-m-d'],
            'lines' => ['required', 'array', 'min:1', 'max:'.PurchaseRequestService::MAX_LINES], 'lines.*.item_id' => ['required', 'string', 'size:26'], 'lines.*.unit' => ['required', 'string', 'max:8'], 'lines.*.quantity' => ['required', 'string', 'max:14'],
            'lines.*.est_cost_minor' => ['nullable', 'integer', 'min:0', 'max:10000000000'], 'lines.*.note' => ['nullable', 'string', 'max:200'],
        ]);

        return $this->json(['request' => $this->requests->create($this->property->current(), $this->actor($request), $data['department'], $data['urgency'], $data['reason'], $data['needed_by'], array_values($data['lines']))], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'department' => ['required', 'string', 'max:16'], 'urgency' => ['required', 'string', 'max:8'], 'reason' => ['required', 'string', 'max:200'], 'needed_by' => ['required', 'date_format:Y-m-d'], 'lock_version' => ['required', 'integer', 'min:0'],
            'lines' => ['required', 'array', 'min:1', 'max:'.PurchaseRequestService::MAX_LINES], 'lines.*.item_id' => ['required', 'string', 'size:26'], 'lines.*.unit' => ['required', 'string', 'max:8'], 'lines.*.quantity' => ['required', 'string', 'max:14'],
            'lines.*.est_cost_minor' => ['nullable', 'integer', 'min:0', 'max:10000000000'], 'lines.*.note' => ['nullable', 'string', 'max:200'],
        ]);

        return $this->json(['request' => $this->requests->update($this->property->current(), $this->actor($request), $id, $data['department'], $data['urgency'], $data['reason'], $data['needed_by'], array_values($data['lines']), (int) $data['lock_version'])]);
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['request' => $this->requests->submit($this->property->current(), $this->actor($request), $id, (int) $data['lock_version'])]);
    }

    public function release(Request $request, string $id): JsonResponse
    {
        return $this->json(['request' => $this->requests->release($this->property->current(), $this->actor($request), $id)]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['request' => $this->requests->cancel($this->property->current(), $this->actor($request), $id, $data['reason'], (int) $data['lock_version'])]);
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
