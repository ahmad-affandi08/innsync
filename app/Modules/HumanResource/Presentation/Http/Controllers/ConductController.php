<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Presentation\Http\Controllers;

use App\Modules\HumanResource\Application\ConductService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Reprimands, warning letters and awards. Every rule and permission lives in `ConductService`. */
final readonly class ConductController
{
    public function __construct(private ConductService $conduct, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['employee' => ['nullable', 'string', 'size:26']]);

        return Inertia::render('hr/pages/conduct', ['overview' => $this->conduct->overview($this->property->current(), $this->actor($request), $data['employee'] ?? null)]);
    }

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate(['employee_id' => ['required', 'string', 'size:26'], 'kind' => ['required', 'string', 'max:8'], 'issued_on' => ['required', 'date_format:Y-m-d'], 'valid_until' => ['nullable', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:500'], 'letter' => ['nullable', 'file', 'max:3072']]);
        $file = $request->file('letter');

        return response()->json($this->conduct->create($this->property->current(), $this->actor($request), $data['employee_id'], $data['kind'], $data['issued_on'], $data['valid_until'] ?? null, $data['reason'], $file?->get() === null ? null : (string) $file->get(), $file?->getClientOriginalName()), 201);
    }

    public function revoke(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return response()->json($this->conduct->revoke($this->property->current(), $this->actor($request), $id, $data['reason'], (int) $data['lock_version']));
    }

    public function letter(Request $request, string $id): HttpResponse
    {
        $content = $this->conduct->letter($this->property->current(), $this->actor($request), $id);

        return response($content->contents, 200, ['Content-Type' => $content->file->mimeType, 'Content-Disposition' => 'inline; filename="letter"', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
