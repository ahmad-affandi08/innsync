<?php

declare(strict_types=1);

namespace App\Modules\Finance\Presentation\Http\Controllers;

use App\Modules\Finance\Application\FixedAssetService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The register of fixed assets and their depreciation. Every rule and permission lives in the service. */
final readonly class FixedAssetController
{
    public function __construct(private FixedAssetService $assets, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('finance/pages/assets', ['overview' => $this->assets->overview($this->property->current(), $this->actor($request))]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'code' => ['required', 'string', 'max:20'], 'name' => ['required', 'string', 'max:120'], 'category' => ['required', 'string', 'max:12'], 'acquired_on' => ['required', 'date_format:Y-m-d'],
            'cost_minor' => ['required', 'integer', 'min:1', 'max:90000000000000'], 'salvage_minor' => ['required', 'integer', 'min:0', 'max:90000000000000'], 'life_months' => ['required', 'integer', 'min:1', 'max:600'], 'notes' => ['nullable', 'string', 'max:300'],
        ]);

        return $this->json(['overview' => $this->assets->add($this->property->current(), $this->actor($request), $d['code'], $d['name'], $d['category'], $d['acquired_on'], (int) $d['cost_minor'], (int) $d['salvage_minor'], (int) $d['life_months'], $d['notes'] ?? null)], 201);
    }

    public function run(Request $request): JsonResponse
    {
        $d = $request->validate(['through' => ['required', 'string', 'size:7']]);

        return $this->json(['run' => $this->assets->run($this->property->current(), $this->actor($request), $d['through'])]);
    }

    public function dispose(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['kind' => ['required', 'string', 'max:8'], 'disposed_on' => ['required', 'date_format:Y-m-d'], 'proceeds_minor' => ['required', 'integer', 'min:0', 'max:90000000000000'], 'method' => ['nullable', 'string', 'max:8'], 'reason' => ['required', 'string', 'max:200'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['overview' => $this->assets->dispose($this->property->current(), $this->actor($request), $id, $d['kind'], $d['disposed_on'], (int) $d['proceeds_minor'], $d['method'] ?? null, $d['reason'], (int) $d['lock_version'])]);
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
