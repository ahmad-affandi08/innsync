<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Presentation\Http\Controllers;

use App\Modules\FnbSales\Application\MenuService;
use App\Modules\FnbSales\Application\OutletService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Outlets, tables, the menu and its groups of choices. Every rule and permission lives in the application services. */
final readonly class SetupController
{
    public function __construct(private OutletService $outlets, private MenuService $menu, private PropertyContext $property) {}

    public function outlets(Request $request): Response
    {
        return Inertia::render('fnb-sales/pages/outlets', ['overview' => $this->outlets->overview($this->property->current(), $this->actor($request))]);
    }

    public function storeOutlet(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12'], 'name' => ['required', 'string', 'max:80'], 'kind' => ['required', 'string', 'max:14'], 'charge_scope' => ['required', 'string', 'max:16'], 'prices_include_charges' => ['required', 'boolean']]);

        return $this->json(['outlet' => $this->outlets->create($this->property->current(), $this->actor($request), $data['code'], $data['name'], $data['kind'], $data['charge_scope'], (bool) $data['prices_include_charges'])], 201);
    }

    public function updateOutlet(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'kind' => ['required', 'string', 'max:14'], 'charge_scope' => ['required', 'string', 'max:16'], 'prices_include_charges' => ['required', 'boolean'], 'active' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['outlet' => $this->outlets->update($this->property->current(), $this->actor($request), $id, $data['name'], $data['kind'], $data['charge_scope'], (bool) $data['prices_include_charges'], (bool) $data['active'], (int) $data['lock_version'])]);
    }

    public function tables(Request $request, string $id): Response
    {
        return Inertia::render('fnb-sales/pages/tables', ['overview' => $this->outlets->tables($this->property->current(), $this->actor($request), $id)]);
    }

    public function storeTable(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:8'], 'area' => ['nullable', 'string', 'max:40'], 'seats' => ['required', 'integer', 'min:1', 'max:500']]);

        return $this->json(['table' => $this->outlets->addTable($this->property->current(), $this->actor($request), $id, $data['code'], $data['area'] ?? null, (int) $data['seats'])], 201);
    }

    public function updateTable(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['area' => ['nullable', 'string', 'max:40'], 'seats' => ['required', 'integer', 'min:1', 'max:500'], 'active' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['table' => $this->outlets->updateTable($this->property->current(), $this->actor($request), $id, $data['area'] ?? null, (int) $data['seats'], (bool) $data['active'], (int) $data['lock_version'])]);
    }

    public function menu(Request $request): Response
    {
        $data = $request->validate(['outlet' => ['nullable', 'string', 'size:26']]);

        return Inertia::render('fnb-sales/pages/menu', ['menu' => $this->menu->menu($this->property->current(), $this->actor($request), $data['outlet'] ?? null)]);
    }

    public function storeCategory(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12'], 'name' => ['required', 'string', 'max:80'], 'station' => ['required', 'string', 'max:8'], 'sort_order' => ['required', 'integer', 'min:0', 'max:9999']]);

        return $this->json(['category' => $this->menu->addCategory($this->property->current(), $this->actor($request), $id, $data['code'], $data['name'], $data['station'], (int) $data['sort_order'])], 201);
    }

    public function updateCategory(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'station' => ['required', 'string', 'max:8'], 'sort_order' => ['required', 'integer', 'min:0', 'max:9999'], 'active' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['category' => $this->menu->updateCategory($this->property->current(), $this->actor($request), $id, $data['name'], $data['station'], (int) $data['sort_order'], (bool) $data['active'], (int) $data['lock_version'])]);
    }

    public function storeItem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:16'], 'category_id' => ['required', 'string', 'size:26'], 'name' => ['required', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:200'], 'price_minor' => ['required', 'integer', 'min:0', 'max:9000000000000'],
            'station' => ['nullable', 'string', 'max:8'], 'sort_order' => ['required', 'integer', 'min:0', 'max:9999'], 'variants' => ['nullable', 'array', 'max:12'], 'variants.*.id' => ['nullable', 'string', 'size:26'], 'variants.*.name' => ['required', 'string', 'max:40'],
            'variants.*.price_minor' => ['required', 'integer', 'min:0', 'max:9000000000000'], 'group_ids' => ['nullable', 'array', 'max:20'], 'group_ids.*' => ['string', 'size:26'],
        ]);

        return $this->json(['item' => $this->menu->addItem($this->property->current(), $this->actor($request), $data['category_id'], $data['code'], $data['name'], $data['description'] ?? null, (int) $data['price_minor'], $data['station'] ?? null, $data['variants'] ?? [], $data['group_ids'] ?? [], (int) $data['sort_order'])], 201);
    }

    public function updateItem(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'string', 'size:26'], 'name' => ['required', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:200'], 'price_minor' => ['required', 'integer', 'min:0', 'max:9000000000000'],
            'station' => ['nullable', 'string', 'max:8'], 'sort_order' => ['required', 'integer', 'min:0', 'max:9999'], 'variants' => ['nullable', 'array', 'max:12'], 'variants.*.id' => ['nullable', 'string', 'size:26'], 'variants.*.name' => ['required', 'string', 'max:40'],
            'variants.*.price_minor' => ['required', 'integer', 'min:0', 'max:9000000000000'], 'group_ids' => ['nullable', 'array', 'max:20'], 'group_ids.*' => ['string', 'size:26'], 'active' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0'],
        ]);

        return $this->json(['item' => $this->menu->updateItem($this->property->current(), $this->actor($request), $id, $data['category_id'], $data['name'], $data['description'] ?? null, (int) $data['price_minor'], $data['station'] ?? null, $data['variants'] ?? [], $data['group_ids'] ?? [], (int) $data['sort_order'], (bool) $data['active'], (int) $data['lock_version'])]);
    }

    public function availability(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['available' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['item' => $this->menu->setAvailability($this->property->current(), $this->actor($request), $id, (bool) $data['available'], (int) $data['lock_version'])]);
    }

    public function storeGroup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:12'], 'name' => ['required', 'string', 'max:60'], 'min_select' => ['required', 'integer', 'min:0', 'max:10'], 'max_select' => ['required', 'integer', 'min:1', 'max:10'], 'modifiers' => ['required', 'array', 'min:1', 'max:30'],
            'modifiers.*.id' => ['nullable', 'string', 'size:26'], 'modifiers.*.name' => ['required', 'string', 'max:40'], 'modifiers.*.price_delta_minor' => ['required', 'integer', 'min:0', 'max:9000000000000'],
        ]);

        return $this->json(['group' => $this->menu->addGroup($this->property->current(), $this->actor($request), $data['code'], $data['name'], (int) $data['min_select'], (int) $data['max_select'], $data['modifiers'])], 201);
    }

    public function updateGroup(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'], 'min_select' => ['required', 'integer', 'min:0', 'max:10'], 'max_select' => ['required', 'integer', 'min:1', 'max:10'], 'modifiers' => ['required', 'array', 'min:1', 'max:30'],
            'modifiers.*.id' => ['nullable', 'string', 'size:26'], 'modifiers.*.name' => ['required', 'string', 'max:40'], 'modifiers.*.price_delta_minor' => ['required', 'integer', 'min:0', 'max:9000000000000'], 'active' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0'],
        ]);

        return $this->json(['group' => $this->menu->updateGroup($this->property->current(), $this->actor($request), $id, $data['name'], (int) $data['min_select'], (int) $data['max_select'], $data['modifiers'], (bool) $data['active'], (int) $data['lock_version'])]);
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
