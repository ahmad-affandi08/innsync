<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Presentation\Http\Controllers;

use App\Modules\Housekeeping\Application\ChecklistService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Housekeeping checklists: what is to be done now, the templates and the completion figures. Every rule lives in `ChecklistService`. */
final readonly class ChecklistController
{
    public function __construct(private ChecklistService $checklists, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('housekeeping/pages/checklists', ['board' => $this->checklists->board($this->property->current(), $this->actor($request))]);
    }

    public function templates(Request $request): Response
    {
        return Inertia::render('housekeeping/pages/checklist-templates', ['catalogue' => $this->checklists->templates($this->property->current(), $this->actor($request))]);
    }

    public function performance(Request $request): Response
    {
        $data = $request->validate(['from' => ['nullable', 'string', 'size:10'], 'to' => ['nullable', 'string', 'size:10']]);

        return Inertia::render('housekeeping/pages/checklist-performance', ['report' => $this->checklists->performance($this->property->current(), $this->actor($request), $data['from'] ?? null, $data['to'] ?? null)]);
    }

    public function define(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'], 'frequency' => ['required', 'string', 'max:8'], 'scope' => ['required', 'string', 'max:4'],
            'areas' => ['nullable', 'array', 'max:30'], 'areas.*' => ['required', 'string', 'max:60'],
            'items' => ['required', 'array', 'min:1', 'max:40'], 'items.*' => ['required', 'string', 'max:160'], 'active' => ['required', 'boolean'],
        ]);

        return $this->json(['template' => $this->checklists->define($this->property->current(), $this->actor($request), $data['name'], $data['frequency'], $data['scope'], array_values($data['areas'] ?? []), array_values($data['items']), (bool) $data['active'])], 201);
    }

    public function detail(Request $request, string $template): JsonResponse
    {
        $data = $request->validate(['target' => ['required', 'string', 'max:60']]);

        return $this->json(['checklist' => $this->checklists->detail($this->property->current(), $this->actor($request), $template, $data['target'])]);
    }

    public function complete(Request $request, string $template): JsonResponse
    {
        $data = $request->validate(['target' => ['required', 'string', 'max:60'], 'item_id' => ['required', 'string', 'max:20'], 'note' => ['nullable', 'string', 'max:300']]);

        return $this->json(['checklist' => $this->checklists->complete($this->property->current(), $this->actor($request), $template, $data['target'], $data['item_id'], $data['note'] ?? null)]);
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
