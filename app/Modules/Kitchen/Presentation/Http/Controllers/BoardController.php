<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Presentation\Http\Controllers;

use App\Modules\Kitchen\Application\BoardService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The screens of the kitchen and the bar. Every rule and permission lives in the application service. */
final readonly class BoardController
{
    public function __construct(private BoardService $board, private PropertyContext $property) {}

    public function show(Request $request): Response
    {
        $data = $request->validate(['station' => ['nullable', 'string', 'max:8']]);
        $property = $this->property->current();
        $actor = $this->actor($request);

        return Inertia::render('kitchen/pages/board', [
            'board' => $this->board->board($property, $actor, $data['station'] ?? 'kitchen'),
            'sold_out' => $this->board->soldOut($property, $actor),
            'settings' => $this->board->settings($property, $actor),
        ]);
    }

    /** The tickets alone, for the screen to reload itself. */
    public function tickets(Request $request): JsonResponse
    {
        $data = $request->validate(['station' => ['required', 'string', 'max:8']]);

        return $this->json(['board' => $this->board->board($this->property->current(), $this->actor($request), $data['station'])]);
    }

    public function advance(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'string', 'max:8'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['ticket' => $this->board->advance($this->property->current(), $this->actor($request), $id, $data['action'], (int) $data['lock_version'])]);
    }

    public function availability(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['available' => ['required', 'boolean']]);

        return $this->json($this->board->setAvailability($this->property->current(), $this->actor($request), $id, (bool) $data['available']));
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['late_after_minutes' => ['required', 'integer', 'min:1', 'max:240'], 'reason' => ['required', 'string', 'max:200'], 'lock_version' => ['nullable', 'integer', 'min:0']]);

        return $this->json(['settings' => $this->board->saveSettings($this->property->current(), $this->actor($request), (int) $data['late_after_minutes'], isset($data['lock_version']) ? (int) $data['lock_version'] : null, $data['reason'])]);
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
