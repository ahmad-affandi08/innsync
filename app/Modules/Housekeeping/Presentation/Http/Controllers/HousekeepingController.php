<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Presentation\Http\Controllers;

use App\Modules\Housekeeping\Application\HousekeepingService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Housekeeping screens and actions. Every rule and permission lives in `HousekeepingService`. */
final readonly class HousekeepingController
{
    public function __construct(private HousekeepingService $housekeeping, private PropertyContext $property) {}

    public function board(Request $request): Response
    {
        return Inertia::render('housekeeping/pages/board', ['board' => $this->housekeeping->board($this->property->current(), $this->actor($request))]);
    }

    public function myRooms(Request $request): Response
    {
        return Inertia::render('housekeeping/pages/my-rooms', ['tasks' => $this->housekeeping->myTasks($this->property->current(), $this->actor($request))]);
    }

    public function room(Request $request, string $id): JsonResponse
    {
        return $this->json(['room' => $this->housekeeping->room($this->property->current(), $this->actor($request), $id)]);
    }

    public function requestService(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_id' => ['required', 'string', 'size:26'], 'kind' => ['required', 'string', 'max:10'], 'reason' => ['required', 'string', 'max:300'], 'assigned_to' => ['nullable', 'string', 'size:26'],
        ]);

        return $this->json(['task' => $this->housekeeping->requestService($this->property->current(), $this->actor($request), $data['room_id'], $data['kind'], $data['reason'], $data['assigned_to'] ?? null)], 201);
    }

    public function assign(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['assigned_to' => ['required', 'string', 'size:26'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['task' => $this->housekeeping->assign($this->property->current(), $this->actor($request), $id, $data['assigned_to'], (int) $data['lock_version'])]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:300'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['task' => $this->housekeeping->cancelTask($this->property->current(), $this->actor($request), $id, $data['reason'], (int) $data['lock_version'])]);
    }

    public function start(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['task' => $this->housekeeping->start($this->property->current(), $this->actor($request), $id, (int) $data['lock_version'])]);
    }

    public function finish(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['task' => $this->housekeeping->finish($this->property->current(), $this->actor($request), $id, (int) $data['lock_version'])]);
    }

    public function inspect(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'passed' => ['required', 'boolean'], 'notes' => ['nullable', 'string', 'max:500'],
            'findings' => ['nullable', 'array', 'max:20'], 'findings.*.description' => ['required', 'string', 'max:300'], 'findings.*.mandatory' => ['nullable', 'boolean'],
        ]);

        return $this->json(['inspection' => $this->housekeeping->inspect($this->property->current(), $this->actor($request), $id, (bool) $data['passed'], array_values($data['findings'] ?? []), $data['notes'] ?? null)], 201);
    }

    public function resolveFinding(Request $request, string $id): JsonResponse
    {
        $this->housekeeping->resolveFinding($this->property->current(), $this->actor($request), $id);

        return $this->json(['resolved' => true]);
    }

    public function waiveFinding(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);
        $this->housekeeping->waiveFinding($this->property->current(), $this->actor($request), $id, $data['reason']);

        return $this->json(['waived' => true]);
    }

    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate(['inspection_required' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:300']]);
        $this->housekeeping->setInspectionRequired($this->property->current(), $this->actor($request), (bool) $data['inspection_required'], (int) $data['lock_version'], $data['reason']);

        return $this->json(['saved' => true]);
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
