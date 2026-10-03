<?php

declare(strict_types=1);

namespace App\Modules\Finance\Presentation\Http\Controllers;

use App\Modules\Finance\Application\SupplierPaymentService;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Payments to suppliers. Every rule and permission lives in the application service. */
final readonly class SupplierPaymentController
{
    public function __construct(private SupplierPaymentService $payments, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['status' => ['nullable', 'string', 'max:16']]);

        return Inertia::render('finance/pages/payments', ['overview' => $this->payments->overview($this->property->current(), $this->actor($request), $data['status'] ?? null), 'status' => $data['status'] ?? '']);
    }

    public function show(Request $request, string $id): Response
    {
        return Inertia::render('finance/pages/payment', ['payment' => $this->payments->show($this->property->current(), $this->actor($request), $id)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payable_id' => ['required', 'string', 'size:26'], 'amount_minor' => ['required', 'integer', 'min:1', 'max:9000000000000'], 'method' => ['required', 'string', 'max:12'], 'paid_on' => ['nullable', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:60'], 'note' => ['nullable', 'string', 'max:200'],
        ]);

        return $this->json(['payment' => $this->payments->pay($this->property->current(), $this->actor($request), $data['payable_id'], (int) $data['amount_minor'], $data['method'], $data['paid_on'] ?? null, $data['reference'] ?? null, $data['note'] ?? null, IdempotencyKey::fromString((string) $request->header('Idempotency-Key')))], 201);
    }

    public function release(Request $request, string $id): JsonResponse
    {
        return $this->json(['payment' => $this->payments->release($this->property->current(), $this->actor($request), $id)]);
    }

    public function reverse(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return $this->json(['payment' => $this->payments->reverse($this->property->current(), $this->actor($request), $id, $data['reason'])], 201);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return $this->json(['payment' => $this->payments->cancel($this->property->current(), $this->actor($request), $id, $data['reason'])]);
    }

    public function addProof(Request $request, string $id): JsonResponse
    {
        $request->validate(['proof' => ['required', 'file', 'max:5120']]);
        $upload = $request->file('proof');

        return $this->json(['payment' => $this->payments->addProof($this->property->current(), $this->actor($request), $id, (string) $upload->get(), $upload->getClientOriginalName())], 201);
    }

    public function proof(Request $request, string $id, string $proof): HttpResponse
    {
        $content = $this->payments->proof($this->property->current(), $this->actor($request), $id, $proof);

        return response($content->contents, 200, ['Content-Type' => $content->file->mimeType, 'Content-Disposition' => 'inline; filename="proof"', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
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
