<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\Companies\CompanyService;
use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Groups\GroupBookingService;
use App\Modules\FrontOffice\Application\Reservations\RateChangeService;
use App\Modules\FrontOffice\Application\Reservations\ReservationRequest;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Reservation screens and actions. Every rule lives in `ReservationService`; this maps input and output. */
final readonly class ReservationController
{
    public function __construct(private ReservationService $reservations, private FolioService $folios, private RateChangeService $rates, private CompanyService $companies, private GroupBookingService $groups, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $property = $this->property->current();
        $actor = $this->actor($request);
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:20'], 'query' => ['nullable', 'string', 'max:100'],
            'arrival_from' => ['nullable', 'string', 'size:10'], 'arrival_to' => ['nullable', 'string', 'size:10'],
        ]);

        return Inertia::render('front-office/pages/reservations', [
            'reservations' => array_map(fn ($r): array => $this->summary($r->toArray()), $this->reservations->search($property, $actor, array_filter($filters, static fn ($v): bool => $v !== null && $v !== ''), 100)),
            'lookups' => $this->reservations->lookups($property, $actor),
            'filters' => (object) array_filter($filters, static fn ($v): bool => $v !== null && $v !== ''),
        ]);
    }

    /** The few reservations that match what was typed in the search box of the header: by number, guest name or phone. No contact details leave. */
    public function find(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);
        $found = $this->reservations->search($this->property->current(), $this->actor($request), ['query' => trim($data['q'])], 8);

        return response()->json(['results' => array_map(static fn ($r): array => array_intersect_key($r->toArray(), array_flip(['id', 'number', 'status', 'guest_name', 'arrival', 'departure'])), $found)])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $id): Response
    {
        $property = $this->property->current();
        $actor = $this->actor($request);

        return Inertia::render('front-office/pages/reservation', [
            'reservation' => $this->reservations->find($property, $actor, $id)->toArray(),
            'lookups' => $this->reservations->lookups($property, $actor),
            'folios' => $this->folioSummaries($property, $actor, $id),
            'policy' => $this->reservations->policyView($property, $actor, $id),
            'rates' => $this->rates->overview($property, $actor, $id),
            'billing' => $this->companies->forReservation($property, $actor, $id),
            'group' => $this->groups->forReservation($property, $actor, $id),
        ]);
    }

    public function linkCompany(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['company_id' => ['required', 'string', 'size:26']]);

        return response()->json(['billing' => $this->companies->link($this->property->current(), $this->actor($request), $id, $data['company_id'])])->header('Cache-Control', 'no-store');
    }

    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate(['rate_plan_id' => ['required', 'string', 'size:26'], 'room_type_id' => ['required', 'string', 'size:26'], 'arrival' => ['required', 'string', 'size:10'], 'departure' => ['required', 'string', 'size:10']]);

        return $this->json(['quote' => $this->reservations->quote($this->property->current(), $this->actor($request), $data['rate_plan_id'], $data['room_type_id'], $data['arrival'], $data['departure'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source' => ['required', 'string', 'max:20'], 'guest_name' => ['required', 'string', 'max:150'], 'guest_phone' => ['nullable', 'string', 'max:30'],
            'guest_email' => ['nullable', 'string', 'max:190'], 'arrival' => ['required', 'string', 'size:10'], 'departure' => ['required', 'string', 'size:10'],
            'adults' => ['required', 'integer', 'min:1', 'max:40'], 'children' => ['required', 'integer', 'min:0', 'max:40'],
            'room_type_id' => ['required', 'string', 'size:26'], 'rate_plan_id' => ['required', 'string', 'size:26'], 'notes' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', 'string', 'max:20'], 'acknowledge_oversell' => ['nullable', 'boolean'], 'oversell_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $reservation = $this->reservations->create(
            $this->property->current(),
            $this->actor($request),
            new ReservationRequest(
                $data['source'], $data['guest_name'], $data['guest_phone'] ?? null, $data['guest_email'] ?? null, $data['arrival'], $data['departure'], (int) $data['adults'], (int) $data['children'],
                $data['room_type_id'], $data['rate_plan_id'], $data['notes'] ?? null, $data['status'], (bool) ($data['acknowledge_oversell'] ?? false), $data['oversell_reason'] ?? null,
            ),
            IdempotencyKey::fromString((string) $request->header('Idempotency-Key')),
        );

        return $this->json(['reservation' => $this->summary($reservation->toArray())], 201);
    }

    public function confirm(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['reservation' => $this->summary($this->reservations->confirm($this->property->current(), $this->actor($request), $id, (int) $data['lock_version'])->toArray())]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:500'], 'waive_penalty' => ['nullable', 'boolean']]);

        return $this->json(['reservation' => $this->summary($this->reservations->cancel($this->property->current(), $this->actor($request), $id, $data['reason'], (int) $data['lock_version'], (bool) ($data['waive_penalty'] ?? false))->toArray())]);
    }

    public function noShow(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:500'], 'waive_penalty' => ['nullable', 'boolean']]);

        return $this->json(['reservation' => $this->summary($this->reservations->noShow($this->property->current(), $this->actor($request), $id, $data['reason'], (int) $data['lock_version'], (bool) ($data['waive_penalty'] ?? false))->toArray())]);
    }

    public function penalty(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['kind' => ['required', 'string', 'max:10']]);

        return $this->json(['penalty' => $this->reservations->penaltyPreview($this->property->current(), $this->actor($request), $id, $data['kind'])]);
    }

    public function guarantee(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0'], 'reason' => ['nullable', 'string', 'max:500']]);

        return $this->json(['reservation' => $this->summary($this->reservations->guarantee($this->property->current(), $this->actor($request), $id, (int) $data['lock_version'], $data['reason'] ?? null)->toArray())]);
    }

    /**
     * The folios of a reservation without their lines. A person who may not see folios simply gets none.
     *
     * @return list<array<string, mixed>>
     */
    private function folioSummaries(mixed $property, string $actor, string $reservationId): array
    {
        try {
            return array_map(static function (array $folio): array {
                unset($folio['postings']);

                return $folio;
            }, $this->folios->forReservation($property, $actor, $reservationId));
        } catch (Refusal) {
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $reservation
     * @return array<string, mixed> without the price snapshot, which only the detail screen needs
     */
    private function summary(array $reservation): array
    {
        unset($reservation['price_snapshot'], $reservation['guest_phone'], $reservation['guest_email']);

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
