<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\Inventory\InventoryAdminService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Rooms out of order or out of service, allotments and holds, and the overbooking allowance. */
final readonly class InventoryController
{
    public function __construct(private InventoryAdminService $inventory, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('front-office/pages/inventory', $this->inventory->overview($this->property->current(), $this->actor($request)));
    }

    public function block(Request $request): JsonResponse
    {
        $data = $request->validate(['room_id' => ['required', 'string', 'size:26'], 'kind' => ['required', 'string', 'max:20'], 'from' => ['required', 'string', 'size:10'], 'to' => ['required', 'string', 'size:10'], 'reason' => ['required', 'string', 'max:500']]);
        $result = $this->inventory->blockRoom($this->property->current(), $this->actor($request), $data['room_id'], $data['kind'], $data['from'], $data['to'], $data['reason']);

        return $this->json(['block' => $result['block']->toArray(), 'oversold_nights' => $result['oversold_nights']], 201);
    }

    public function releaseBlock(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->inventory->releaseBlock($this->property->current(), $this->actor($request), $id, $data['reason']);

        return $this->json(['released' => true]);
    }

    public function hold(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_type_id' => ['required', 'string', 'size:26'], 'from' => ['required', 'string', 'size:10'], 'to' => ['required', 'string', 'size:10'],
            'rooms' => ['required', 'integer', 'min:1', 'max:500'], 'reason' => ['required', 'string', 'max:500'], 'expires_at' => ['nullable', 'string', 'max:40'],
        ]);
        $hold = $this->inventory->placeHold($this->property->current(), $this->actor($request), $data['room_type_id'], $data['from'], $data['to'], (int) $data['rooms'], $data['reason'], $data['expires_at'] ?? null);

        return $this->json(['hold' => $hold->toArray()], 201);
    }

    public function releaseHold(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->inventory->releaseHold($this->property->current(), $this->actor($request), $id, $data['reason']);

        return $this->json(['released' => true]);
    }

    public function allowance(Request $request, string $typeId): JsonResponse
    {
        $data = $request->validate(['rooms' => ['required', 'integer', 'min:0', 'max:20'], 'lock_version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:500']]);
        $version = $this->inventory->setOverbookingAllowance($this->property->current(), $this->actor($request), $typeId, (int) $data['rooms'], (int) $data['lock_version'], $data['reason']);

        return $this->json(['lock_version' => $version]);
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
