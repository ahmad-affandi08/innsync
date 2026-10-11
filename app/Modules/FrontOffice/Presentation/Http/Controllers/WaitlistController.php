<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\Waitlist\WaitlistService;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The waiting list for sold-out dates. Every rule and permission lives in the service. */
final readonly class WaitlistController
{
    public function __construct(private WaitlistService $waitlist, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('front-office/pages/waitlist', ['overview' => $this->waitlist->overview($this->property->current(), $this->actor($request))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'guest_name' => ['required', 'string', 'max:150'], 'phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'string', 'max:120'], 'room_type_id' => ['required', 'string', 'size:26'],
            'arrival' => ['required', 'date_format:Y-m-d'], 'departure' => ['required', 'date_format:Y-m-d'], 'adults' => ['required', 'integer', 'min:1', 'max:20'], 'children' => ['required', 'integer', 'min:0', 'max:20'], 'note' => ['nullable', 'string', 'max:300'],
        ]);
        $entry = $this->waitlist->add($this->property->current(), $this->actor($request), $data['guest_name'], $data['phone'] ?? null, $data['email'] ?? null, $data['room_type_id'], $data['arrival'], $data['departure'], (int) $data['adults'], (int) $data['children'], $data['note'] ?? null);

        return $this->json(['entry' => $entry], 201);
    }

    public function offer(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['entry' => $this->waitlist->offer($this->property->current(), $this->actor($request), $id, (int) $data['lock_version'])]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200'], 'lock_version' => ['required', 'integer', 'min:0']]);
        $this->waitlist->cancel($this->property->current(), $this->actor($request), $id, $data['reason'], (int) $data['lock_version']);

        return $this->json(['ok' => true]);
    }

    public function book(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['rate_plan_id' => ['required', 'string', 'size:26'], 'status' => ['required', 'string', 'max:10'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json($this->waitlist->book($this->property->current(), $this->actor($request), $id, $data['rate_plan_id'], $data['status'], (int) $data['lock_version'], IdempotencyKey::fromString((string) $request->header('Idempotency-Key'))), 201);
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
