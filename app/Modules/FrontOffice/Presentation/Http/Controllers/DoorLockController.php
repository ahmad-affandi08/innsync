<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\DoorLocks\DoorLockBridge;
use App\Modules\FrontOffice\Application\DoorLocks\DoorLockService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The door lock settings and jobs for the people who set them up, and the three calls of the program beside the lock system (token, no session). */
final readonly class DoorLockController
{
    public function __construct(private DoorLockService $locks, private DoorLockBridge $bridge, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('front-office/pages/door-locks', ['overview' => $this->locks->overview($this->property->current(), $this->actor($request))]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'vendor' => ['required', 'string', 'max:20'], 'key_count' => ['required', 'integer', 'min:1', 'max:6'], 'lock_version' => ['nullable', 'integer', 'min:0']]);

        return $this->json(['overview' => $this->locks->saveSettings($this->property->current(), $this->actor($request), (bool) $data['enabled'], $data['vendor'], (int) $data['key_count'], isset($data['lock_version']) ? (int) $data['lock_version'] : null)]);
    }

    public function token(Request $request): JsonResponse
    {
        return $this->json($this->locks->newToken($this->property->current(), $this->actor($request)), 201);
    }

    public function retry(Request $request, string $id): JsonResponse
    {
        $this->locks->retry($this->property->current(), $this->actor($request), $id);

        return $this->json(['ok' => true]);
    }

    public function settle(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);
        $this->locks->settle($this->property->current(), $this->actor($request), $id, $data['reason']);

        return $this->json(['ok' => true]);
    }

    public function pull(Request $request): JsonResponse
    {
        $property = $this->bridge->authenticate($request->bearerToken());

        if ($property === null) {
            return response()->json(['error' => 'unauthorized'], 401)->header('Cache-Control', 'no-store');
        }

        $data = $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:50']]);

        return $this->json(['jobs' => $this->bridge->pull($property, (int) ($data['limit'] ?? 20))]);
    }

    public function report(Request $request, string $id): JsonResponse
    {
        $property = $this->bridge->authenticate($request->bearerToken());

        if ($property === null) {
            return response()->json(['error' => 'unauthorized'], 401)->header('Cache-Control', 'no-store');
        }

        $data = $request->validate(['outcome' => ['required', 'string', 'max:10'], 'message' => ['nullable', 'string', 'max:200']]);
        $this->bridge->report($property, $id, $data['outcome'], $data['message'] ?? null);

        return $this->json(['ok' => true]);
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
