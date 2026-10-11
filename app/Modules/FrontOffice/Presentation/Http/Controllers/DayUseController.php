<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\DayUse\DayUseService;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Day use: the prices, the bookings, letting a guest in with the payment, and the guest leaving. Every rule and permission lives in the service. */
final readonly class DayUseController
{
    public function __construct(private DayUseService $dayUse, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('front-office/pages/day-use', ['overview' => $this->dayUse->overview($this->property->current(), $this->actor($request))]);
    }

    public function addRate(Request $request): JsonResponse
    {
        $d = $request->validate(['room_type_id' => ['required', 'string', 'size:26'], 'price_minor' => ['required', 'integer', 'min:1', 'max:100000000000'], 'max_hours' => ['required', 'integer', 'min:1', 'max:100']]);
        $this->dayUse->addRate($this->property->current(), $this->actor($request), $d['room_type_id'], (int) $d['price_minor'], (int) $d['max_hours']);

        return $this->json(['ok' => true], 201);
    }

    public function changeRate(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['price_minor' => ['required', 'integer', 'min:1', 'max:100000000000'], 'max_hours' => ['required', 'integer', 'min:1', 'max:100'], 'is_active' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0']]);
        $this->dayUse->changeRate($this->property->current(), $this->actor($request), $id, (int) $d['price_minor'], (int) $d['max_hours'], (bool) $d['is_active'], (int) $d['lock_version']);

        return $this->json(['ok' => true]);
    }

    public function book(Request $request): JsonResponse
    {
        $d = $request->validate([
            'room_id' => ['required', 'string', 'size:26'], 'starts_on' => ['required', 'date_format:Y-m-d'], 'starts_at' => ['required', 'string', 'max:5'], 'hours' => ['required', 'integer', 'min:1', 'max:100'],
            'guest_name' => ['required', 'string', 'max:120'], 'guest_phone' => ['nullable', 'string', 'max:30'], 'guests' => ['required', 'integer', 'min:1', 'max:100'], 'note' => ['nullable', 'string', 'max:200'],
        ]);
        $made = $this->dayUse->book($this->property->current(), $this->actor($request), IdempotencyKey::fromString((string) $request->header('Idempotency-Key')), $d['room_id'], $d['starts_on'], $d['starts_at'], (int) $d['hours'], $d['guest_name'], $d['guest_phone'] ?? null, (int) $d['guests'], $d['note'] ?? null);

        return $this->json($made, 201);
    }

    public function start(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['payment_method' => ['required', 'string', 'max:8'], 'reference' => ['nullable', 'string', 'max:60'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['booking' => $this->dayUse->start($this->property->current(), $this->actor($request), $id, $d['payment_method'], $d['reference'] ?? null, (int) $d['lock_version'])]);
    }

    public function finish(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['booking' => $this->dayUse->finish($this->property->current(), $this->actor($request), $id, (int) $d['lock_version'])]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:200'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['booking' => $this->dayUse->cancel($this->property->current(), $this->actor($request), $id, $d['reason'], (int) $d['lock_version'])]);
    }

    public function noShow(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['booking' => $this->dayUse->noShow($this->property->current(), $this->actor($request), $id, (int) $d['lock_version'])]);
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
