<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Presentation\Http\Controllers;

use App\Modules\FnbSales\Application\RoomServiceService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Room service: the orders for the rooms and their delivery. Every rule and permission lives in `RoomServiceService`. */
final readonly class RoomServiceController
{
    public function __construct(private RoomServiceService $roomService, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('fnb-sales/pages/room-service', ['board' => $this->roomService->board($this->property->current(), $this->actor($request))]);
    }

    public function place(Request $request): JsonResponse
    {
        $data = $request->validate(['outlet_id' => ['required', 'string', 'size:26'], 'room_id' => ['required', 'string', 'size:26'], 'promised_time' => ['required', 'string', 'max:5'], 'covers' => ['required', 'integer', 'min:1'], 'note' => ['nullable', 'string', 'max:200']]);

        return response()->json($this->roomService->place($this->property->current(), $this->actor($request), $data['outlet_id'], $data['room_id'], $data['promised_time'], (int) $data['covers'], $data['note'] ?? null), 201);
    }

    public function advance(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'string', 'max:10'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return response()->json($this->roomService->advance($this->property->current(), $this->actor($request), $id, $data['status'], (int) $data['lock_version']));
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
