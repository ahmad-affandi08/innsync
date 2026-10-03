<?php

declare(strict_types=1);

namespace App\Modules\Finance\Presentation\Http\Controllers;

use App\Modules\Finance\Application\ExceptionService;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Reconciliation exceptions. Every rule and permission lives in the application service. */
final readonly class ExceptionController
{
    public function __construct(private ExceptionService $exceptions, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['status' => ['nullable', 'string', 'max:10']]);

        return Inertia::render('finance/pages/exceptions', ['overview' => $this->exceptions->overview($this->property->current(), $this->actor($request), $data['status'] ?? null), 'status' => $data['status'] ?? '']);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'string', 'max:24'], 'business_date' => ['nullable', 'date_format:Y-m-d'], 'amount_minor' => ['required', 'integer', 'min:1', 'max:9000000000000'], 'method' => ['nullable', 'string', 'max:14'],
            'reference' => ['nullable', 'string', 'max:80'], 'folio_ref' => ['nullable', 'string', 'max:40'], 'description' => ['required', 'string', 'max:300'],
        ]);

        return $this->json(['exception' => $this->exceptions->raise($this->property->current(), $this->actor($request), $data['kind'], $data['business_date'] ?? null, (int) $data['amount_minor'], $data['method'] ?? null, $data['reference'] ?? null, $data['folio_ref'] ?? null, $data['description'], IdempotencyKey::fromString((string) $request->header('Idempotency-Key')))], 201);
    }

    public function reconcile(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'string', 'max:10'], 'resolution' => ['required', 'string', 'max:300'], 'correction_number' => ['nullable', 'string', 'max:20'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['exception' => $this->exceptions->reconcile($this->property->current(), $this->actor($request), $id, $data['status'], $data['resolution'], $data['correction_number'] ?? null, (int) $data['lock_version'])]);
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
