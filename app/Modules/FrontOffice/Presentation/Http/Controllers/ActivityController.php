<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\Activities\ActivityService;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Spa and activities: the services, the bookings of in-house guests, and what happens to a booking. Every rule and permission lives in the service. */
final readonly class ActivityController
{
    public function __construct(private ActivityService $activities, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('front-office/pages/activities', ['overview' => $this->activities->overview($this->property->current(), $this->actor($request))]);
    }

    public function addService(Request $request): JsonResponse
    {
        $d = $request->validate(['code' => ['required', 'string', 'max:20'], 'name' => ['required', 'string', 'max:120'], 'category' => ['required', 'string', 'max:8'], 'duration_minutes' => ['required', 'integer', 'min:1', 'max:1000'], 'price_minor' => ['required', 'integer', 'min:0', 'max:100000000000'], 'capacity' => ['required', 'integer', 'min:1', 'max:1000']]);
        $this->activities->addService($this->property->current(), $this->actor($request), $d['code'], $d['name'], $d['category'], (int) $d['duration_minutes'], (int) $d['price_minor'], (int) $d['capacity']);

        return $this->json(['ok' => true], 201);
    }

    public function changeService(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'duration_minutes' => ['required', 'integer', 'min:1', 'max:1000'], 'price_minor' => ['required', 'integer', 'min:0', 'max:100000000000'], 'capacity' => ['required', 'integer', 'min:1', 'max:1000'], 'is_active' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0']]);
        $this->activities->changeService($this->property->current(), $this->actor($request), $id, $d['name'], (int) $d['duration_minutes'], (int) $d['price_minor'], (int) $d['capacity'], (bool) $d['is_active'], (int) $d['lock_version']);

        return $this->json(['ok' => true]);
    }

    public function book(Request $request): JsonResponse
    {
        $d = $request->validate(['service_id' => ['required', 'string', 'size:26'], 'reservation_id' => ['required', 'string', 'size:26'], 'starts_on' => ['required', 'date_format:Y-m-d'], 'starts_at' => ['required', 'string', 'max:5'], 'guests' => ['required', 'integer', 'min:1', 'max:1000'], 'note' => ['nullable', 'string', 'max:200']]);
        $made = $this->activities->book($this->property->current(), $this->actor($request), IdempotencyKey::fromString((string) $request->header('Idempotency-Key')), $d['service_id'], $d['reservation_id'], $d['starts_on'], $d['starts_at'], (int) $d['guests'], $d['note'] ?? null);

        return $this->json($made, 201);
    }

    public function complete(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['booking' => $this->activities->complete($this->property->current(), $this->actor($request), $id, (int) $d['lock_version'])]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:200'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['booking' => $this->activities->cancel($this->property->current(), $this->actor($request), $id, $d['reason'], (int) $d['lock_version'])]);
    }

    public function noShow(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['booking' => $this->activities->noShow($this->property->current(), $this->actor($request), $id, (int) $d['lock_version'])]);
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
