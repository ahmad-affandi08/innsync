<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\RoomPlan\RoomPlanService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The room a booking that has not arrived is planned for, set from the room calendar. */
final readonly class RoomPlanController
{
    public function __construct(private RoomPlanService $plans, private PropertyContext $property) {}

    public function plan(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['room_id' => ['present', 'nullable', 'string', 'size:26']]);
        $this->plans->plan($this->property->current(), (string) $request->user()->getAuthIdentifier(), $id, $data['room_id']);

        return response()->json(['ok' => true])->header('Cache-Control', 'no-store');
    }
}
