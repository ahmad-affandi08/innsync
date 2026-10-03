<?php

declare(strict_types=1);

namespace App\Modules\Laundry\Presentation\Http\Controllers;

use App\Modules\Laundry\Application\SupplyUseService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The supplies the laundry uses. Every rule and permission lives in `SupplyUseService`. */
final readonly class SupplyUseController
{
    public function __construct(private SupplyUseService $supplies, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('laundry/pages/supplies', ['overview' => $this->supplies->overview($this->property->current(), (string) $request->user()->getAuthIdentifier())]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['item_id' => ['required', 'string', 'size:26'], 'location_id' => ['required', 'string', 'size:26'], 'unit' => ['required', 'string', 'max:8'], 'quantity' => ['required', 'string', 'max:14'], 'note' => ['nullable', 'string', 'max:200']]);
        $made = $this->supplies->use($this->property->current(), (string) $request->user()->getAuthIdentifier(), $data['item_id'], $data['location_id'], $data['unit'], $data['quantity'], $data['note'] ?? null);

        return response()->json(['use' => $made], 201)->header('Cache-Control', 'no-store');
    }
}
