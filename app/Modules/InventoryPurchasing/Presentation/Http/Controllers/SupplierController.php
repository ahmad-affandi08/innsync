<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Presentation\Http\Controllers;

use App\Modules\InventoryPurchasing\Application\SupplierService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Suppliers, their price lists and ratings. Every rule and permission lives in the application service. */
final readonly class SupplierController
{
    public function __construct(private SupplierService $suppliers, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('inventory-purchasing/pages/suppliers', ['overview' => $this->suppliers->overview($this->property->current(), $this->actor($request))]);
    }

    public function show(Request $request, string $id): Response
    {
        return Inertia::render('inventory-purchasing/pages/supplier', ['supplier' => $this->suppliers->show($this->property->current(), $this->actor($request), $id)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:12'], 'name' => ['required', 'string', 'max:120'], 'contact_name' => ['nullable', 'string', 'max:80'], 'phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:200'], 'tax_id' => ['nullable', 'string', 'max:24'], 'payment_terms_days' => ['required', 'integer', 'min:0', 'max:180'], 'note' => ['nullable', 'string', 'max:200'],
        ]);

        return $this->json(['supplier' => $this->suppliers->create($this->property->current(), $this->actor($request), $data['code'], $data['name'], $data)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'active' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0'], 'contact_name' => ['nullable', 'string', 'max:80'], 'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'string', 'max:120'], 'address' => ['nullable', 'string', 'max:200'], 'tax_id' => ['nullable', 'string', 'max:24'], 'payment_terms_days' => ['required', 'integer', 'min:0', 'max:180'], 'note' => ['nullable', 'string', 'max:200'],
        ]);

        return $this->json(['supplier' => $this->suppliers->update($this->property->current(), $this->actor($request), $id, $data['name'], $data, (bool) $data['active'], (int) $data['lock_version'])]);
    }

    public function addPrice(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['item_id' => ['required', 'string', 'size:26'], 'unit' => ['required', 'string', 'max:8'], 'unit_price_minor' => ['required', 'integer', 'min:0', 'max:10000000000'], 'valid_from' => ['required', 'date_format:Y-m-d'], 'reason' => ['nullable', 'string', 'max:200']]);

        return $this->json(['supplier' => $this->suppliers->addPrice($this->property->current(), $this->actor($request), $id, $data['item_id'], $data['unit'], (int) $data['unit_price_minor'], $data['valid_from'], $data['reason'] ?? null)], 201);
    }

    public function rate(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['score' => ['required', 'integer', 'min:1', 'max:5'], 'aspect' => ['required', 'string', 'max:12'], 'comment' => ['nullable', 'string', 'max:200']]);

        return $this->json(['supplier' => $this->suppliers->rate($this->property->current(), $this->actor($request), $id, (int) $data['score'], $data['aspect'], $data['comment'] ?? null)], 201);
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
