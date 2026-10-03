<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Presentation\Http\Controllers;

use App\Modules\HumanResource\Application\PayrollAdjustmentService;
use App\Modules\HumanResource\Application\PayrollRunService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The payroll of a month: the run and its steps, and the adjustments for later periods. Every rule and permission lives in the services. */
final readonly class PayrollRunController
{
    public function __construct(private PayrollRunService $runs, private PayrollAdjustmentService $adjustments, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['run' => ['nullable', 'string', 'size:26']]);

        return Inertia::render('hr/pages/payroll-runs', ['overview' => $this->runs->overview($this->property->current(), $this->actor($request), $data['run'] ?? null)]);
    }

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate(['period' => ['required', 'string', 'size:7']]);

        return response()->json($this->runs->create($this->property->current(), $this->actor($request), $data['period']), 201);
    }

    public function calculate(Request $request, string $id): JsonResponse
    {
        return response()->json($this->runs->calculate($this->property->current(), $this->actor($request), $id, $this->lock($request)));
    }

    public function review(Request $request, string $id): JsonResponse
    {
        return response()->json($this->runs->review($this->property->current(), $this->actor($request), $id, $this->lock($request)));
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        return response()->json($this->runs->approve($this->property->current(), $this->actor($request), $id, $this->lock($request)));
    }

    public function reopen(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return response()->json($this->runs->reopen($this->property->current(), $this->actor($request), $id, $data['reason'], (int) $data['lock_version']));
    }

    public function discard(Request $request, string $id): JsonResponse
    {
        return response()->json($this->runs->discard($this->property->current(), $this->actor($request), $id));
    }

    public function adjust(Request $request): JsonResponse
    {
        $data = $request->validate(['employee_id' => ['required', 'string', 'size:26'], 'amount_minor' => ['required', 'integer'], 'label' => ['required', 'string', 'max:60'], 'taxable' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:200'], 'source_run_id' => ['nullable', 'string', 'size:26']]);

        return response()->json($this->adjustments->create($this->property->current(), $this->actor($request), $data['employee_id'], (int) $data['amount_minor'], $data['label'], (bool) $data['taxable'], $data['reason'], $data['source_run_id'] ?? null), 201);
    }

    public function cancelAdjustment(Request $request, string $id): JsonResponse
    {
        return response()->json($this->adjustments->cancel($this->property->current(), $this->actor($request), $id, $this->lock($request)));
    }

    private function lock(Request $request): int
    {
        return (int) $request->validate(['lock_version' => ['required', 'integer', 'min:0']])['lock_version'];
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
