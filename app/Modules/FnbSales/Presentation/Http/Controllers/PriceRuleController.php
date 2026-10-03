<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Presentation\Http\Controllers;

use App\Modules\FnbSales\Application\PriceRuleService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The price lists and scheduled promotions of the outlets. Every rule and permission lives in `PriceRuleService`. */
final readonly class PriceRuleController
{
    public function __construct(private PriceRuleService $prices, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['outlet' => ['nullable', 'string', 'size:26']]);

        return Inertia::render('fnb-sales/pages/prices', ['overview' => $this->prices->overview($this->property->current(), $this->actor($request), $data['outlet'] ?? null)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'outlet_id' => ['required', 'string', 'size:26'], 'item_id' => ['required', 'string', 'size:26'], 'variant_id' => ['nullable', 'string', 'size:26'], 'channel' => ['required', 'string', 'max:12'], 'kind' => ['required', 'string', 'max:5'],
            'name' => ['required', 'string', 'max:80'], 'price_minor' => ['required', 'integer', 'min:1', 'max:9000000000000'], 'valid_from' => ['required', 'date_format:Y-m-d'], 'valid_to' => ['nullable', 'date_format:Y-m-d'],
            'days' => ['required', 'integer', 'min:1', 'max:127'], 'from_time' => ['nullable', 'date_format:H:i'], 'to_time' => ['nullable', 'date_format:H:i'],
        ]);

        return response()->json($this->prices->add($this->property->current(), $this->actor($request), $data['outlet_id'], $data), 201)->header('Cache-Control', 'no-store');
    }

    public function retire(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return response()->json($this->prices->retire($this->property->current(), $this->actor($request), $id, $data['reason']))->header('Cache-Control', 'no-store');
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
