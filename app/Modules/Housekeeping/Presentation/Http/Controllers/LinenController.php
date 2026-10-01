<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Presentation\Http\Controllers;

use App\Modules\Housekeeping\Application\LinenService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Linen screens and actions. Every rule and permission lives in `LinenService`. */
final readonly class LinenController
{
    public function __construct(private LinenService $linen, private PropertyContext $property) {}

    public function index(Request $request): Response|JsonResponse
    {
        $position = $this->linen->position($this->property->current(), $this->actor($request));

        return $request->wantsJson() && ! $request->header('X-Inertia')
            ? $this->json($position)
            : Inertia::render('housekeeping/pages/linen', ['linen' => $position]);
    }

    public function usage(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'room_id' => ['nullable', 'string', 'size:26']]);

        return $this->json(['usage' => $this->linen->usage($this->property->current(), $this->actor($request), $data['from'] ?? null, $data['to'] ?? null, $data['room_id'] ?? null)]);
    }

    public function storeItem(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20'], 'name' => ['required', 'string', 'max:80'], 'kind' => ['required', 'string', 'max:10'], 'unit' => ['required', 'string', 'max:12']]);

        return $this->json(['item' => $this->linen->createItem($this->property->current(), $this->actor($request), $data['code'], $data['name'], $data['kind'], $data['unit'])], 201);
    }

    public function itemActive(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['item' => $this->linen->setItemActive($this->property->current(), $this->actor($request), $id, (bool) $data['active'], (int) $data['lock_version'])]);
    }

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'string', 'size:26'], 'from' => ['required', 'string', 'max:10'], 'to' => ['required', 'string', 'max:10'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'], 'note' => ['nullable', 'string', 'max:200'],
        ]);
        $key = (string) $request->header('Idempotency-Key');

        return $this->json(['transfer' => $this->linen->send($this->property->current(), $this->actor($request), $data['item_id'], $data['from'], $data['to'], (int) $data['quantity'], $data['note'] ?? null, $key === '' ? null : 'lin:'.$key)], 201);
    }

    public function receive(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'quantity_received' => ['required', 'integer', 'min:0', 'max:1000000'], 'variance_kind' => ['nullable', 'string', 'max:10'],
            'variance_note' => ['nullable', 'string', 'max:200'], 'lock_version' => ['required', 'integer', 'min:0'],
        ]);

        return $this->json(['transfer' => $this->linen->receive($this->property->current(), $this->actor($request), $id, (int) $data['quantity_received'], $data['variance_kind'] ?? null, $data['variance_note'] ?? null, (int) $data['lock_version'])]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['transfer' => $this->linen->cancel($this->property->current(), $this->actor($request), $id, (int) $data['lock_version'])]);
    }

    public function recordUsage(Request $request): JsonResponse
    {
        $data = $request->validate(['room_id' => ['required', 'string', 'size:26'], 'item_id' => ['required', 'string', 'size:26'], 'quantity' => ['required', 'integer', 'min:1', 'max:1000'], 'note' => ['nullable', 'string', 'max:200']]);
        $this->linen->recordUsage($this->property->current(), $this->actor($request), $data['room_id'], $data['item_id'], (int) $data['quantity'], $data['note'] ?? null);

        return $this->json(['recorded' => true], 201);
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
