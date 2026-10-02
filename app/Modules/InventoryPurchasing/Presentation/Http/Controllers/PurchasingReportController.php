<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Presentation\Http\Controllers;

use App\Modules\InventoryPurchasing\Application\PurchasingReportService;
use App\Modules\InventoryPurchasing\Application\SupplierQuoteService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Purchasing reports and the quotation comparison. Every rule and permission lives in the application services. */
final readonly class PurchasingReportController
{
    public function __construct(private PurchasingReportService $reports, private SupplierQuoteService $quotes, private PropertyContext $property) {}

    public function purchases(Request $request): Response
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'supplier' => ['nullable', 'string', 'size:26'], 'department' => ['nullable', 'string', 'max:16']]);

        return Inertia::render('inventory-purchasing/pages/purchase-report', ['report' => $this->reports->purchases($this->property->current(), $this->actor($request), $data['from'] ?? null, $data['to'] ?? null, $data['supplier'] ?? null, $data['department'] ?? null), 'filters' => ['supplier' => $data['supplier'] ?? '', 'department' => $data['department'] ?? '']]);
    }

    public function deliveries(Request $request): Response
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);

        return Inertia::render('inventory-purchasing/pages/delivery-report', ['report' => $this->reports->deliveries($this->property->current(), $this->actor($request), $data['from'] ?? null, $data['to'] ?? null)]);
    }

    public function quotes(Request $request): Response
    {
        $data = $request->validate(['items' => ['nullable', 'array', 'max:10'], 'items.*' => ['string', 'size:26']]);

        return Inertia::render('inventory-purchasing/pages/quotes', ['overview' => $this->quotes->overview($this->property->current(), $this->actor($request), array_values($data['items'] ?? []))]);
    }

    public function recordQuote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'string', 'size:26'], 'item_id' => ['required', 'string', 'size:26'], 'unit' => ['required', 'string', 'max:8'], 'unit_price_minor' => ['required', 'integer', 'min:0', 'max:10000000000'],
            'quoted_on' => ['nullable', 'date_format:Y-m-d'], 'valid_until' => ['required', 'date_format:Y-m-d'], 'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:365'], 'min_quantity' => ['nullable', 'string', 'max:14'],
            'reference' => ['nullable', 'string', 'max:40'], 'note' => ['nullable', 'string', 'max:200'],
        ]);

        return response()->json(['overview' => $this->quotes->record($this->property->current(), $this->actor($request), $data['supplier_id'], $data['item_id'], $data['unit'], (int) $data['unit_price_minor'], $data['quoted_on'] ?? null, $data['valid_until'], (int) ($data['lead_time_days'] ?? 0), $data['min_quantity'] ?? null, $data['reference'] ?? null, $data['note'] ?? null)], 201)->header('Cache-Control', 'no-store');
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
