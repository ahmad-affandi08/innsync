<?php

declare(strict_types=1);

namespace App\Modules\Finance\Presentation\Http\Controllers;

use App\Modules\Finance\Application\SettlementService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The settlements of QRIS and card against the books and the bank. Every rule and permission lives in `SettlementService`. */
final readonly class SettlementController
{
    public function __construct(private SettlementService $settlements, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('finance/pages/settlements', ['overview' => $this->settlements->overview($this->property->current(), (string) $request->user()->getAuthIdentifier())]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', 'string', 'max:8'], 'provider' => ['required', 'string', 'max:80'], 'covers_from' => ['required', 'date_format:Y-m-d'], 'covers_to' => ['required', 'date_format:Y-m-d'], 'settled_on' => ['required', 'date_format:Y-m-d'],
            'gross_minor' => ['required', 'integer', 'min:1', 'max:9000000000000'], 'fee_minor' => ['required', 'integer', 'min:0', 'max:9000000000000'], 'net_minor' => ['required', 'integer', 'min:0', 'max:9000000000000'],
            'bank_reference' => ['required', 'string', 'max:60'], 'note' => ['nullable', 'string', 'max:200'],
        ]);

        return response()->json($this->settlements->record(
            $this->property->current(), (string) $request->user()->getAuthIdentifier(), $data['method'], $data['provider'], $data['covers_from'], $data['covers_to'], $data['settled_on'], (int) $data['gross_minor'], (int) $data['fee_minor'], (int) $data['net_minor'], $data['bank_reference'], $data['note'] ?? null,
        ), 201)->header('Cache-Control', 'no-store');
    }
}
