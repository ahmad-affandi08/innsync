<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\Routine\SopService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The front desk checklists: what is to be done now, the templates and the completion figures. Every rule lives in `SopService`. */
final readonly class ChecklistController
{
    public function __construct(private SopService $sop, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('front-office/pages/checklists', ['board' => $this->sop->board($this->property->current(), $this->actor($request))]);
    }

    public function templates(Request $request): Response
    {
        return Inertia::render('front-office/pages/checklist-templates', ['catalogue' => $this->sop->templates($this->property->current(), $this->actor($request))]);
    }

    public function performance(Request $request): Response
    {
        $data = $request->validate(['from' => ['nullable', 'string', 'size:10'], 'to' => ['nullable', 'string', 'size:10']]);

        return Inertia::render('front-office/pages/checklist-performance', ['report' => $this->sop->performance($this->property->current(), $this->actor($request), $data['from'] ?? null, $data['to'] ?? null)]);
    }

    public function define(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'], 'frequency' => ['required', 'string', 'max:8'], 'items' => ['required', 'array', 'min:1', 'max:40'], 'items.*' => ['required', 'string', 'max:160'], 'active' => ['required', 'boolean'],
        ]);

        return $this->json(['template' => $this->sop->define($this->property->current(), $this->actor($request), $data['name'], $data['frequency'], array_values($data['items']), (bool) $data['active'])], 201);
    }

    public function complete(Request $request, string $template, string $item): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:300']]);

        return $this->json(['checklist' => $this->sop->complete($this->property->current(), $this->actor($request), $template, $item, $data['note'] ?? null)]);
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
