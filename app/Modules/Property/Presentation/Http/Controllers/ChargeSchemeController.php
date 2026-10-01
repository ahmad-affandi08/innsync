<?php

declare(strict_types=1);

namespace App\Modules\Property\Presentation\Http\Controllers;

use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Rates\RateQuoteService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final readonly class ChargeSchemeController
{
    public function __construct(private ChargeSchemeService $schemes, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $scope = $this->scopeOf((string) $request->query('scope', RateQuoteService::CHARGE_SCOPE));
        $history = $this->schemes->history($this->property->current(), (string) $request->user()->getAuthIdentifier(), $scope);

        return Inertia::render('property/pages/charge-schemes', ['scope' => $scope, 'scopes' => ChargeSchemeService::SCOPES, 'schemes' => array_map(static fn ($s): array => $s->toArray(), $history)]);
    }

    public function define(Request $request): JsonResponse
    {
        $data = $request->validate([
            'effective_from' => ['required', 'string', 'size:10'], 'service_charge_rate' => ['required', 'string', 'max:6'], 'tax_rate' => ['required', 'string', 'max:6'],
            'tax_on_service_charge' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:500'], 'scope' => ['nullable', 'string', 'max:20'],
        ]);

        $config = $this->schemes->define($this->property->current(), (string) $request->user()->getAuthIdentifier(), $this->scopeOf($data['scope'] ?? RateQuoteService::CHARGE_SCOPE), $data['effective_from'], $data['service_charge_rate'], $data['tax_rate'], (bool) $data['tax_on_service_charge'], $data['reason']);

        return response()->json(['scheme' => $config->toArray()], 201)->header('Cache-Control', 'no-store');
    }

    private function scopeOf(string $scope): string
    {
        return in_array($scope, ChargeSchemeService::SCOPES, true) ? $scope : RateQuoteService::CHARGE_SCOPE;
    }
}
