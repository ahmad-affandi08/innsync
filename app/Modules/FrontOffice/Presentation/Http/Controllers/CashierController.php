<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\Cashier\CashierService;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The cashier shift screens and their actions. Every rule and permission lives in `CashierService`. */
final readonly class CashierController
{
    public function __construct(private CashierService $cashier, private PropertyContext $property) {}

    public function mine(Request $request): Response
    {
        return Inertia::render('front-office/pages/cashier', ['cashier' => $this->cashier->mine($this->property->current(), $this->actor($request))]);
    }

    public function index(Request $request): Response
    {
        $data = $request->validate(['status' => ['nullable', 'string', 'max:6'], 'cashier' => ['nullable', 'string', 'size:26']]);
        $property = $this->property->current();
        $actor = $this->actor($request);

        return Inertia::render('front-office/pages/cashier-shifts', [
            'list' => $this->cashier->search($property, $actor, $data['status'] ?? null, $data['cashier'] ?? null),
            'filters' => ['status' => $data['status'] ?? '', 'cashier' => $data['cashier'] ?? ''],
            'settings' => $this->maySettings($request) ? $this->cashier->settings($property, $actor) : null,
        ]);
    }

    public function show(Request $request, string $id): Response
    {
        return Inertia::render('front-office/pages/cashier-shift', ['detail' => $this->cashier->view($this->property->current(), $this->actor($request), $id)]);
    }

    public function open(Request $request): JsonResponse
    {
        $data = $request->validate(['opening_float_minor' => ['required', 'integer', 'min:0']]);

        return $this->json(['shift' => $this->cashier->open($this->property->current(), $this->actor($request), (int) $data['opening_float_minor'])], 201);
    }

    public function drop(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['amount_minor' => ['required', 'integer', 'min:1'], 'reference' => ['nullable', 'string', 'max:80'], 'note' => ['nullable', 'string', 'max:300']]);

        return $this->json(['shift' => $this->cashier->drop($this->property->current(), $this->actor($request), $id, (int) $data['amount_minor'], $data['reference'] ?? null, $data['note'] ?? null)]);
    }

    public function close(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['counted_cash_minor' => ['required', 'integer', 'min:0'], 'variance_reason' => ['nullable', 'string', 'max:300'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['shift' => $this->cashier->close($this->property->current(), $this->actor($request), $id, (int) $data['counted_cash_minor'], $data['variance_reason'] ?? null, (int) $data['lock_version'])]);
    }

    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate(['require_open_shift' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:300']]);

        return $this->json(['settings' => $this->cashier->updateSettings($this->property->current(), $this->actor($request), (bool) $data['require_open_shift'], (int) $data['lock_version'], $data['reason'])]);
    }

    private function maySettings(Request $request): bool
    {
        try {
            $this->cashier->settings($this->property->current(), $this->actor($request));

            return true;
        } catch (Refusal) {
            return false;
        }
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
