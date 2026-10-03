<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Presentation\Http\Controllers;

use App\Modules\Maintenance\Application\AssetService;
use App\Modules\Maintenance\Application\PreventiveService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The assets, their meter readings and their preventive plans. Every rule and permission lives in the application services. */
final readonly class AssetController
{
    public function __construct(private AssetService $assets, private PreventiveService $preventive, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $property = $this->property->current();
        $overview = $this->assets->overview($property, $this->actor($request));
        $this->preventive->generate($property);

        return Inertia::render('maintenance/pages/assets', ['overview' => $overview]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->json($this->assets->show($this->property->current(), $this->actor($request), $id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'], 'category' => ['required', 'string', 'max:14'], 'serial' => ['nullable', 'string', 'max:40'], 'room_id' => ['nullable', 'string', 'size:26'], 'area' => ['nullable', 'string', 'max:80'],
            'acquired_on' => ['required', 'date_format:Y-m-d'], 'warranty_until' => ['nullable', 'date_format:Y-m-d'], 'meter_unit' => ['nullable', 'string', 'max:8'], 'notes' => ['nullable', 'string', 'max:300'],
        ]);

        return $this->json($this->assets->create($this->property->current(), $this->actor($request), $data['name'], $data['category'], $data['serial'] ?? null, $data['room_id'] ?? null, $data['area'] ?? null, $data['acquired_on'], $data['warranty_until'] ?? null, $data['meter_unit'] ?? null, $data['notes'] ?? null), 201);
    }

    public function retire(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json($this->assets->retire($this->property->current(), $this->actor($request), $id, $data['reason'], (int) $data['lock_version']));
    }

    public function reading(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reading' => ['required', 'integer', 'min:0', 'max:9000000000']]);

        return $this->json($this->assets->readMeter($this->property->current(), $this->actor($request), $id, (int) $data['reading']), 201);
    }

    public function addPlan(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:300'], 'category' => ['required', 'string', 'max:12'], 'priority' => ['required', 'string', 'max:8'], 'trigger_kind' => ['required', 'string', 'max:8'],
            'interval_value' => ['required', 'integer', 'min:1', 'max:1000000'], 'lead_days' => ['required', 'integer', 'min:0', 'max:60'], 'first_due_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return $this->json($this->assets->addPlan($this->property->current(), $this->actor($request), $id, $data['title'], $data['description'] ?? null, $data['category'], $data['priority'], $data['trigger_kind'], (int) $data['interval_value'], (int) $data['lead_days'], $data['first_due_on'] ?? null), 201);
    }

    public function planActive(Request $request, string $plan): JsonResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json($this->assets->setPlanActive($this->property->current(), $this->actor($request), $plan, (bool) $data['active'], (int) $data['lock_version']));
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
