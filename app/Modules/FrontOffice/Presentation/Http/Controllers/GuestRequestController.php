<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\Requests\GuestRequestService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The guest request queue and its actions. Every rule and permission lives in `GuestRequestService`. */
final readonly class GuestRequestController
{
    public function __construct(private GuestRequestService $requests, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['status' => ['nullable', 'string', 'max:12'], 'category' => ['nullable', 'string', 'max:20'], 'room' => ['nullable', 'string', 'size:26']]);
        $property = $this->property->current();
        $actor = $this->actor($request);
        $status = array_key_exists('status', $data) ? $data['status'] : 'active';

        return Inertia::render('front-office/pages/requests', [
            'queue' => $this->requests->queue($property, $actor, $status, $data['category'] ?? null, $data['room'] ?? null, null),
            'filters' => ['status' => $status ?? '', 'category' => $data['category'] ?? '', 'room' => $data['room'] ?? ''],
            'in_house' => $this->requests->inHouse($property, $actor),
        ]);
    }

    public function open(Request $request): JsonResponse
    {
        $data = $request->validate([
            'stay_id' => ['required', 'string', 'size:26'], 'category' => ['required', 'string', 'max:20'], 'title' => ['required', 'string', 'max:120'],
            'detail' => ['nullable', 'string', 'max:500'], 'urgent' => ['nullable', 'boolean'],
        ]);

        return $this->json(['request' => $this->requests->open($this->property->current(), $this->actor($request), $data['stay_id'], $data['category'], $data['title'], $data['detail'] ?? null, (bool) ($data['urgent'] ?? false), ($key = (string) $request->header('Idempotency-Key')) === '' ? null : 'req:'.$key)], 201);
    }

    public function start(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['request' => $this->requests->start($this->property->current(), $this->actor($request), $id, (int) $data['lock_version'])]);
    }

    public function complete(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0'], 'resolution' => ['nullable', 'string', 'max:300']]);

        return $this->json(['request' => $this->requests->complete($this->property->current(), $this->actor($request), $id, (int) $data['lock_version'], $data['resolution'] ?? null)]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:300']]);

        return $this->json(['request' => $this->requests->cancel($this->property->current(), $this->actor($request), $id, (int) $data['lock_version'], $data['reason'])]);
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
