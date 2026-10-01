<?php

declare(strict_types=1);

namespace App\Modules\Property\Presentation\Http\Controllers;

use App\Modules\Property\Application\Policies\BookingPolicyService;
use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final readonly class BookingPolicyController
{
    public function __construct(private BookingPolicyService $policies, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $overview = $this->policies->overview($this->property->current(), $this->actor($request));

        return Inertia::render('property/pages/booking-policies', [...$overview, 'currency' => app(PropertyCurrencyReader::class)->currencyOf($this->property->current())]);
    }

    public function define(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rate_plan_id' => ['nullable', 'string', 'size:26'], 'source' => ['nullable', 'string', 'max:10'], 'effective_from' => ['required', 'string', 'size:10'],
            'guarantee_required' => ['required', 'boolean'], 'deposit_basis' => ['required', 'string', 'max:12'], 'deposit_value' => ['required', 'integer', 'min:0'],
            'deposit_due_days' => ['required', 'integer', 'min:0', 'max:365'], 'cancel_free_days' => ['required', 'integer', 'min:0', 'max:365'],
            'cancel_penalty_kind' => ['required', 'string', 'max:12'], 'cancel_penalty_value' => ['required', 'integer', 'min:0'],
            'noshow_penalty_kind' => ['required', 'string', 'max:12'], 'noshow_penalty_value' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:300'],
        ]);

        $policy = $this->policies->define(
            $this->property->current(), $this->actor($request), $data['rate_plan_id'] ?? null, $data['source'] ?? null, $data['effective_from'], (bool) $data['guarantee_required'],
            $data['deposit_basis'], (int) $data['deposit_value'], (int) $data['deposit_due_days'], (int) $data['cancel_free_days'], $data['cancel_penalty_kind'], (int) $data['cancel_penalty_value'],
            $data['noshow_penalty_kind'], (int) $data['noshow_penalty_value'], $data['reason'],
        );

        return response()->json(['policy' => $policy], 201)->header('Cache-Control', 'no-store');
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
