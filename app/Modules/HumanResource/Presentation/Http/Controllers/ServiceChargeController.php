<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Presentation\Http\Controllers;

use App\Modules\HumanResource\Application\ServiceChargeService;
use App\Modules\HumanResource\Application\ServiceChargeSettingsService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The service charge: how it is shared and the distribution of each month. Every rule and permission lives in the services. */
final readonly class ServiceChargeController
{
    public function __construct(private ServiceChargeService $distributions, private ServiceChargeSettingsService $settings, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['distribution' => ['nullable', 'string', 'size:26']]);

        return Inertia::render('hr/pages/service-charge', ['overview' => $this->distributions->overview($this->property->current(), $this->actor($request), $data['distribution'] ?? null)]);
    }

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate(['period' => ['required', 'string', 'size:7']]);

        return response()->json($this->distributions->create($this->property->current(), $this->actor($request), $data['period']), 201);
    }

    public function calculate(Request $request, string $id): JsonResponse
    {
        return response()->json($this->distributions->calculate($this->property->current(), $this->actor($request), $id, $this->version($request)));
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        return response()->json($this->distributions->approve($this->property->current(), $this->actor($request), $id, $this->version($request)));
    }

    public function discard(Request $request, string $id): JsonResponse
    {
        return response()->json($this->distributions->discard($this->property->current(), $this->actor($request), $id));
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'staff_share_bp' => ['required', 'integer', 'min:0'], 'reserve_bp' => ['required', 'integer', 'min:0'], 'default_points_x100' => ['required', 'integer', 'min:1'],
            'points' => ['present', 'array', 'max:100'], 'points.*.position' => ['required', 'string', 'max:80'], 'points.*.points_x100' => ['required', 'integer', 'min:1'], 'lock_version' => ['nullable', 'integer', 'min:0'],
        ]);

        return response()->json($this->settings->save($this->property->current(), $this->actor($request), (int) $data['staff_share_bp'], (int) $data['reserve_bp'], (int) $data['default_points_x100'], array_map(static fn (array $p): array => ['position' => $p['position'], 'points_x100' => (int) $p['points_x100']], $data['points']), isset($data['lock_version']) ? (int) $data['lock_version'] : null));
    }

    private function version(Request $request): int
    {
        return (int) $request->validate(['lock_version' => ['required', 'integer', 'min:0']])['lock_version'];
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
