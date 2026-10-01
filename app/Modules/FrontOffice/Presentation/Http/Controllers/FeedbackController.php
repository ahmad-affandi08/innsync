<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\Feedback\FeedbackService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Guest comments and complaints: the list, one item with its history, and the actions. Every rule lives in `FeedbackService`. */
final readonly class FeedbackController
{
    public function __construct(private FeedbackService $feedback, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['kind' => ['nullable', 'string', 'max:12'], 'status' => ['nullable', 'string', 'max:12'], 'severity' => ['nullable', 'string', 'max:8'], 'owner' => ['nullable', 'string', 'size:26']]);
        $status = array_key_exists('status', $data) ? $data['status'] : 'active';

        return Inertia::render('front-office/pages/feedback', [
            'queue' => $this->feedback->queue($this->property->current(), $this->actor($request), $data['kind'] ?? null, $status, $data['severity'] ?? null, $data['owner'] ?? null),
            'filters' => ['kind' => $data['kind'] ?? '', 'status' => $status ?? '', 'severity' => $data['severity'] ?? '', 'owner' => $data['owner'] ?? ''],
        ]);
    }

    public function show(Request $request, string $id): Response
    {
        return Inertia::render('front-office/pages/feedback-item', ['detail' => $this->feedback->view($this->property->current(), $this->actor($request), $id)]);
    }

    public function record(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'string', 'max:12'], 'severity' => ['nullable', 'string', 'max:8'], 'channel' => ['required', 'string', 'max:10'], 'reservation_id' => ['nullable', 'string', 'size:26'],
            'guest_name' => ['nullable', 'string', 'max:150'], 'summary' => ['required', 'string', 'max:150'], 'detail' => ['nullable', 'string', 'max:1000'], 'follow_up_by' => ['nullable', 'string', 'size:10'],
        ]);
        $key = (string) $request->header('Idempotency-Key');
        $item = $this->feedback->record($this->property->current(), $this->actor($request), $data['kind'], $data['severity'] ?? null, $data['channel'], $data['reservation_id'] ?? null, $data['guest_name'] ?? null, $data['summary'], $data['detail'] ?? null, $data['follow_up_by'] ?? null, $key === '' ? null : 'fb:'.$key);

        return $this->json(['item' => $item], 201);
    }

    public function assign(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['owner_id' => ['required', 'string', 'size:26'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['item' => $this->feedback->assign($this->property->current(), $this->actor($request), $id, $data['owner_id'], (int) $data['lock_version'])]);
    }

    public function note(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:500']]);

        return $this->json(['item' => $this->feedback->note($this->property->current(), $this->actor($request), $id, $data['text'])]);
    }

    public function start(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['item' => $this->feedback->start($this->property->current(), $this->actor($request), $id, (int) $data['lock_version'])]);
    }

    public function resolve(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['resolution' => ['required', 'string', 'max:500'], 'evidence_ref' => ['nullable', 'string', 'max:120'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['item' => $this->feedback->resolve($this->property->current(), $this->actor($request), $id, $data['resolution'], $data['evidence_ref'] ?? null, (int) $data['lock_version'])]);
    }

    public function close(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['item' => $this->feedback->close($this->property->current(), $this->actor($request), $id, (int) $data['lock_version'])]);
    }

    public function reopen(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:300'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['item' => $this->feedback->reopen($this->property->current(), $this->actor($request), $id, $data['note'], (int) $data['lock_version'])]);
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
