<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Presentation\Http\Controllers;

use App\Modules\GuestExperience\Application\GuestOrderService;
use App\Modules\GuestExperience\Application\GuestSessionService;
use App\Modules\GuestExperience\Presentation\Http\Middleware\ResolveGuestSession;
use App\Shared\Application\Errors\Refusal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

/** The pages and actions of a guest with a session: the menu, proving the stay, placing an order and following it. Every rule lives in the application services. */
final readonly class GuestMenuController
{
    public function __construct(private GuestOrderService $orders, private GuestSessionService $sessions) {}

    public function menu(Request $request): Response
    {
        return Inertia::render('guest/pages/menu', ['view' => $this->orders->menu($this->session($request))]);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['room_number' => ['required', 'string', 'max:20'], 'surname' => ['required', 'string', 'max:80']]);
        $session = $this->session($request);

        // Failed tries are counted for the room, not only for the session: a new cookie costs one request, so a count kept per session stops nobody (SEC-02).
        $room = 'guest-verify:'.$session['property']->toString().':'.preg_replace('/[^a-z0-9]/', '', mb_strtolower($data['room_number']));
        $max = (int) config('guest.verify_room_max_attempts');
        $window = (int) config('guest.verify_lock_minutes') * 60;

        if (RateLimiter::tooManyAttempts($room, $max)) {
            throw Refusal::stateConflict('Too many tries. Ask the front desk, or try again in a few minutes.');
        }

        try {
            $this->sessions->verify($session, $data['room_number'], $data['surname']);
        } catch (Refusal $refusal) {
            if ($refusal->status() === 422) {
                RateLimiter::hit($room, $window);
            }

            throw $refusal;
        }

        $fresh = $this->sessions->resolve((string) $request->cookie(ResolveGuestSession::COOKIE)) ?? $this->session($request);

        return response()->json($this->orders->menu($fresh))->header('Cache-Control', 'no-store');
    }

    public function order(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_key' => ['required', 'string', 'max:40'], 'lines' => ['required', 'array', 'min:1', 'max:60'], 'lines.*.item_id' => ['required', 'string', 'size:26'], 'lines.*.variant_id' => ['nullable', 'string', 'size:26'],
            'lines.*.modifier_ids' => ['nullable', 'array', 'max:30'], 'lines.*.modifier_ids.*' => ['string', 'size:26'], 'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:99'], 'lines.*.note' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:200'], 'payment' => ['required', 'string', 'max:6'],
        ]);

        $order = $this->orders->place($this->session($request), $data['client_key'], array_values(array_map(static fn (array $l): array => [
            'item_id' => $l['item_id'], 'variant_id' => $l['variant_id'] ?? null, 'modifier_ids' => array_values($l['modifier_ids'] ?? []), 'quantity' => (int) $l['quantity'], 'note' => $l['note'] ?? null,
        ], $data['lines'])), $data['note'] ?? null, $data['payment']);

        return response()->json($order, 201)->header('Cache-Control', 'no-store');
    }

    public function orders(Request $request): Response
    {
        return Inertia::render('guest/pages/orders', ['view' => $this->orders->orders($this->session($request))]);
    }

    /** @return array<string, mixed> */
    private function session(Request $request): array
    {
        /** @var array<string, mixed> $session */
        $session = $request->attributes->get('guest.session');

        return $session;
    }
}
