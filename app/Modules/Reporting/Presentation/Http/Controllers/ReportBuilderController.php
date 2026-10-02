<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Presentation\Http\Controllers;

use App\Modules\Reporting\Application\ReportBuilderService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/** The simple report builder. Every rule and permission lives in `ReportBuilderService`. */
final readonly class ReportBuilderController
{
    public function __construct(private ReportBuilderService $builder, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('reporting/pages/builder', ['catalogue' => $this->builder->catalogue($this->property->current(), $this->actor($request))]);
    }

    public function run(Request $request): JsonResponse
    {
        $d = $this->input($request);

        return response()->json(['report' => $this->builder->run($this->property->current(), $this->actor($request), $d['dataset'], $d['columns'], $d['filters'], $d['from'], $d['to'], $d['sort'], $d['direction'])])->header('Cache-Control', 'no-store');
    }

    public function export(Request $request): HttpResponse
    {
        $d = $this->input($request);
        $file = $this->builder->export($this->property->current(), $this->actor($request), $d['dataset'], $d['columns'], $d['filters'], $d['from'], $d['to'], $d['sort'], $d['direction']);

        return response($file['contents'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.preg_replace('/[^A-Za-z0-9._-]/', '_', $file['filename']).'"',
            'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array{dataset: string, columns: list<string>, filters: array<string, string>, from: string|null, to: string|null, sort: string|null, direction: string} */
    private function input(Request $request): array
    {
        $data = $request->validate([
            'dataset' => ['required', 'string', 'max:20'], 'columns' => ['required', 'array', 'min:1', 'max:20'], 'columns.*' => ['string', 'max:30'], 'filters' => ['nullable', 'array', 'max:6'], 'filters.*' => ['nullable', 'string', 'max:40'],
            'from' => ['nullable', 'string', 'size:10'], 'to' => ['nullable', 'string', 'size:10'], 'sort' => ['nullable', 'string', 'max:30'], 'direction' => ['nullable', 'string', 'max:4'],
        ]);

        return ['dataset' => $data['dataset'], 'columns' => array_values($data['columns']), 'filters' => $data['filters'] ?? [], 'from' => $data['from'] ?? null, 'to' => $data['to'] ?? null, 'sort' => $data['sort'] ?? null, 'direction' => $data['direction'] ?? 'asc'];
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
