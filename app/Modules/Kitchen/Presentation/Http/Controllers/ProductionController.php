<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Presentation\Http\Controllers;

use App\Modules\Kitchen\Application\ProductionService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The preparation formulas and the production batches of the kitchen. Every rule and permission lives in `ProductionService`. */
final readonly class ProductionController
{
    public function __construct(private ProductionService $production, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('kitchen/pages/production', ['overview' => $this->production->overview($this->property->current(), $this->actor($request))]);
    }

    public function define(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'], 'name' => ['required', 'string', 'max:80'], 'output_item_id' => ['required', 'string', 'size:26'], 'output_unit' => ['required', 'string', 'max:12'],
            'standard_output_milli' => ['required', 'integer', 'min:1', 'max:100000000000'], 'lines' => ['required', 'array', 'min:1', 'max:30'], 'lines.*.item_id' => ['required', 'string', 'size:26'],
            'lines.*.unit' => ['required', 'string', 'max:12'], 'lines.*.quantity_milli' => ['required', 'integer', 'min:1', 'max:100000000000'],
        ]);

        return $this->json($this->production->defineFormula($this->property->current(), $this->actor($request), $data['code'], $data['name'], $data['output_item_id'], $data['output_unit'], (int) $data['standard_output_milli'], array_values(array_map(
            static fn (array $l): array => ['item_id' => $l['item_id'], 'unit' => $l['unit'], 'quantity_milli' => (int) $l['quantity_milli']],
            $data['lines'],
        ))), 201);
    }

    public function retire(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return $this->json($this->production->retireFormula($this->property->current(), $this->actor($request), $id, $data['reason']));
    }

    public function record(Request $request): JsonResponse
    {
        $data = $request->validate(['formula_id' => ['required', 'string', 'size:26'], 'batches' => ['required', 'integer', 'min:1', 'max:100'], 'actual_output_milli' => ['required', 'integer', 'min:1', 'max:100000000000'], 'expires_on' => ['nullable', 'date_format:Y-m-d'], 'note' => ['nullable', 'string', 'max:200']]);

        return $this->json($this->production->record($this->property->current(), $this->actor($request), $data['formula_id'], (int) $data['batches'], (int) $data['actual_output_milli'], $data['expires_on'] ?? null, $data['note'] ?? null), 201);
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
