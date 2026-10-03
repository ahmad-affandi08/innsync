<?php

declare(strict_types=1);

namespace App\Modules\Finance\Presentation\Http\Controllers;

use App\Modules\Finance\Application\CashReconciliationService;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The cash of closed cashier shifts against the cash received. Every rule and permission lives in the application service. */
final readonly class CashReconciliationController
{
    public function __construct(private CashReconciliationService $cash, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'only' => ['nullable', 'string', 'max:10']]);

        return Inertia::render('finance/pages/cash', [
            'overview' => $this->cash->overview($this->property->current(), $this->actor($request), $data['from'] ?? null, $data['to'] ?? null, $data['only'] ?? null),
            'filters' => ['from' => $data['from'] ?? '', 'to' => $data['to'] ?? '', 'only' => $data['only'] ?? ''],
        ]);
    }

    public function receive(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['deposited_minor' => ['required', 'integer', 'min:0', 'max:9000000000000'], 'reason' => ['nullable', 'string', 'max:300'], 'note' => ['nullable', 'string', 'max:200']]);

        return $this->json(['shift' => $this->cash->receive($this->property->current(), $this->actor($request), $id, (int) $data['deposited_minor'], $data['reason'] ?? null, $data['note'] ?? null, IdempotencyKey::fromString((string) $request->header('Idempotency-Key')))], 201);
    }

    public function settle(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'string', 'max:10'], 'resolution' => ['required', 'string', 'max:300'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['exception' => $this->cash->settle($this->property->current(), $this->actor($request), $id, $data['status'], $data['resolution'], (int) $data['lock_version'])]);
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
