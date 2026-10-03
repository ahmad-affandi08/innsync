<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Presentation\Http\Controllers;

use App\Modules\GuestExperience\Application\PrivacyNoticeService;
use App\Modules\GuestExperience\Application\SelfCheckInLinkService;
use App\Modules\GuestExperience\Application\SelfCheckInQueueService;
use App\Shared\Application\Files\FileContent;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/** The receptionist's side of the self check-in: send links, the code at the lobby, what guests sent, the privacy notice. Every rule and permission lives in the services. */
final readonly class SelfCheckInStaffController
{
    public function __construct(private SelfCheckInLinkService $links, private SelfCheckInQueueService $queue, private PrivacyNoticeService $notices, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $property = $this->property->current();
        $actor = $this->actor($request);

        return Inertia::render('guest/pages/checkins', ['overview' => $this->links->overview($property, $actor), 'queue' => $this->queue->queue($property, $actor), 'notice' => $this->notices->overview($property, $actor)]);
    }

    public function issue(Request $request): JsonResponse
    {
        $data = $request->validate(['reservation_id' => ['required', 'string', 'size:26']]);

        return $this->json($this->links->issue($this->property->current(), $this->actor($request), $data['reservation_id']), 201);
    }

    public function revoke(Request $request, string $id): JsonResponse
    {
        $this->links->revoke($this->property->current(), $this->actor($request), $id);

        return $this->json(['revoked' => true]);
    }

    public function lobby(Request $request): JsonResponse
    {
        $data = $request->validate(['renew' => ['nullable', 'boolean']]);

        return $this->json($this->links->lobby($this->property->current(), $this->actor($request), (bool) ($data['renew'] ?? false)), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->json(['checkin' => $this->queue->detail($this->property->current(), $this->actor($request), $id)]);
    }

    public function photo(Request $request, string $id): HttpResponse
    {
        return $this->file($this->queue->photo($this->property->current(), $this->actor($request), $id));
    }

    public function signature(Request $request, string $id): HttpResponse
    {
        return $this->file($this->queue->signature($this->property->current(), $this->actor($request), $id));
    }

    public function verify(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0'], 'room_id' => ['required', 'string', 'size:26'], 'key_note' => ['nullable', 'string', 'max:400'], 'deposit_received_minor' => ['nullable', 'integer', 'min:0']]);

        return $this->json(['checkin' => $this->queue->verify($this->property->current(), $this->actor($request), $id, (int) $data['lock_version'], $data['room_id'], $data['key_note'] ?? null, (int) ($data['deposit_received_minor'] ?? 0))]);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:400']]);

        return $this->json(['checkin' => $this->queue->reject($this->property->current(), $this->actor($request), $id, (int) $data['lock_version'], $data['reason'])]);
    }

    public function defineNotice(Request $request): JsonResponse
    {
        $data = $request->validate(['body_id' => ['required', 'string', 'max:5000'], 'body_en' => ['required', 'string', 'max:5000'], 'reason' => ['required', 'string', 'max:400']]);

        return $this->json(['notice' => $this->notices->define($this->property->current(), $this->actor($request), $data['body_id'], $data['body_en'], $data['reason'])], 201);
    }

    /** @param array<string, mixed> $body */
    private function json(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status)->header('Cache-Control', 'no-store');
    }

    private function file(FileContent $content): HttpResponse
    {
        return response($content->contents, 200, ['Content-Type' => $content->file->mimeType, 'Content-Disposition' => 'inline; filename="document"', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
