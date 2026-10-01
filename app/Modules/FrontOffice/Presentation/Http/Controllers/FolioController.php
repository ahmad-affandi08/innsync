<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The folio screen and its actions. Rules, permissions and approvals live in `FolioService`. */
final readonly class FolioController
{
    public function __construct(private FolioService $folios, private ReservationService $reservations, private PropertyContext $property) {}

    public function show(Request $request, string $id): Response
    {
        $property = $this->property->current();
        $actor = $this->actor($request);
        $folio = $this->folios->view($property, $actor, $id);

        return Inertia::render('front-office/pages/folio', [
            'folio' => $folio,
            'approvals' => $this->folios->approvalsFor($property, $actor, $id),
            'reservation' => $this->summary($this->reservations->find($property, $actor, $folio['reservation_id'])->toArray()),
        ]);
    }

    public function open(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['label' => ['nullable', 'string', 'max:60'], 'window' => ['nullable', 'integer', 'min:1', 'max:20']]);

        return $this->json(['folio' => $this->folios->open($this->property->current(), $this->actor($request), $id, $data['label'] ?? 'Guest', (int) ($data['window'] ?? 1))], 201);
    }

    public function charge(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'], 'description' => ['required', 'string', 'max:200'], 'amount_minor' => ['required', 'integer', 'min:1'],
            'prices_include_charges' => ['required', 'boolean'],
        ]);
        $key = (string) $request->header('Idempotency-Key');

        return $this->json($this->folios->charge($this->property->current(), $this->actor($request), $id, strtoupper($data['code']), $data['description'], (int) $data['amount_minor'], (bool) $data['prices_include_charges'], $key === '' ? null : 'ui:'.$key));
    }

    public function pay(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['payment_method' => ['required', 'string', 'max:20'], 'amount_minor' => ['required', 'integer', 'min:1'], 'reference' => ['nullable', 'string', 'max:80'], 'purpose' => ['required', 'string', 'max:12']]);
        $key = (string) $request->header('Idempotency-Key');

        return $this->json($this->folios->pay($this->property->current(), $this->actor($request), $id, $data['payment_method'], (int) $data['amount_minor'], $data['reference'] ?? null, $data['purpose'], $key === '' ? null : 'ui:'.$key));
    }

    public function close(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['folio' => $this->folios->close($this->property->current(), $this->actor($request), $id, (int) $data['lock_version'])]);
    }

    public function requestReversal(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $approval = $this->folios->requestReversalApproval($this->property->current(), $this->actor($request), $id, $data['reason'], IdempotencyKey::fromString((string) $request->header('Idempotency-Key')));

        return $this->json(['approval' => $approval->toArray()], 201);
    }

    public function reverse(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500'], 'approval_id' => ['nullable', 'string', 'size:26']]);

        return $this->json($this->folios->reverse($this->property->current(), $this->actor($request), $id, $data['reason'], $data['approval_id'] ?? null));
    }

    public function requestRefund(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['payment_method' => ['required', 'string', 'max:20'], 'amount_minor' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:500']]);
        $approval = $this->folios->requestRefundApproval($this->property->current(), $this->actor($request), $id, $data['payment_method'], (int) $data['amount_minor'], $data['reason'], IdempotencyKey::fromString((string) $request->header('Idempotency-Key')));

        return $this->json(['approval' => $approval->toArray()], 201);
    }

    public function refund(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'payment_method' => ['required', 'string', 'max:20'], 'amount_minor' => ['required', 'integer', 'min:1'], 'reference' => ['nullable', 'string', 'max:80'],
            'reason' => ['required', 'string', 'max:500'], 'approval_id' => ['nullable', 'string', 'size:26'],
        ]);

        return $this->json($this->folios->refund($this->property->current(), $this->actor($request), $id, $data['payment_method'], (int) $data['amount_minor'], $data['reference'] ?? null, $data['reason'], $data['approval_id'] ?? null));
    }

    /**
     * @param  array<string, mixed>  $reservation
     * @return array<string, mixed>
     */
    private function summary(array $reservation): array
    {
        unset($reservation['price_snapshot']);

        return $reservation;
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }

    /** @param array<string, mixed> $body */
    private function json(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status)->header('Cache-Control', 'no-store');
    }
}
