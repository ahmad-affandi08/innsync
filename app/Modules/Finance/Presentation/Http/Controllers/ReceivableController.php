<?php

declare(strict_types=1);

namespace App\Modules\Finance\Presentation\Http\Controllers;

use App\Modules\Finance\Application\CustomerService;
use App\Modules\Finance\Application\ReceivableService;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Customers, receivables, receipts and collection notes. Every rule and permission lives in the application services. */
final readonly class ReceivableController
{
    public function __construct(private ReceivableService $receivables, private CustomerService $customers, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['status' => ['nullable', 'string', 'max:10'], 'customer_id' => ['nullable', 'string', 'size:26']]);

        return Inertia::render('finance/pages/receivables', [
            'overview' => $this->receivables->overview($this->property->current(), $this->actor($request), $data['status'] ?? null, $data['customer_id'] ?? null),
            'filters' => ['status' => $data['status'] ?? 'open', 'customer_id' => $data['customer_id'] ?? ''],
        ]);
    }

    public function show(Request $request, string $id): Response
    {
        return Inertia::render('finance/pages/receivable', ['receivable' => $this->receivables->show($this->property->current(), $this->actor($request), $id)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'string', 'size:26'], 'description' => ['required', 'string', 'max:200'], 'reference' => ['nullable', 'string', 'max:60'], 'amount_minor' => ['required', 'integer', 'min:1', 'max:9000000000000'],
            'issued_on' => ['nullable', 'date_format:Y-m-d'], 'due_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return $this->json(['receivable' => $this->receivables->create($this->property->current(), $this->actor($request), $data['customer_id'], $data['description'], $data['reference'] ?? null, (int) $data['amount_minor'], $data['issued_on'] ?? null, $data['due_date'] ?? null, IdempotencyKey::fromString((string) $request->header('Idempotency-Key')))], 201);
    }

    public function receive(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1', 'max:9000000000000'], 'method' => ['required', 'string', 'max:12'], 'received_on' => ['nullable', 'date_format:Y-m-d'], 'reference' => ['required', 'string', 'max:80'], 'note' => ['nullable', 'string', 'max:200'],
        ]);

        return $this->json(['receivable' => $this->receivables->receive($this->property->current(), $this->actor($request), $id, (int) $data['amount_minor'], $data['method'], $data['received_on'] ?? null, $data['reference'], $data['note'] ?? null, IdempotencyKey::fromString((string) $request->header('Idempotency-Key')))], 201);
    }

    public function note(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['kind' => ['required', 'string', 'max:10'], 'note' => ['required', 'string', 'max:300'], 'promised_on' => ['nullable', 'date_format:Y-m-d'], 'promised_minor' => ['nullable', 'integer', 'min:1', 'max:9000000000000']]);

        return $this->json(['receivable' => $this->receivables->note($this->property->current(), $this->actor($request), $id, $data['kind'], $data['note'], $data['promised_on'] ?? null, isset($data['promised_minor']) ? (int) $data['promised_minor'] : null)], 201);
    }

    public function adjust(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['kind' => ['required', 'string', 'in:credit_note,write_off'], 'amount_minor' => ['required', 'integer', 'min:1', 'max:9000000000000'], 'reason' => ['required', 'string', 'max:200']]);

        return $this->json(['receivable' => $this->receivables->adjust($this->property->current(), $this->actor($request), $id, $data['kind'], (int) $data['amount_minor'], $data['reason'])], 201);
    }

    public function reverseReceipt(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return $this->json(['receivable' => $this->receivables->reverseReceipt($this->property->current(), $this->actor($request), $id, $data['reason'])], 201);
    }

    public function aging(Request $request): Response
    {
        $data = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d']]);

        return Inertia::render('finance/pages/receivable-aging', ['aging' => $this->receivables->aging($this->property->current(), $this->actor($request), $data['as_of'] ?? null)]);
    }

    public function customers(Request $request): Response
    {
        return Inertia::render('finance/pages/customers', ['overview' => $this->customers->overview($this->property->current(), $this->actor($request))]);
    }

    public function storeCustomer(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20'], 'name' => ['required', 'string', 'max:120'], 'kind' => ['required', 'string', 'max:8'], 'terms_days' => ['required', 'integer', 'min:0', 'max:180']]);

        return $this->json(['customer' => $this->customers->create($this->property->current(), $this->actor($request), $data['code'], $data['name'], $data['kind'], (int) $data['terms_days'])], 201);
    }

    public function updateCustomer(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'terms_days' => ['required', 'integer', 'min:0', 'max:180'], 'active' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['customer' => $this->customers->update($this->property->current(), $this->actor($request), $id, $data['name'], (int) $data['terms_days'], (bool) $data['active'], (int) $data['lock_version'])]);
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
