<?php

declare(strict_types=1);

namespace App\Modules\Laundry\Presentation\Http\Controllers;

use App\Modules\Laundry\Application\LaundryRequest;
use App\Modules\Laundry\Application\LaundryService;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Guest laundry screens and actions. Every rule and permission lives in `LaundryService`. */
final readonly class LaundryController
{
    public function __construct(private LaundryService $laundry, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $property = $this->property->current();
        $actor = $this->actor($request);

        return Inertia::render('laundry/pages/queue', ['orders' => $this->laundry->queue($property, $actor), 'currency' => $this->laundry->currency($property), 'may' => $this->laundry->abilities($property, $actor)]);
    }

    public function show(Request $request, string $id): Response
    {
        $property = $this->property->current();
        $actor = $this->actor($request);

        return Inertia::render('laundry/pages/order', ['order' => $this->laundry->view($property, $actor, $id), 'currency' => $this->laundry->currency($property), 'may' => $this->laundry->abilities($property, $actor)]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('laundry/pages/intake', ['lookups' => $this->laundry->intakeLookups($this->property->current(), $this->actor($request)), 'currency' => $this->laundry->currency($this->property->current())]);
    }

    public function prices(Request $request): Response
    {
        $property = $this->property->current();
        $actor = $this->actor($request);

        return Inertia::render('laundry/pages/prices', ['items' => $this->laundry->priceList($property, $actor), 'currency' => $this->laundry->currency($property), 'may' => $this->laundry->abilities($property, $actor)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'barcode' => ['required', 'string', 'max:40'], 'room_id' => ['required', 'string', 'size:26'], 'express' => ['nullable', 'boolean'],
            'promised_date' => ['required', 'string', 'size:10'], 'promised_time' => ['required', 'string', 'max:8'], 'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:30'], 'lines.*.price_item_id' => ['required', 'string', 'size:26'], 'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'lines.*.brand' => ['nullable', 'string', 'max:60'], 'lines.*.condition_note' => ['nullable', 'string', 'max:200'],
        ]);

        $order = $this->laundry->intake(
            $this->property->current(),
            $this->actor($request),
            new LaundryRequest($data['barcode'], $data['room_id'], (bool) ($data['express'] ?? false), $data['promised_date'], $data['promised_time'], $data['notes'] ?? null, array_values($data['lines'])),
            IdempotencyKey::fromString((string) $request->header('Idempotency-Key')),
        );

        return $this->json(['order' => $order], 201);
    }

    public function receive(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['counts' => ['required', 'array', 'min:1'], 'counts.*' => ['required', 'integer', 'min:0', 'max:999'], 'note' => ['nullable', 'string', 'max:500'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['order' => $this->laundry->receive($this->property->current(), $this->actor($request), $id, array_map('intval', $data['counts']), $data['note'] ?? null, (int) $data['lock_version'])]);
    }

    public function advance(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['order' => $this->laundry->advance($this->property->current(), $this->actor($request), $id, (int) $data['lock_version'])]);
    }

    public function ready(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['order' => $this->laundry->markReady($this->property->current(), $this->actor($request), $id, (int) $data['lock_version'])]);
    }

    public function deliver(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['receipt' => ['required', 'string', 'max:200'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['order' => $this->laundry->deliver($this->property->current(), $this->actor($request), $id, $data['receipt'], (int) $data['lock_version'])]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:300'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['order' => $this->laundry->cancel($this->property->current(), $this->actor($request), $id, $data['reason'], (int) $data['lock_version'])]);
    }

    public function addPrice(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20'], 'name' => ['required', 'string', 'max:80'], 'unit_price_minor' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:300']]);

        return $this->json(['item' => $this->laundry->addPriceItem($this->property->current(), $this->actor($request), $data['code'], $data['name'], (int) $data['unit_price_minor'], $data['reason'])], 201);
    }

    public function updatePrice(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'unit_price_minor' => ['required', 'integer', 'min:0'], 'is_active' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:300']]);

        return $this->json(['item' => $this->laundry->updatePriceItem($this->property->current(), $this->actor($request), $id, $data['name'], (int) $data['unit_price_minor'], (bool) $data['is_active'], (int) $data['lock_version'], $data['reason'])]);
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }

    /** @param array<string, mixed> $body */
    private function json(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status)->header('Cache-Control', 'no-store');
    }
}
