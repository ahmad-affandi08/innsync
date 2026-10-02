<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\Stays\StayTimeFeeService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Early check-in and late check-out fee policies. Every rule lives in `StayTimeFeeService`. */
final readonly class StayFeePolicyController
{
    public function __construct(private StayTimeFeeService $fees, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('front-office/pages/stay-fees', ['catalogue' => $this->fees->policies($this->property->current(), (string) $request->user()->getAuthIdentifier())]);
    }

    public function define(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'string', 'max:14'], 'effective_from' => ['required', 'string', 'size:10'], 'grace_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'bands' => ['nullable', 'array', 'max:6'], 'bands.*.up_to_minutes' => ['required', 'integer', 'min:1', 'max:1440'], 'bands.*.percent_bp' => ['required', 'integer', 'min:0', 'max:10000'],
            'beyond_bp' => ['required', 'integer', 'min:0', 'max:10000'], 'reason' => ['required', 'string', 'max:300'],
        ]);

        return response()->json(['policy' => $this->fees->definePolicy($this->property->current(), (string) $request->user()->getAuthIdentifier(), $data['kind'], $data['effective_from'], (int) $data['grace_minutes'], array_values($data['bands'] ?? []), (int) $data['beyond_bp'], $data['reason'])], 201)->header('Cache-Control', 'no-store');
    }
}
