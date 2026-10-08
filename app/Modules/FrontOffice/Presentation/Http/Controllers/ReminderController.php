<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\Reminders\ReminderService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** What the front desk must remember. */
final readonly class ReminderController
{
    public function __construct(private ReminderService $reminders, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('front-office/pages/reminders', ['reminders' => $this->reminders->overview($this->property->current(), $this->actor($request))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['due_on' => ['required', 'string', 'size:10'], 'due_time' => ['nullable', 'string', 'max:5'], 'text' => ['required', 'string', 'max:300'], 'reservation_id' => ['nullable', 'string', 'size:26'], 'room_id' => ['nullable', 'string', 'size:26']]);
        $id = $this->reminders->add($this->property->current(), $this->actor($request), $data['due_on'], $data['due_time'] ?? null, $data['text'], $data['reservation_id'] ?? null, $data['room_id'] ?? null);

        return response()->json(['id' => $id], 201)->header('Cache-Control', 'no-store');
    }

    public function done(Request $request, string $id): JsonResponse
    {
        $this->reminders->done($this->property->current(), $this->actor($request), $id);

        return response()->json(['ok' => true])->header('Cache-Control', 'no-store');
    }

    public function reopen(Request $request, string $id): JsonResponse
    {
        $this->reminders->reopen($this->property->current(), $this->actor($request), $id);

        return response()->json(['ok' => true])->header('Cache-Control', 'no-store');
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
