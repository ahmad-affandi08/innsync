<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\Groups\GroupBookingService;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Group bookings: one booker, several rooms. Every rule lives in `GroupBookingService`. */
final readonly class GroupController
{
    public function __construct(private GroupBookingService $groups, private ReservationService $reservations, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $property = $this->property->current();
        $actor = $this->actor($request);
        $data = $request->validate(['query' => ['nullable', 'string', 'max:100']]);
        $overview = $this->groups->overview($property, $actor, $data['query'] ?? null);

        return Inertia::render('front-office/pages/groups', [
            'overview' => $overview, 'lookups' => $overview['may']['manage'] ? $this->reservations->lookups($property, $actor) : null, 'query' => $data['query'] ?? '',
        ]);
    }

    public function show(Request $request, string $id): Response
    {
        $property = $this->property->current();
        $actor = $this->actor($request);
        $view = $this->groups->view($property, $actor, $id);

        return Inertia::render('front-office/pages/group', ['group' => $view, 'lookups' => $view['may']['manage'] ? $this->reservations->lookups($property, $actor) : null]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'booker_name' => ['required', 'string', 'max:150'], 'booker_phone' => ['nullable', 'string', 'max:30'], 'booker_email' => ['nullable', 'string', 'max:190'],
            'source' => ['required', 'string', 'max:20'], 'arrival' => ['required', 'string', 'size:10'], 'departure' => ['required', 'string', 'size:10'], 'billing_mode' => ['required', 'string', 'max:8'],
            'route_extras' => ['nullable', 'boolean'], 'notes' => ['nullable', 'string', 'max:500'], 'status' => ['required', 'string', 'max:20'], ...$this->roomRules(),
        ]);

        return response()->json(['group' => $this->groups->create($this->property->current(), $this->actor($request), $data, $data['rooms'], $this->key($request))], 201)->header('Cache-Control', 'no-store');
    }

    public function addRooms(Request $request, string $id): JsonResponse
    {
        $data = $request->validate($this->roomRules());

        return response()->json(['group' => $this->groups->addRooms($this->property->current(), $this->actor($request), $id, $data['rooms'], $this->key($request))])->header('Cache-Control', 'no-store');
    }

    /** @return array<string, list<string>> */
    private function roomRules(): array
    {
        return [
            'rooms' => ['required', 'array', 'min:1', 'max:30'], 'rooms.*.room_type_id' => ['required', 'string', 'size:26'], 'rooms.*.rate_plan_id' => ['required', 'string', 'size:26'],
            'rooms.*.adults' => ['required', 'integer', 'min:1', 'max:40'], 'rooms.*.children' => ['required', 'integer', 'min:0', 'max:40'], 'rooms.*.guest_name' => ['nullable', 'string', 'max:150'],
        ];
    }

    private function key(Request $request): IdempotencyKey
    {
        return IdempotencyKey::fromString((string) $request->header('Idempotency-Key'));
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
