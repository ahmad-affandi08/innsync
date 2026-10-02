<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Presentation\Http\Controllers;

use App\Modules\Reporting\Application\ExportJobService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Report exports built in the background, their status and download. Every rule and permission lives in `ExportJobService`. */
final readonly class ExportJobController
{
    public function __construct(private ExportJobService $exports, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('reporting/pages/exports', ['overview' => $this->exports->overview($this->property->current(), $this->actor($request))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'report' => ['required', 'string', 'max:20'], 'purpose' => ['nullable', 'string', 'max:300'], 'params' => ['nullable', 'array'],
            'params.preset' => ['nullable', 'string', 'max:20'], 'params.from' => ['nullable', 'string', 'size:10'], 'params.to' => ['nullable', 'string', 'size:10'], 'params.date' => ['nullable', 'string', 'size:10'],
            'params.nationality' => ['nullable', 'string', 'max:3'], 'params.by' => ['nullable', 'string', 'max:5'], 'params.year' => ['nullable', 'integer', 'min:2000', 'max:2100'], 'params.kind' => ['nullable', 'string', 'max:10'],
        ]);

        return response()->json(['job' => $this->exports->request($this->property->current(), $this->actor($request), $data['report'], $data['params'] ?? [], $data['purpose'] ?? null)], 201)->header('Cache-Control', 'no-store');
    }

    public function seen(Request $request): JsonResponse
    {
        $this->exports->markSeen($this->property->current(), $this->actor($request));

        return response()->json(['ok' => true])->header('Cache-Control', 'no-store');
    }

    public function download(Request $request, string $id): HttpResponse
    {
        $result = $this->exports->download($this->property->current(), $this->actor($request), $id);
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $result['filename']);

        return response($result['file']->contents, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
