<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\Spa\SpaService;
use App\Modules\FrontOffice\Application\Spa\SpaTherapistService;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The spa: the board and the booking at the desk, the set-up and the report of the manager, and the therapist's phone. Every rule and permission lives in the services. */
final readonly class SpaController
{
    public function __construct(private SpaService $spa, private SpaTherapistService $therapist, private PropertyContext $property) {}

    public function index(Request $request): Response|RedirectResponse
    {
        // A therapist holds no desk permission: the phone page is theirs.
        if (! $this->spa->mayUseDesk($this->property->current(), $this->actor($request))) {
            return redirect('/front-office/spa/me');
        }

        $date = $request->query('date');

        return Inertia::render('front-office/pages/spa', ['overview' => $this->spa->overview($this->property->current(), $this->actor($request), is_string($date) ? $date : null)]);
    }

    public function manage(Request $request): Response
    {
        $from = $request->query('from');
        $to = $request->query('to');

        return Inertia::render('front-office/pages/spa-manage', [
            'overview' => $this->spa->overview($this->property->current(), $this->actor($request)),
            'report' => $this->spa->report($this->property->current(), $this->actor($request), is_string($from) ? $from : null, is_string($to) ? $to : null),
        ]);
    }

    public function mine(Request $request): Response
    {
        return Inertia::render('front-office/pages/spa-me', ['me' => $this->therapist->mine($this->property->current(), $this->actor($request))]);
    }

    public function board(Request $request): JsonResponse
    {
        $date = $request->query('date');

        return $this->json(['overview' => $this->spa->overview($this->property->current(), $this->actor($request), is_string($date) ? $date : null)]);
    }

    public function myState(Request $request): JsonResponse
    {
        return $this->json(['me' => $this->therapist->mine($this->property->current(), $this->actor($request))]);
    }

    public function addRoom(Request $request): JsonResponse
    {
        $d = $request->validate(['code' => ['required', 'string', 'max:12'], 'name' => ['required', 'string', 'max:60']]);
        $this->spa->addRoom($this->property->current(), $this->actor($request), $d['code'], $d['name']);

        return $this->json(['ok' => true], 201);
    }

    public function changeRoom(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:60'], 'is_active' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0']]);
        $this->spa->changeRoom($this->property->current(), $this->actor($request), $id, $d['name'], (bool) $d['is_active'], (int) $d['lock_version']);

        return $this->json(['ok' => true]);
    }

    public function addTreatment(Request $request): JsonResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:80'], 'minutes' => ['required', 'integer', 'min:5', 'max:480'], 'price_minor' => ['required', 'integer', 'min:1', 'max:100000000000']]);
        $this->spa->addTreatment($this->property->current(), $this->actor($request), $d['name'], (int) $d['minutes'], (int) $d['price_minor']);

        return $this->json(['ok' => true], 201);
    }

    public function changeTreatment(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:80'], 'minutes' => ['required', 'integer', 'min:5', 'max:480'], 'price_minor' => ['required', 'integer', 'min:1', 'max:100000000000'], 'is_active' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0']]);
        $this->spa->changeTreatment($this->property->current(), $this->actor($request), $id, $d['name'], (int) $d['minutes'], (int) $d['price_minor'], (bool) $d['is_active'], (int) $d['lock_version']);

        return $this->json(['ok' => true]);
    }

    public function addTherapist(Request $request): JsonResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:80'], 'user_id' => ['nullable', 'string', 'size:26'], 'commission_bp' => ['required', 'integer', 'min:0', 'max:10000']]);
        $this->spa->addTherapist($this->property->current(), $this->actor($request), $d['name'], $d['user_id'] ?? null, (int) $d['commission_bp']);

        return $this->json(['ok' => true], 201);
    }

    public function changeTherapist(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:80'], 'user_id' => ['nullable', 'string', 'size:26'], 'commission_bp' => ['required', 'integer', 'min:0', 'max:10000'], 'is_active' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0']]);
        $this->spa->changeTherapist($this->property->current(), $this->actor($request), $id, $d['name'], $d['user_id'] ?? null, (int) $d['commission_bp'], (bool) $d['is_active'], (int) $d['lock_version']);

        return $this->json(['ok' => true]);
    }

    public function book(Request $request): JsonResponse
    {
        $d = $request->validate([
            'room_id' => ['required', 'string', 'size:26'], 'treatment_id' => ['required', 'string', 'size:26'], 'therapist_id' => ['required', 'string', 'size:26'],
            'date' => ['required', 'date_format:Y-m-d'], 'time' => ['required', 'string', 'max:5'], 'guest_name' => ['required', 'string', 'max:120'], 'reservation_id' => ['nullable', 'string', 'size:26'],
            'waive_service_charge' => ['sometimes', 'boolean'], 'waive_tax' => ['sometimes', 'boolean'],
        ]);
        $made = $this->spa->book($this->property->current(), $this->actor($request), IdempotencyKey::fromString((string) $request->header('Idempotency-Key')), [
            'room_id' => $d['room_id'], 'treatment_id' => $d['treatment_id'], 'therapist_id' => $d['therapist_id'], 'date' => $d['date'], 'time' => $d['time'], 'guest_name' => $d['guest_name'],
            'reservation_id' => $d['reservation_id'] ?? null, 'waive_service_charge' => (bool) ($d['waive_service_charge'] ?? false), 'waive_tax' => (bool) ($d['waive_tax'] ?? false),
        ]);

        return $this->json($made, 201);
    }

    public function assign(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['therapist_id' => ['required', 'string', 'size:26'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['booking' => $this->spa->assign($this->property->current(), $this->actor($request), $id, $d['therapist_id'], (int) $d['lock_version'])]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:200'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['booking' => $this->spa->cancel($this->property->current(), $this->actor($request), $id, $d['reason'], (int) $d['lock_version'])]);
    }

    public function noShow(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['booking' => $this->spa->noShow($this->property->current(), $this->actor($request), $id, (int) $d['lock_version'])]);
    }

    public function pay(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['method' => ['required', 'string', 'max:8'], 'reference' => ['nullable', 'string', 'max:60'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['booking' => $this->spa->pay($this->property->current(), $this->actor($request), $id, $d['method'], $d['reference'] ?? null, (int) $d['lock_version'])]);
    }

    public function clockIn(Request $request): JsonResponse
    {
        $this->therapist->clockIn($this->property->current(), $this->actor($request));

        return $this->json(['ok' => true]);
    }

    public function clockOut(Request $request): JsonResponse
    {
        $this->therapist->clockOut($this->property->current(), $this->actor($request));

        return $this->json(['ok' => true]);
    }

    public function accept(Request $request, string $id): JsonResponse
    {
        return $this->json(['booking' => $this->therapist->accept($this->property->current(), $this->actor($request), $id, $this->lock($request))]);
    }

    public function decline(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['reason' => ['nullable', 'string', 'max:200'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['booking' => $this->therapist->decline($this->property->current(), $this->actor($request), $id, $d['reason'] ?? null, (int) $d['lock_version'])]);
    }

    public function start(Request $request, string $id): JsonResponse
    {
        return $this->json(['booking' => $this->therapist->start($this->property->current(), $this->actor($request), $id, $this->lock($request))]);
    }

    public function finish(Request $request, string $id): JsonResponse
    {
        return $this->json(['booking' => $this->therapist->finish($this->property->current(), $this->actor($request), $id, $this->lock($request))]);
    }

    private function lock(Request $request): int
    {
        return (int) $request->validate(['lock_version' => ['required', 'integer', 'min:0']])['lock_version'];
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
