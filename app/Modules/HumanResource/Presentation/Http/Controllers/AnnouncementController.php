<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Presentation\Http\Controllers;

use App\Modules\HumanResource\Application\AnnouncementService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/** The notice board and the policies. Every rule and permission lives in `AnnouncementService`. */
final readonly class AnnouncementController
{
    public function __construct(private AnnouncementService $announcements, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('hr/pages/announcements', ['overview' => $this->announcements->overview($this->property->current(), $this->actor($request))]);
    }

    public function publish(Request $request): JsonResponse
    {
        $data = $request->validate(['kind' => ['required', 'string', 'max:12'], 'title' => ['required', 'string', 'max:120'], 'body' => ['required', 'string', 'max:4000'], 'audience' => ['required', 'string', 'max:16'], 'requires_ack' => ['required', 'boolean'], 'expires_on' => ['nullable', 'date_format:Y-m-d'], 'document' => ['nullable', 'file', 'max:5120']]);
        $file = $request->file('document');

        return response()->json($this->announcements->publish($this->property->current(), $this->actor($request), $data['kind'], $data['title'], $data['body'], $data['audience'], (bool) $data['requires_ack'], $data['expires_on'] ?? null, $file?->get() === null ? null : (string) $file->get(), $file?->getClientOriginalName()), 201);
    }

    public function withdraw(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return response()->json($this->announcements->withdraw($this->property->current(), $this->actor($request), $id, $data['reason'], (int) $data['lock_version']));
    }

    public function read(Request $request, string $id): JsonResponse
    {
        return response()->json($this->announcements->read($this->property->current(), $this->actor($request), $id));
    }

    public function acknowledge(Request $request, string $id): JsonResponse
    {
        return response()->json($this->announcements->acknowledge($this->property->current(), $this->actor($request), $id));
    }

    public function document(Request $request, string $id): HttpResponse
    {
        $content = $this->announcements->document($this->property->current(), $this->actor($request), $id);

        return response($content->contents, 200, ['Content-Type' => $content->file->mimeType, 'Content-Disposition' => 'inline; filename="document"', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
