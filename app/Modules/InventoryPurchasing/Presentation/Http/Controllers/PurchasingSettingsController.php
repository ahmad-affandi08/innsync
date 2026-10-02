<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Presentation\Http\Controllers;

use App\Modules\InventoryPurchasing\Application\PurchasingSettingsService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The purchasing policy and the department budgets. Every rule and permission lives in the application service. */
final readonly class PurchasingSettingsController
{
    public function __construct(private PurchasingSettingsService $settings, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('inventory-purchasing/pages/purchasing-settings', ['overview' => $this->settings->overview($this->property->current(), $this->actor($request))]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'budget_policy' => ['required', 'string', 'max:8'], 'tax_bp' => ['required', 'integer', 'min:0', 'max:5000'], 'po_tolerance_bp' => ['required', 'integer', 'min:0', 'max:10000'], 'over_receipt_bp' => ['required', 'integer', 'min:0', 'max:10000'],
            'invoice_price_tolerance_bp' => ['required', 'integer', 'min:0', 'max:10000'], 'invoice_qty_tolerance_bp' => ['required', 'integer', 'min:0', 'max:10000'], 'lock_version' => ['required', 'integer', 'min:0'],
        ]);

        return $this->json($this->settings->update($this->property->current(), $this->actor($request), $data, (int) $data['lock_version']));
    }

    public function budget(Request $request): JsonResponse
    {
        $data = $request->validate(['department' => ['required', 'string', 'max:16'], 'period' => ['required', 'string', 'size:7'], 'amount_minor' => ['required', 'integer', 'min:0', 'max:9000000000000'], 'lock_version' => ['nullable', 'integer', 'min:0']]);

        return $this->json($this->settings->setBudget($this->property->current(), $this->actor($request), $data['department'], $data['period'], (int) $data['amount_minor'], isset($data['lock_version']) ? (int) $data['lock_version'] : null));
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
