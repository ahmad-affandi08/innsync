<?php

declare(strict_types=1);

namespace App\Modules\Routines\Presentation\Http\Controllers;

use App\Modules\Routines\Application\RoutineSopService;
use App\Modules\Routines\Application\TemperatureService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The checklists and temperature logs of a department. The department comes from the route; every rule and permission lives in the services. */
final readonly class RoutineController
{
    public function __construct(private RoutineSopService $sop, private TemperatureService $temperatures, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('routines/pages/checklists', ['board' => $this->sop->board($this->property->current(), $this->department($request), $this->actor($request)), 'department' => $this->department($request)]);
    }

    public function templates(Request $request): Response
    {
        return Inertia::render('routines/pages/templates', ['overview' => $this->sop->templates($this->property->current(), $this->department($request), $this->actor($request)), 'department' => $this->department($request)]);
    }

    public function define(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'frequency' => ['required', 'string', 'max:8'], 'items' => ['required', 'array', 'min:1', 'max:40'], 'items.*' => ['required', 'string', 'max:160'], 'active' => ['required', 'boolean']]);

        return $this->json(['template' => $this->sop->define($this->property->current(), $this->department($request), $this->actor($request), $data['name'], $data['frequency'], array_values($data['items']), (bool) $data['active'])], 201);
    }

    public function complete(Request $request, string $template, string $item): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:300']]);

        return $this->json(['checklist' => $this->sop->complete($this->property->current(), $this->department($request), $this->actor($request), $template, $item, $data['note'] ?? null)]);
    }

    public function performance(Request $request): Response
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);

        return Inertia::render('routines/pages/performance', ['report' => $this->sop->performance($this->property->current(), $this->department($request), $this->actor($request), $data['from'] ?? null, $data['to'] ?? null), 'department' => $this->department($request)]);
    }

    public function temperaturesPage(Request $request): Response
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);

        return Inertia::render('routines/pages/temperatures', ['overview' => $this->temperatures->overview($this->property->current(), $this->department($request), $this->actor($request), $data['from'] ?? null, $data['to'] ?? null), 'department' => $this->department($request)]);
    }

    public function createPoint(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60'], 'min_tenth' => ['required', 'integer', 'between:-600,1500'], 'max_tenth' => ['required', 'integer', 'between:-600,1500']]);

        return $this->json(['point' => $this->temperatures->definePoint($this->property->current(), $this->department($request), $this->actor($request), $data['name'], (int) $data['min_tenth'], (int) $data['max_tenth'])], 201);
    }

    public function updatePoint(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60'], 'min_tenth' => ['required', 'integer', 'between:-600,1500'], 'max_tenth' => ['required', 'integer', 'between:-600,1500'], 'active' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['point' => $this->temperatures->updatePoint($this->property->current(), $this->department($request), $this->actor($request), $id, $data['name'], (int) $data['min_tenth'], (int) $data['max_tenth'], (bool) $data['active'], (int) $data['lock_version'])]);
    }

    public function record(Request $request): JsonResponse
    {
        $data = $request->validate(['point_id' => ['required', 'string', 'size:26'], 'value_tenth' => ['required', 'integer', 'between:-600,1500'], 'action_taken' => ['nullable', 'string', 'max:200']]);

        return $this->json(['reading' => $this->temperatures->record($this->property->current(), $this->department($request), $this->actor($request), $data['point_id'], (int) $data['value_tenth'], $data['action_taken'] ?? null)], 201);
    }

    /** @param array<string, mixed> $body */
    private function json(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status)->header('Cache-Control', 'no-store');
    }

    /** The department is a default of the route, so it is read by name and not by position. */
    private function department(Request $request): string
    {
        return (string) $request->route('department');
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
