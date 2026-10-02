<?php

declare(strict_types=1);

namespace App\Modules\Laundry\Presentation\Http\Controllers;

use App\Modules\Laundry\Application\ClaimService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Claims for damaged or lost guest laundry. Every rule and permission lives in `ClaimService`. */
final readonly class ClaimController
{
    public function __construct(private ClaimService $claims, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['status' => ['nullable', 'string', 'max:8'], 'order' => ['nullable', 'string', 'size:26']]);

        return Inertia::render('laundry/pages/claims', ['overview' => $this->claims->overview($this->property->current(), $this->actor($request), $data['status'] ?? null), 'status' => $data['status'] ?? '', 'order' => $data['order'] ?? null]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'string', 'size:26'], 'line_id' => ['nullable', 'string', 'size:26'], 'pieces' => ['required', 'integer', 'min:1', 'max:999'], 'kind' => ['required', 'string', 'max:6'],
            'description' => ['required', 'string', 'max:300'], 'claimed_minor' => ['required', 'integer', 'min:1'], 'photo' => ['nullable', 'file', 'max:5120'],
        ]);
        $upload = $request->file('photo');
        $claim = $this->claims->record($this->property->current(), $this->actor($request), $data['order_id'], $data['line_id'] ?? null, (int) $data['pieces'], $data['kind'], $data['description'], (int) $data['claimed_minor'], $upload === null ? null : (string) $upload->get(), $upload?->getClientOriginalName());

        return $this->json(['claim' => $claim], 201);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['approved_minor' => ['required', 'integer', 'min:1'], 'note' => ['nullable', 'string', 'max:300'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['claim' => $this->claims->approve($this->property->current(), $this->actor($request), $id, (int) $data['approved_minor'], $data['note'] ?? null, (int) $data['lock_version'])]);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:300'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['claim' => $this->claims->reject($this->property->current(), $this->actor($request), $id, $data['note'], (int) $data['lock_version'])]);
    }

    public function cap(Request $request): JsonResponse
    {
        $data = $request->validate(['cap_multiple' => ['required', 'integer', 'min:0', 'max:100'], 'lock_version' => ['nullable', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:300']]);

        return $this->json(['settings' => $this->claims->saveCap($this->property->current(), $this->actor($request), (int) $data['cap_multiple'], isset($data['lock_version']) ? (int) $data['lock_version'] : null, $data['reason'])]);
    }

    public function photo(Request $request, string $id): HttpResponse
    {
        $content = $this->claims->photo($this->property->current(), $this->actor($request), $id);

        return response($content->contents, 200, [
            'Content-Type' => $content->file->mimeType, 'Content-Disposition' => 'inline; filename="laundry-claim"',
            'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
        ]);
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
