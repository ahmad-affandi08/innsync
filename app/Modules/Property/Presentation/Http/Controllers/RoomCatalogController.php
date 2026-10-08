<?php

declare(strict_types=1);

namespace App\Modules\Property\Presentation\Http\Controllers;

use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Catalog\RoomPhotoService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Room types and rooms. Authorization and every rule live in `RoomCatalogService`; this only moves data. */
final readonly class RoomCatalogController
{
    public function __construct(private RoomCatalogService $catalog, private PropertyContext $property, private RoomPhotoService $photos) {}

    public function index(Request $request): Response
    {
        $property = $this->property->current();
        $actor = (string) $request->user()->getAuthIdentifier();

        $types = array_map(static fn ($t): array => $t->toArray(), $this->catalog->listTypes($property, $actor));

        return Inertia::render('property/pages/room-catalog', [
            'types' => $types,
            'rooms' => array_map(static fn ($r): array => $r->toArray(), $this->catalog->listRooms($property, $actor)),
            'photos' => (object) $this->photos->overview($property, $actor, array_column($types, 'id')),
        ]);
    }

    public function storeType(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'max_adults' => ['required', 'integer', 'min:1', 'max:20'],
            'max_children' => ['required', 'integer', 'min:0', 'max:20'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $type = $this->catalog->createType($this->property->current(), $this->actor($request), $data['code'], $data['name'], $data['description'] ?? null, (int) $data['max_adults'], (int) $data['max_children'], (int) ($data['sort_order'] ?? 0), $data['reason']);

        return response()->json(['type' => $type->toArray()], 201)->header('Cache-Control', 'no-store');
    }

    public function updateType(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'max_adults' => ['required', 'integer', 'min:1', 'max:20'],
            'max_children' => ['required', 'integer', 'min:0', 'max:20'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'lock_version' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $type = $this->catalog->updateType($this->property->current(), $this->actor($request), $id, $data['name'], $data['description'] ?? null, (int) $data['max_adults'], (int) $data['max_children'], (int) ($data['sort_order'] ?? 0), (int) $data['lock_version'], $data['reason']);

        return response()->json(['type' => $type->toArray()])->header('Cache-Control', 'no-store');
    }

    public function typeActive(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:500']]);

        $type = $this->catalog->setTypeActive($this->property->current(), $this->actor($request), $id, (bool) $data['active'], (int) $data['lock_version'], $data['reason']);

        return response()->json(['type' => $type->toArray()])->header('Cache-Control', 'no-store');
    }

    public function storeRoom(Request $request): JsonResponse
    {
        $data = $request->validate([
            'number' => ['required', 'string', 'max:20'],
            'room_type_id' => ['required', 'string', 'size:26'],
            'floor' => ['nullable', 'string', 'max:10'],
            'building' => ['nullable', 'string', 'max:40'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $room = $this->catalog->createRoom($this->property->current(), $this->actor($request), $data['number'], $data['room_type_id'], $data['floor'] ?? null, $data['reason'], $data['building'] ?? null);

        return response()->json(['room' => $room->toArray()], 201)->header('Cache-Control', 'no-store');
    }

    public function updateRoom(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'room_type_id' => ['required', 'string', 'size:26'],
            'floor' => ['nullable', 'string', 'max:10'],
            'building' => ['nullable', 'string', 'max:40'],
            'lock_version' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $room = $this->catalog->updateRoom($this->property->current(), $this->actor($request), $id, $data['room_type_id'], $data['floor'] ?? null, (int) $data['lock_version'], $data['reason'], $data['building'] ?? null);

        return response()->json(['room' => $room->toArray()])->header('Cache-Control', 'no-store');
    }

    public function roomActive(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:500']]);

        $room = $this->catalog->setRoomActive($this->property->current(), $this->actor($request), $id, (bool) $data['active'], (int) $data['lock_version'], $data['reason']);

        return response()->json(['room' => $room->toArray()])->header('Cache-Control', 'no-store');
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
