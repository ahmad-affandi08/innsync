<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Presentation\Http\Controllers;

use App\Modules\InventoryPurchasing\Application\PurchaseReturnService;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Goods returned to a supplier. Every rule and permission lives in the application service. */
final readonly class PurchaseReturnController
{
    public function __construct(private PurchaseReturnService $returns, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['supplier' => ['nullable', 'string', 'size:26']]);

        return Inertia::render('inventory-purchasing/pages/returns', ['overview' => $this->returns->overview($this->property->current(), $this->actor($request), $data['supplier'] ?? null)]);
    }

    public function show(Request $request, string $id): Response
    {
        return Inertia::render('inventory-purchasing/pages/return', ['purchaseReturn' => $this->returns->show($this->property->current(), $this->actor($request), $id)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'receipt_id' => ['required', 'string', 'size:26'], 'reason' => ['required', 'string', 'max:16'], 'note' => ['nullable', 'string', 'max:200'], 'credit_note_number' => ['nullable', 'string', 'max:40'],
            'credit_tax_minor' => ['nullable', 'integer', 'min:0', 'max:9000000000000'], 'lines' => ['required', 'array', 'min:1', 'max:60'], 'lines.*.receipt_line_id' => ['required', 'string', 'size:26'], 'lines.*.quantity' => ['required', 'string', 'max:14'],
        ]);

        return $this->json(['return' => $this->returns->create($this->property->current(), $this->actor($request), $data['receipt_id'], $data['reason'], $data['note'] ?? null, $data['credit_note_number'] ?? null, (int) ($data['credit_tax_minor'] ?? 0), array_values($data['lines']), IdempotencyKey::fromString((string) $request->header('Idempotency-Key')))], 201);
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
