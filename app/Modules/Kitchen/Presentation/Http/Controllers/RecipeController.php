<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Presentation\Http\Controllers;

use App\Modules\Kitchen\Application\RecipeService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The recipes of the dishes. Every rule and permission lives in the application service. */
final readonly class RecipeController
{
    public function __construct(private RecipeService $recipes, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $property = $this->property->current();
        $actor = $this->actor($request);

        return Inertia::render('kitchen/pages/recipes', ['overview' => $this->recipes->overview($property, $actor), 'consumed' => $this->recipes->consumptions($property, $actor)]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->json($this->recipes->show($this->property->current(), $this->actor($request), $id));
    }

    public function save(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'effective_from' => ['required', 'date_format:Y-m-d'], 'yield_portions' => ['required', 'integer', 'min:1', 'max:1000'], 'reason' => ['required', 'string', 'max:200'],
            'lines' => ['required', 'array', 'min:1', 'max:40'], 'lines.*.item_id' => ['required', 'string', 'size:26'], 'lines.*.unit' => ['required', 'string', 'max:12'],
            'lines.*.quantity_milli' => ['required', 'integer', 'min:1', 'max:100000000'], 'lines.*.waste_bp' => ['required', 'integer', 'min:0', 'max:5000'],
        ]);

        return $this->json($this->recipes->save($this->property->current(), $this->actor($request), $id, $data['effective_from'], (int) $data['yield_portions'], array_values(array_map(static fn (array $l): array => [
            'item_id' => (string) $l['item_id'], 'unit' => (string) $l['unit'], 'quantity_milli' => (int) $l['quantity_milli'], 'waste_bp' => (int) $l['waste_bp'],
        ], $data['lines'])), $data['reason']), 201);
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
