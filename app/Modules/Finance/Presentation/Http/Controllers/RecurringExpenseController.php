<?php

declare(strict_types=1);

namespace App\Modules\Finance\Presentation\Http\Controllers;

use App\Modules\Finance\Application\RecurringExpenseService;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Recurring expenses. Every rule and permission lives in the application service. */
final readonly class RecurringExpenseController
{
    public function __construct(private RecurringExpenseService $recurring, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('finance/pages/recurring', ['overview' => $this->recurring->overview($this->property->current(), $this->actor($request))]);
    }

    public function show(Request $request, string $id): Response
    {
        return Inertia::render('finance/pages/recurring-detail', ['item' => $this->recurring->show($this->property->current(), $this->actor($request), $id)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'], 'expense_account_id' => ['required', 'string', 'size:26'], 'payee' => ['nullable', 'string', 'max:120'], 'amount_minor' => ['required', 'integer', 'min:1', 'max:9000000000000'],
            'frequency' => ['required', 'string', 'max:10'], 'due_day' => ['required', 'integer', 'min:1', 'max:31'], 'start_month' => ['required', 'string', 'max:7'], 'end_date' => ['nullable', 'date_format:Y-m-d'], 'remind_days' => ['nullable', 'integer', 'min:0', 'max:60'],
        ]);

        return $this->json(['item' => $this->recurring->create($this->property->current(), $this->actor($request), $data['name'], $data['expense_account_id'], $data['payee'] ?? null, (int) $data['amount_minor'], $data['frequency'], (int) $data['due_day'], $data['start_month'], $data['end_date'] ?? null, isset($data['remind_days']) ? (int) $data['remind_days'] : null)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'], 'expense_account_id' => ['required', 'string', 'size:26'], 'payee' => ['nullable', 'string', 'max:120'], 'amount_minor' => ['required', 'integer', 'min:1', 'max:9000000000000'],
            'remind_days' => ['required', 'integer', 'min:0', 'max:60'], 'end_date' => ['nullable', 'date_format:Y-m-d'], 'active' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0'],
        ]);

        return $this->json(['item' => $this->recurring->update($this->property->current(), $this->actor($request), $id, $data['name'], $data['expense_account_id'], $data['payee'] ?? null, (int) $data['amount_minor'], (int) $data['remind_days'], $data['end_date'] ?? null, (bool) $data['active'], (int) $data['lock_version'])]);
    }

    public function settle(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'in:paid,skipped'], 'amount_minor' => ['nullable', 'integer', 'min:1', 'max:9000000000000'], 'paid_on' => ['nullable', 'date_format:Y-m-d'], 'method' => ['nullable', 'string', 'max:12'],
            'reference' => ['nullable', 'string', 'max:60'], 'note' => ['nullable', 'string', 'max:200'],
        ]);

        return $this->json(['item' => $this->recurring->settle($this->property->current(), $this->actor($request), $id, $data['action'], isset($data['amount_minor']) ? (int) $data['amount_minor'] : null, $data['paid_on'] ?? null, $data['method'] ?? null, $data['reference'] ?? null, $data['note'] ?? null, IdempotencyKey::fromString((string) $request->header('Idempotency-Key')))], 201);
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
