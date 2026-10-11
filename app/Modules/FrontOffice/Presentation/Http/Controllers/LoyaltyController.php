<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\Loyalty\LoyaltyService;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Loyalty points: the accounts, turning points into a voucher, and a correction. Every rule and permission lives in the service. */
final readonly class LoyaltyController
{
    public function __construct(private LoyaltyService $loyalty, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['account' => ['nullable', 'string', 'size:26']]);

        return Inertia::render('front-office/pages/loyalty', ['overview' => $this->loyalty->overview($this->property->current(), $this->actor($request), $data['account'] ?? null)]);
    }

    public function redeem(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['points' => ['required', 'integer', 'min:1', 'max:1000000']]);
        $made = $this->loyalty->redeem($this->property->current(), $this->actor($request), IdempotencyKey::fromString((string) $request->header('Idempotency-Key')), $id, (int) $data['points']);

        return response()->json($made, 201)->header('Cache-Control', 'no-store');
    }

    public function adjust(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['points' => ['required', 'integer', 'between:-100000,100000'], 'reason' => ['required', 'string', 'max:200']]);
        $this->loyalty->adjust($this->property->current(), $this->actor($request), $id, (int) $data['points'], $data['reason']);

        return response()->json(['ok' => true])->header('Cache-Control', 'no-store');
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
