<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Presentation\Http\Controllers;

use App\Modules\FnbSales\Application\BillService;
use App\Modules\FnbSales\Application\LineDiscountService;
use App\Modules\FnbSales\Application\RefundService;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The floor of an outlet and its bills. Every rule, permission and approval lives in `BillService`. */
final readonly class BillController
{
    public function __construct(private BillService $bills, private LineDiscountService $discounts, private RefundService $refunds, private PropertyContext $property) {}

    public function floor(Request $request): Response
    {
        $data = $request->validate(['outlet' => ['nullable', 'string', 'size:26']]);

        return Inertia::render('fnb-sales/pages/floor', ['floor' => $this->bills->floor($this->property->current(), $this->actor($request), $data['outlet'] ?? null)]);
    }

    public function open(Request $request): JsonResponse
    {
        $data = $request->validate(['outlet_id' => ['required', 'string', 'size:26'], 'table_id' => ['nullable', 'string', 'size:26'], 'room_id' => ['nullable', 'string', 'size:26'], 'covers' => ['required', 'integer', 'min:1', 'max:500'], 'note' => ['nullable', 'string', 'max:200']]);

        return $this->json($this->bills->open($this->property->current(), $this->actor($request), $data['outlet_id'], $data['table_id'] ?? null, $data['room_id'] ?? null, (int) $data['covers'], $data['note'] ?? null), 201);
    }

    public function show(Request $request, string $id): Response
    {
        return Inertia::render('fnb-sales/pages/bill', ['view' => $this->bills->show($this->property->current(), $this->actor($request), $id)]);
    }

    public function addLine(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'lock_version' => ['required', 'integer', 'min:0'], 'item_id' => ['required', 'string', 'size:26'], 'variant_id' => ['nullable', 'string', 'size:26'], 'modifier_ids' => ['nullable', 'array', 'max:30'], 'modifier_ids.*' => ['string', 'size:26'],
            'quantity' => ['required', 'integer', 'min:1', 'max:99'], 'note' => ['nullable', 'string', 'max:120'],
        ]);

        return $this->json($this->bills->addLine($this->property->current(), $this->actor($request), $id, (int) $data['lock_version'], $data['item_id'], $data['variant_id'] ?? null, $data['modifier_ids'] ?? [], (int) $data['quantity'], $data['note'] ?? null));
    }

    public function removeLine(Request $request, string $id, string $line): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json($this->bills->removeLine($this->property->current(), $this->actor($request), $id, $line, (int) $data['lock_version']));
    }

    public function send(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json($this->bills->send($this->property->current(), $this->actor($request), $id, (int) $data['lock_version']));
    }

    public function requestVoid(Request $request, string $id, string $line): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return $this->json($this->bills->requestVoid($this->property->current(), $this->actor($request), $id, $line, $data['reason'], IdempotencyKey::fromString((string) $request->header('Idempotency-Key'))), 201);
    }

    public function voidLine(Request $request, string $id, string $line): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:200'], 'approval_id' => ['nullable', 'string', 'size:26']]);

        return $this->json($this->bills->voidLine($this->property->current(), $this->actor($request), $id, $line, $data['reason'], $data['approval_id'] ?? null, (int) $data['lock_version']));
    }

    public function requestDiscount(Request $request, string $id, string $line): JsonResponse
    {
        $data = $request->validate(['kind' => ['required', 'string', 'max:8'], 'value' => ['nullable', 'integer', 'min:1', 'max:9000000000000'], 'reason' => ['required', 'string', 'max:200']]);

        return $this->json($this->discounts->request($this->property->current(), $this->actor($request), $id, $line, $data['kind'], isset($data['value']) ? (int) $data['value'] : null, $data['reason'], IdempotencyKey::fromString((string) $request->header('Idempotency-Key'))), 201);
    }

    public function discount(Request $request, string $id, string $line): JsonResponse
    {
        $data = $request->validate(['kind' => ['required', 'string', 'max:8'], 'value' => ['nullable', 'integer', 'min:1', 'max:9000000000000'], 'lock_version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:200'], 'approval_id' => ['nullable', 'string', 'size:26']]);
        $this->discounts->apply($this->property->current(), $this->actor($request), $id, $line, $data['kind'], isset($data['value']) ? (int) $data['value'] : null, $data['reason'], $data['approval_id'] ?? null, (int) $data['lock_version']);

        return $this->json($this->bills->show($this->property->current(), $this->actor($request), $id));
    }

    public function removeDiscount(Request $request, string $id, string $line): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:200']]);
        $this->discounts->remove($this->property->current(), $this->actor($request), $id, $line, $data['reason'], (int) $data['lock_version']);

        return $this->json($this->bills->show($this->property->current(), $this->actor($request), $id));
    }

    public function requestRefund(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return $this->json($this->refunds->request($this->property->current(), $this->actor($request), $id, $data['reason'], IdempotencyKey::fromString((string) $request->header('Idempotency-Key'))), 201);
    }

    public function refund(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:200'], 'approval_id' => ['nullable', 'string', 'size:26']]);
        $this->refunds->refund($this->property->current(), $this->actor($request), $id, $data['reason'], $data['approval_id'] ?? null, (int) $data['lock_version']);

        return $this->json($this->bills->show($this->property->current(), $this->actor($request), $id));
    }

    public function reprint(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return $this->json($this->refunds->reprint($this->property->current(), $this->actor($request), $id, $data['reason']));
    }

    public function requestCancel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return $this->json($this->bills->requestCancel($this->property->current(), $this->actor($request), $id, $data['reason'], IdempotencyKey::fromString((string) $request->header('Idempotency-Key'))), 201);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:200'], 'approval_id' => ['nullable', 'string', 'size:26']]);

        return $this->json($this->bills->cancel($this->property->current(), $this->actor($request), $id, $data['reason'], $data['approval_id'] ?? null, (int) $data['lock_version']));
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
