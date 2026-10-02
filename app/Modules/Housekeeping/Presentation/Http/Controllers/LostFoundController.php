<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Presentation\Http\Controllers;

use App\Modules\Housekeeping\Application\LostFoundService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Lost and found screens and actions. Every rule and permission lives in `LostFoundService`. */
final readonly class LostFoundController
{
    public function __construct(private LostFoundService $items, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['status' => ['nullable', 'string', 'max:8']]);

        return Inertia::render('housekeeping/pages/lost-found', ['overview' => $this->items->overview($this->property->current(), $this->actor($request), $data['status'] ?? null), 'status' => $data['status'] ?? '']);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:200'], 'room_id' => ['nullable', 'string', 'size:26'], 'place' => ['nullable', 'string', 'max:80'], 'stored_at' => ['required', 'string', 'max:80'],
            'photo' => ['nullable', 'file', 'max:5120'],
        ]);
        $upload = $request->file('photo');
        $item = $this->items->record($this->property->current(), $this->actor($request), $data['description'], $data['room_id'] ?? null, $data['place'] ?? null, $data['stored_at'], $upload === null ? null : (string) $upload->get(), $upload?->getClientOriginalName());

        return $this->json(['item' => $item], 201);
    }

    public function returned(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['returned_to' => ['required', 'string', 'max:100'], 'note' => ['nullable', 'string', 'max:300'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['item' => $this->items->markReturned($this->property->current(), $this->actor($request), $id, $data['returned_to'], $data['note'] ?? null, (int) $data['lock_version'])]);
    }

    public function disposed(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:300'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['item' => $this->items->markDisposed($this->property->current(), $this->actor($request), $id, $data['reason'], (int) $data['lock_version'])]);
    }

    public function photo(Request $request, string $id): HttpResponse
    {
        $content = $this->items->photo($this->property->current(), $this->actor($request), $id);

        return response($content->contents, 200, [
            'Content-Type' => $content->file->mimeType, 'Content-Disposition' => 'inline; filename="found-item"',
            'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
        ]);
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
