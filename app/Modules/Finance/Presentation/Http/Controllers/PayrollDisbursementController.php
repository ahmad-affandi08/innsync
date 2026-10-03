<?php

declare(strict_types=1);

namespace App\Modules\Finance\Presentation\Http\Controllers;

use App\Modules\Finance\Application\PayrollDisbursementService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Approved payroll runs to verify and pay. Every rule and permission lives in `PayrollDisbursementService`. */
final readonly class PayrollDisbursementController
{
    public function __construct(private PayrollDisbursementService $payroll, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('finance/pages/payroll', ['overview' => $this->payroll->overview($this->property->current(), $this->actor($request))]);
    }

    public function verify(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['confirmed_net_minor' => ['required', 'integer'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return response()->json($this->payroll->verify($this->property->current(), $this->actor($request), $id, (int) $data['confirmed_net_minor'], (int) $data['lock_version']));
    }

    public function pay(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['method' => ['required', 'string', 'max:12'], 'reference' => ['required', 'string', 'max:80'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return response()->json($this->payroll->pay($this->property->current(), $this->actor($request), $id, $data['method'], $data['reference'], (int) $data['lock_version']));
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
