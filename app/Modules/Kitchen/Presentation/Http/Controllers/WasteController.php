<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Presentation\Http\Controllers;

use App\Modules\Kitchen\Application\WasteService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The waste log of the kitchen. Every rule and permission lives in the application service. */
final readonly class WasteController
{
    public function __construct(private WasteService $waste, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('kitchen/pages/waste', ['overview' => $this->waste->overview($this->property->current(), $this->actor($request))]);
    }

    public function record(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'string', 'max:10'], 'item_id' => ['required', 'string', 'size:26'], 'quantity_milli' => ['nullable', 'integer', 'min:1', 'max:100000000'], 'unit' => ['nullable', 'string', 'max:12'],
            'portions' => ['nullable', 'integer', 'min:1', 'max:999'], 'reason' => ['required', 'string', 'max:12'], 'reference' => ['nullable', 'string', 'max:40'], 'note' => ['nullable', 'string', 'max:200'],
        ]);

        return response()->json($this->waste->record(
            $this->property->current(), $this->actor($request), $data['kind'], $data['item_id'], isset($data['quantity_milli']) ? (int) $data['quantity_milli'] : null, $data['unit'] ?? null,
            isset($data['portions']) ? (int) $data['portions'] : null, $data['reason'], $data['reference'] ?? null, $data['note'] ?? null,
        ), 201)->header('Cache-Control', 'no-store');
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
