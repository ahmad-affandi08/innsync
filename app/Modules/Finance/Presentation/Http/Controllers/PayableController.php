<?php

declare(strict_types=1);

namespace App\Modules\Finance\Presentation\Http\Controllers;

use App\Modules\Finance\Application\PayableService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Payables, supplier credits, the aging report and the due schedule. Every rule and permission lives in the application service. */
final readonly class PayableController
{
    public function __construct(private PayableService $payables, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['status' => ['nullable', 'string', 'max:8'], 'supplier' => ['nullable', 'string', 'size:26']]);

        return Inertia::render('finance/pages/payables', ['overview' => $this->payables->overview($this->property->current(), $this->actor($request), $data['status'] ?? null, $data['supplier'] ?? null), 'status' => $data['status'] ?? 'open']);
    }

    public function show(Request $request, string $id): Response
    {
        return Inertia::render('finance/pages/payable', ['payable' => $this->payables->show($this->property->current(), $this->actor($request), $id)]);
    }

    public function classify(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['expense_account_id' => ['nullable', 'string', 'size:26']]);

        return $this->json(['payable' => $this->payables->classify($this->property->current(), $this->actor($request), $id, $data['expense_account_id'] ?? null)]);
    }

    public function applyCredit(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['credit_id' => ['required', 'string', 'size:26'], 'amount_minor' => ['required', 'integer', 'min:1', 'max:9000000000000']]);

        return $this->json(['payable' => $this->payables->applyCredit($this->property->current(), $this->actor($request), $id, $data['credit_id'], (int) $data['amount_minor'])], 201);
    }

    public function aging(Request $request): Response
    {
        $data = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d']]);

        return Inertia::render('finance/pages/aging', ['report' => $this->payables->aging($this->property->current(), $this->actor($request), $data['as_of'] ?? null)]);
    }

    public function schedule(Request $request): Response
    {
        $data = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:90']]);

        return Inertia::render('finance/pages/schedule', ['schedule' => $this->payables->schedule($this->property->current(), $this->actor($request), (int) ($data['days'] ?? 14))]);
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
