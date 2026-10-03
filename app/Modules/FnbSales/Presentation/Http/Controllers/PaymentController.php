<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Presentation\Http\Controllers;

use App\Modules\FnbSales\Application\PaymentService;
use App\Modules\FnbSales\Application\ShiftService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The cashier's shift and the payments of a bill. Every rule and permission lives in the application services. */
final readonly class PaymentController
{
    public function __construct(private ShiftService $shifts, private PaymentService $payments, private PropertyContext $property) {}

    public function shift(Request $request): Response
    {
        return Inertia::render('fnb-sales/pages/shift', ['overview' => $this->shifts->mine($this->property->current(), $this->actor($request))]);
    }

    public function openShift(Request $request): JsonResponse
    {
        $data = $request->validate(['outlet_id' => ['required', 'string', 'size:26'], 'opening_float_minor' => ['required', 'integer', 'min:0', 'max:9000000000000']]);

        return $this->json(['shift' => $this->shifts->open($this->property->current(), $this->actor($request), $data['outlet_id'], (int) $data['opening_float_minor'])], 201);
    }

    public function closeShift(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['counted_cash_minor' => ['required', 'integer', 'min:0', 'max:9000000000000'], 'reason' => ['nullable', 'string', 'max:200'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['shift' => $this->shifts->close($this->property->current(), $this->actor($request), $id, (int) $data['counted_cash_minor'], $data['reason'] ?? null, (int) $data['lock_version'])]);
    }

    public function pay(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'lock_version' => ['required', 'integer', 'min:0'], 'method' => ['required', 'string', 'max:8'], 'amount_minor' => ['required', 'integer', 'min:1', 'max:9000000000000'], 'tendered_minor' => ['nullable', 'integer', 'min:0', 'max:9000000000000'],
            'reference' => ['nullable', 'string', 'max:60'], 'room_id' => ['nullable', 'string', 'size:26'], 'guest_name' => ['nullable', 'string', 'max:80'],
        ]);

        return $this->json($this->payments->pay($this->property->current(), $this->actor($request), $id, (int) $data['lock_version'], $data['method'], (int) $data['amount_minor'], isset($data['tendered_minor']) ? (int) $data['tendered_minor'] : null, $data['reference'] ?? null, $data['room_id'] ?? null, $data['guest_name'] ?? null));
    }

    public function qris(Request $request, string $id, string $payment): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0'], 'status' => ['required', 'string', 'max:10'], 'reference' => ['nullable', 'string', 'max:60'], 'reason' => ['nullable', 'string', 'max:200']]);

        return $this->json($this->payments->updateQris($this->property->current(), $this->actor($request), $id, $payment, (int) $data['lock_version'], $data['status'], $data['reference'] ?? null, $data['reason'] ?? null));
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
