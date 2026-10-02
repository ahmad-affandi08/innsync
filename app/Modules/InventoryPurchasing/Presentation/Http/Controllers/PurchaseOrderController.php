<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Presentation\Http\Controllers;

use App\Modules\InventoryPurchasing\Application\PurchaseOrderService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Purchase orders. Every rule and permission lives in the application service. */
final readonly class PurchaseOrderController
{
    public function __construct(private PurchaseOrderService $orders, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['status' => ['nullable', 'string', 'max:18'], 'supplier' => ['nullable', 'string', 'size:26']]);

        return Inertia::render('inventory-purchasing/pages/orders', ['overview' => $this->orders->overview($this->property->current(), $this->actor($request), $data['status'] ?? null, $data['supplier'] ?? null), 'status' => $data['status'] ?? '']);
    }

    public function show(Request $request, string $id): Response
    {
        return Inertia::render('inventory-purchasing/pages/order', ['order' => $this->orders->show($this->property->current(), $this->actor($request), $id)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'string', 'size:26'], 'location_id' => ['required', 'string', 'size:26'], 'expected_date' => ['nullable', 'date_format:Y-m-d'], 'tax_bp' => ['nullable', 'integer', 'min:0', 'max:5000'], 'note' => ['nullable', 'string', 'max:200'],
            'lines' => ['required', 'array', 'min:1', 'max:'.PurchaseOrderService::MAX_LINES], 'lines.*.request_line_id' => ['nullable', 'string', 'size:26'], 'lines.*.item_id' => ['nullable', 'string', 'size:26'], 'lines.*.unit' => ['nullable', 'string', 'max:8'],
            'lines.*.quantity' => ['nullable', 'string', 'max:14'], 'lines.*.unit_price_minor' => ['nullable', 'integer', 'min:0', 'max:10000000000'], 'lines.*.department' => ['nullable', 'string', 'max:16'],
        ]);

        return $this->json(['order' => $this->orders->create($this->property->current(), $this->actor($request), $data['supplier_id'], $data['location_id'], $data['expected_date'] ?? null, isset($data['tax_bp']) ? (int) $data['tax_bp'] : null, $data['note'] ?? null, array_values($data['lines']))], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'string', 'size:26'], 'location_id' => ['required', 'string', 'size:26'], 'expected_date' => ['nullable', 'date_format:Y-m-d'], 'tax_bp' => ['nullable', 'integer', 'min:0', 'max:5000'], 'note' => ['nullable', 'string', 'max:200'], 'lock_version' => ['required', 'integer', 'min:0'],
            'lines' => ['required', 'array', 'min:1', 'max:'.PurchaseOrderService::MAX_LINES], 'lines.*.request_line_id' => ['nullable', 'string', 'size:26'], 'lines.*.item_id' => ['nullable', 'string', 'size:26'], 'lines.*.unit' => ['nullable', 'string', 'max:8'],
            'lines.*.quantity' => ['nullable', 'string', 'max:14'], 'lines.*.unit_price_minor' => ['nullable', 'integer', 'min:0', 'max:10000000000'], 'lines.*.department' => ['nullable', 'string', 'max:16'],
        ]);

        return $this->json(['order' => $this->orders->update($this->property->current(), $this->actor($request), $id, $data['supplier_id'], $data['location_id'], $data['expected_date'] ?? null, isset($data['tax_bp']) ? (int) $data['tax_bp'] : null, $data['note'] ?? null, array_values($data['lines']), (int) $data['lock_version'])]);
    }

    public function revise(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'string', 'size:26'], 'location_id' => ['required', 'string', 'size:26'], 'expected_date' => ['nullable', 'date_format:Y-m-d'], 'tax_bp' => ['nullable', 'integer', 'min:0', 'max:5000'], 'note' => ['nullable', 'string', 'max:200'],
            'reason' => ['required', 'string', 'max:200'], 'lock_version' => ['required', 'integer', 'min:0'],
            'lines' => ['required', 'array', 'min:1', 'max:'.PurchaseOrderService::MAX_LINES], 'lines.*.line_id' => ['nullable', 'string', 'size:26'], 'lines.*.item_id' => ['nullable', 'string', 'size:26'], 'lines.*.unit' => ['nullable', 'string', 'max:8'],
            'lines.*.quantity' => ['required', 'string', 'max:14'], 'lines.*.unit_price_minor' => ['nullable', 'integer', 'min:0', 'max:10000000000'], 'lines.*.department' => ['nullable', 'string', 'max:16'],
        ]);

        return $this->json(['order' => $this->orders->revise($this->property->current(), $this->actor($request), $id, $data['supplier_id'], $data['location_id'], $data['expected_date'] ?? null, isset($data['tax_bp']) ? (int) $data['tax_bp'] : null, $data['note'] ?? null, array_values($data['lines']), $data['reason'], (int) $data['lock_version'])]);
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['order' => $this->orders->submit($this->property->current(), $this->actor($request), $id, (int) $data['lock_version'])]);
    }

    public function release(Request $request, string $id): JsonResponse
    {
        return $this->json(['order' => $this->orders->release($this->property->current(), $this->actor($request), $id)]);
    }

    public function issue(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['order' => $this->orders->issue($this->property->current(), $this->actor($request), $id, (int) $data['lock_version'])]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['order' => $this->orders->cancel($this->property->current(), $this->actor($request), $id, $data['reason'], (int) $data['lock_version'])]);
    }

    public function close(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['order' => $this->orders->close($this->property->current(), $this->actor($request), $id, $data['reason'], (int) $data['lock_version'])]);
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
