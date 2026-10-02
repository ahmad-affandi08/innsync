<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Presentation\Http\Controllers;

use App\Modules\InventoryPurchasing\Application\InventoryCatalogService;
use App\Modules\InventoryPurchasing\Application\StockService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Inventory catalog and stock screens. Every rule and permission lives in `InventoryCatalogService` and `StockService`. */
final readonly class InventoryCatalogController
{
    public function __construct(private InventoryCatalogService $catalog, private StockService $stock, private PropertyContext $property) {}

    public function items(Request $request): Response
    {
        return Inertia::render('inventory-purchasing/pages/items', ['catalog' => $this->catalog->overview($this->property->current(), $this->actor($request))]);
    }

    public function locations(Request $request): Response
    {
        return Inertia::render('inventory-purchasing/pages/locations', ['catalog' => $this->catalog->overview($this->property->current(), $this->actor($request))]);
    }

    public function valuation(Request $request): Response
    {
        $data = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d']]);

        return Inertia::render('inventory-purchasing/pages/valuation', [
            'report' => $this->stock->valuation($this->property->current(), $this->actor($request), $data['as_of'] ?? null),
        ]);
    }

    public function stock(Request $request): Response
    {
        $data = $request->validate(['location' => ['nullable', 'string', 'size:26'], 'item' => ['nullable', 'string', 'size:26']]);
        $property = $this->property->current();
        $actor = $this->actor($request);

        return Inertia::render('inventory-purchasing/pages/stock', [
            'position' => $this->stock->position($property, $actor, $data['location'] ?? null, $data['item'] ?? null),
            'movements' => $this->stock->movements($property, $actor, $data['item'] ?? null, $data['location'] ?? null, 50),
            'catalog' => $this->catalog->overview($property, $actor),
            'filters' => ['location' => $data['location'] ?? '', 'item' => $data['item'] ?? ''],
        ]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12'], 'name' => ['required', 'string', 'max:80'], 'negative_blocked' => ['nullable', 'boolean']]);

        return $this->json(['category' => $this->catalog->createCategory($this->property->current(), $this->actor($request), $data['code'], $data['name'], (bool) ($data['negative_blocked'] ?? false))], 201);
    }

    public function updateCategory(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'active' => ['required', 'boolean'], 'negative_blocked' => ['nullable', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['category' => $this->catalog->updateCategory($this->property->current(), $this->actor($request), $id, $data['name'], (bool) $data['active'], (int) $data['lock_version'], isset($data['negative_blocked']) ? (bool) $data['negative_blocked'] : null)]);
    }

    public function storeLocation(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12'], 'name' => ['required', 'string', 'max:80'], 'kind' => ['required', 'string', 'max:12'], 'negative_blocked' => ['nullable', 'boolean']]);

        return $this->json(['location' => $this->catalog->createLocation($this->property->current(), $this->actor($request), $data['code'], $data['name'], $data['kind'], (bool) ($data['negative_blocked'] ?? false))], 201);
    }

    public function updateLocation(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'kind' => ['required', 'string', 'max:12'], 'active' => ['required', 'boolean'], 'negative_blocked' => ['nullable', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['location' => $this->catalog->updateLocation($this->property->current(), $this->actor($request), $id, $data['name'], $data['kind'], (bool) $data['active'], (int) $data['lock_version'], isset($data['negative_blocked']) ? (bool) $data['negative_blocked'] : null)]);
    }

    public function storeItem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'], 'name' => ['required', 'string', 'max:120'], 'category_id' => ['required', 'string', 'size:26'],
            'department' => ['required', 'string', 'max:16'], 'base_unit' => ['required', 'string', 'max:8'],
        ]);

        return $this->json(['item' => $this->catalog->createItem($this->property->current(), $this->actor($request), $data['code'], $data['name'], $data['category_id'], $data['department'], $data['base_unit'])], 201);
    }

    public function updateItem(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'category_id' => ['required', 'string', 'size:26'], 'department' => ['required', 'string', 'max:16'],
            'active' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0'],
        ]);

        return $this->json(['item' => $this->catalog->updateItem($this->property->current(), $this->actor($request), $id, $data['name'], $data['category_id'], $data['department'], (bool) $data['active'], (int) $data['lock_version'])]);
    }

    public function addConversion(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['unit' => ['required', 'string', 'max:8'], 'factor' => ['required', 'string', 'max:14'], 'reason' => ['required', 'string', 'max:200']]);

        return $this->json(['conversion' => $this->catalog->addConversion($this->property->current(), $this->actor($request), $id, $data['unit'], $data['factor'], $data['reason'])], 201);
    }

    public function setLimits(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'string', 'size:26'], 'location_id' => ['required', 'string', 'size:26'], 'min' => ['required', 'string', 'max:14'],
            'max' => ['nullable', 'string', 'max:14'], 'lock_version' => ['nullable', 'integer', 'min:0'],
        ]);

        return $this->json(['limits' => $this->catalog->setLimits($this->property->current(), $this->actor($request), $data['item_id'], $data['location_id'], $data['min'], $data['max'] ?? null, isset($data['lock_version']) ? (int) $data['lock_version'] : null)]);
    }

    public function postOpening(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'string', 'size:26'], 'location_id' => ['required', 'string', 'size:26'], 'unit' => ['required', 'string', 'max:8'], 'quantity' => ['required', 'string', 'max:14'],
            'reference' => ['nullable', 'string', 'max:40'], 'note' => ['nullable', 'string', 'max:200'], 'unit_cost_minor' => ['required', 'integer', 'min:0', 'max:10000000000'],
        ]);

        return $this->json(['movement' => $this->stock->postOpening($this->property->current(), $this->actor($request), $data['item_id'], $data['location_id'], $data['unit'], $data['quantity'], $data['reference'] ?? null, $data['note'] ?? null, (int) $data['unit_cost_minor'])], 201);
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
