<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\ForeignPayments\ForeignPaymentService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Payment in a foreign currency: the switch, the rates and taking the payment. Every rule lives in `ForeignPaymentService`. */
final readonly class ForeignPaymentController
{
    public function __construct(private ForeignPaymentService $foreign, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('front-office/pages/foreign-currency', ['overview' => $this->foreign->overview($this->property->current(), $this->actor($request))]);
    }

    public function enable(Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:300']]);

        return response()->json(['settings' => $this->foreign->setEnabled($this->property->current(), $this->actor($request), (bool) $data['enabled'], (int) $data['lock_version'], $data['reason'])])->header('Cache-Control', 'no-store');
    }

    public function setRate(Request $request): JsonResponse
    {
        $data = $request->validate(['currency' => ['required', 'string', 'size:3'], 'rate_e4' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:300']]);

        return response()->json(['rate' => $this->foreign->setRate($this->property->current(), $this->actor($request), $data['currency'], (int) $data['rate_e4'], $data['reason'])], 201)->header('Cache-Control', 'no-store');
    }

    public function pay(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'payment_method' => ['required', 'string', 'max:20'], 'currency' => ['required', 'string', 'size:3'], 'foreign_minor' => ['required', 'integer', 'min:1'],
            'reference' => ['nullable', 'string', 'max:60'], 'purpose' => ['required', 'string', 'max:12'],
        ]);
        $key = (string) $request->header('Idempotency-Key');

        return response()->json($this->foreign->pay($this->property->current(), $this->actor($request), $id, $data['payment_method'], $data['currency'], (int) $data['foreign_minor'], $data['reference'] ?? null, $data['purpose'], $key === '' ? null : 'ui:'.$key))->header('Cache-Control', 'no-store');
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
