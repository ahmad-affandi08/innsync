<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\GiftCards\GiftCardService;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Gift cards and vouchers: selling, finding what a code is worth, and voiding. Every rule and permission lives in the service. */
final readonly class GiftCardController
{
    public function __construct(private GiftCardService $cards, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('front-office/pages/gift-cards', ['overview' => $this->cards->overview($this->property->current(), $this->actor($request))]);
    }

    public function issue(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1', 'max:50000000000'], 'kind' => ['required', 'string', 'max:10'], 'payment_method' => ['required', 'string', 'max:20'], 'reference' => ['nullable', 'string', 'max:60'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'], 'holder_note' => ['nullable', 'string', 'max:120'],
        ]);
        $made = $this->cards->issue($this->property->current(), $this->actor($request), IdempotencyKey::fromString((string) $request->header('Idempotency-Key')), (int) $data['amount_minor'], $data['kind'], $data['payment_method'], $data['reference'] ?? null, $data['expires_on'] ?? null, $data['holder_note'] ?? null);

        return response()->json($made, 201)->header('Cache-Control', 'no-store');
    }

    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);

        return response()->json(['card' => $this->cards->lookup($this->property->current(), $this->actor($request), $data['code'])])->header('Cache-Control', 'no-store');
    }

    public function void(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['disposition' => ['required', 'string', 'max:14'], 'reason' => ['required', 'string', 'max:200']]);

        return response()->json(['card' => $this->cards->void($this->property->current(), $this->actor($request), $id, $data['disposition'], $data['reason'])])->header('Cache-Control', 'no-store');
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
