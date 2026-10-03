<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Presentation\Http\Controllers;

use App\Modules\Maintenance\Application\PartsService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The spare parts of a work order and the purchase requests started from it. Every rule and permission lives in the application service. */
final readonly class PartsController
{
    public function __construct(private PartsService $parts, private PropertyContext $property) {}

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->json($this->parts->panel($this->property->current(), $this->actor($request), $id));
    }

    public function use(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'string', 'size:26'], 'location_id' => ['required', 'string', 'size:26'], 'unit' => ['required', 'string', 'max:12'], 'quantity_milli' => ['required', 'integer', 'min:1', 'max:100000000'], 'note' => ['nullable', 'string', 'max:200'],
        ]);

        return $this->json($this->parts->use($this->property->current(), $this->actor($request), $id, $data['item_id'], $data['location_id'], $data['unit'], (int) $data['quantity_milli'], $data['note'] ?? null), 201);
    }

    public function request(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'urgency' => ['required', 'string', 'max:8'], 'reason' => ['required', 'string', 'max:160'], 'needed_by' => ['required', 'date_format:Y-m-d'],
            'lines' => ['required', 'array', 'min:1', 'max:20'], 'lines.*.item_id' => ['required', 'string', 'size:26'], 'lines.*.unit' => ['required', 'string', 'max:12'], 'lines.*.quantity_milli' => ['required', 'integer', 'min:1', 'max:100000000'], 'lines.*.note' => ['nullable', 'string', 'max:200'],
        ]);

        return $this->json($this->parts->request($this->property->current(), $this->actor($request), $id, $data['urgency'], $data['reason'], $data['needed_by'], array_map(static fn (array $l): array => [
            'item_id' => $l['item_id'], 'unit' => $l['unit'], 'quantity_milli' => (int) $l['quantity_milli'], 'note' => $l['note'] ?? null,
        ], $data['lines'])), 201);
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
