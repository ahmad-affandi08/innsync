<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Presentation\Http\Controllers;

use App\Modules\Maintenance\Application\DutyRunService;
use App\Modules\Maintenance\Application\DutyService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The routine duties of engineering and their runs. Every rule and permission lives in the application services. */
final readonly class DutyController
{
    public function __construct(private DutyService $duties, private DutyRunService $runs, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $property = $this->property->current();
        $actor = $this->actor($request);
        $overview = $this->runs->overview($property, $actor, $data['from'] ?? null, $data['to'] ?? null);

        return Inertia::render('maintenance/pages/duties', [
            'overview' => $overview, 'duties' => $overview['may']['manage'] ? $this->duties->list($property, $actor) : [], 'choices' => $overview['may']['manage'] ? $this->duties->choices($property) : null,
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->json($this->runs->show($this->property->current(), $this->actor($request), $id));
    }

    public function check(Request $request, string $id, string $step): JsonResponse
    {
        $data = $request->validate(['result' => ['required', 'string', 'max:7'], 'note' => ['nullable', 'string', 'max:200']]);

        return $this->json($this->runs->check($this->property->current(), $this->actor($request), $id, $step, $data['result'], $data['note'] ?? null));
    }

    public function complete(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:300'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json($this->runs->complete($this->property->current(), $this->actor($request), $id, $data['note'] ?? null, (int) $data['lock_version']));
    }

    public function create(Request $request): JsonResponse
    {
        $d = $this->validated($request);

        return $this->json($this->duties->create($this->property->current(), $this->actor($request), $d['title'], $d['frequency'], $d['shift'], $d['weekday'], $d['month_day'], $d['asset_id'], $d['area'], $d['category'], $d['steps']), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $d = $this->validated($request);
        $lock = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json($this->duties->update($this->property->current(), $this->actor($request), $id, $d['title'], $d['frequency'], $d['shift'], $d['weekday'], $d['month_day'], $d['asset_id'], $d['area'], $d['category'], $d['steps'], (int) $lock['lock_version']));
    }

    public function active(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json($this->duties->setActive($this->property->current(), $this->actor($request), $id, (bool) $data['active'], (int) $data['lock_version']));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:80'], 'frequency' => ['required', 'string', 'max:8'], 'shift' => ['required', 'string', 'max:5'], 'weekday' => ['nullable', 'integer', 'min:1', 'max:7'], 'month_day' => ['nullable', 'integer', 'min:1', 'max:28'],
            'asset_id' => ['nullable', 'string', 'size:26'], 'area' => ['nullable', 'string', 'max:80'], 'category' => ['required', 'string', 'max:12'], 'steps' => ['required', 'array', 'min:1', 'max:30'], 'steps.*' => ['nullable', 'string', 'max:200'],
        ]);

        return [...$data, 'weekday' => isset($data['weekday']) ? (int) $data['weekday'] : null, 'month_day' => isset($data['month_day']) ? (int) $data['month_day'] : null, 'asset_id' => $data['asset_id'] ?? null, 'area' => $data['area'] ?? null, 'steps' => array_values($data['steps'])];
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
