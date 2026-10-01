<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\Reservations\RateChangeService;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Changing the room price of a booked reservation (FR-FO-013). Every rule and approval lives in `RateChangeService`. */
final readonly class RateChangeController
{
    public function __construct(private RateChangeService $rates, private PropertyContext $property) {}

    public function preview(Request $request, string $id): JsonResponse
    {
        $data = $this->validated($request, false);

        return $this->json(['preview' => $this->rates->preview($this->property->current(), $this->actor($request), $id, (int) $data['price_minor'], (bool) $data['nett'], $data['from'] ?? null)]);
    }

    public function requestApproval(Request $request, string $id): JsonResponse
    {
        $data = $this->validated($request, true);
        $approval = $this->rates->requestApproval($this->property->current(), $this->actor($request), $id, (int) $data['price_minor'], (bool) $data['nett'], $data['from'] ?? null, $data['reason'], IdempotencyKey::fromString((string) $request->header('Idempotency-Key')));

        return $this->json(['approval' => $approval->toArray()], 201);
    }

    public function change(Request $request, string $id): JsonResponse
    {
        $data = $this->validated($request, true);
        $overview = $this->rates->change($this->property->current(), $this->actor($request), $id, (int) $data['price_minor'], (bool) $data['nett'], $data['from'] ?? null, $data['reason'], $data['approval_id'] ?? null);

        return $this->json(['rates' => $overview]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $withReason): array
    {
        return $request->validate([
            'price_minor' => ['required', 'integer', 'min:0'], 'nett' => ['required', 'boolean'], 'from' => ['nullable', 'string', 'size:10'],
            'reason' => $withReason ? ['required', 'string', 'max:300'] : ['nullable', 'string', 'max:300'], 'approval_id' => ['nullable', 'string', 'size:26'],
        ]);
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
