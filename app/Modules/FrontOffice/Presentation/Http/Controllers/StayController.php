<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\FrontOffice\Application\Stays\CheckInRequest;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Check-in, the guests in the house and check-out. Rules, permissions and privacy live in `StayService`. */
final readonly class StayController
{
    public function __construct(private StayService $stays, private ReservationService $reservations, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('front-office/pages/stays', ['stays' => $this->stays->inHouse($this->property->current(), $this->actor($request))]);
    }

    public function show(Request $request, string $id): Response
    {
        $property = $this->property->current();
        $stay = $this->stays->view($property, $this->actor($request), $id);

        return Inertia::render('front-office/pages/stay', [
            'stay' => $stay,
            'reservation' => $this->summary($this->reservations->find($property, $this->actor($request), $stay['reservation_id'])->toArray()),
        ]);
    }

    public function checkInForm(Request $request, string $id): Response
    {
        $property = $this->property->current();
        $actor = $this->actor($request);
        $reservation = $this->reservations->find($property, $actor, $id);

        return Inertia::render('front-office/pages/check-in', [
            'reservation' => $this->summary($reservation->toArray()),
            'rooms' => $this->stays->availableRooms($property, $actor, $id),
            'stay' => $this->stays->forReservation($property, $actor, $id),
        ]);
    }

    public function lookup(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['id_type' => ['required', 'string', 'max:12'], 'id_number' => ['required', 'string', 'max:40']]);

        return $this->json(['matches' => $this->stays->previousGuests($this->property->current(), $this->actor($request), $id, $data['id_type'], $data['id_number'])]);
    }

    public function checkIn(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'room_id' => ['required', 'string', 'size:26'], 'full_name' => ['required', 'string', 'max:150'], 'nationality' => ['required', 'string', 'size:2'],
            'id_type' => ['required', 'string', 'max:12'], 'id_number' => ['required', 'string', 'max:40'], 'id_valid_until' => ['nullable', 'string', 'size:10'],
            'visa_number' => ['nullable', 'string', 'max:40'], 'address' => ['required', 'string', 'max:500'],
            'adults' => ['required', 'integer', 'min:1', 'max:40'], 'children' => ['required', 'integer', 'min:0', 'max:40'],
        ]);

        $stay = $this->stays->checkIn(
            $this->property->current(),
            $this->actor($request),
            new CheckInRequest(
                $id, $data['room_id'], $data['full_name'], $data['nationality'], $data['id_type'], $data['id_number'], $data['id_valid_until'] ?? null,
                $data['visa_number'] ?? null, $data['address'], (int) $data['adults'], (int) $data['children'],
            ),
            IdempotencyKey::fromString((string) $request->header('Idempotency-Key')),
        );

        return $this->json(['stay' => $stay], 201);
    }

    public function attachPhoto(Request $request, string $id): JsonResponse
    {
        $request->validate(['photo' => ['required', 'file', 'max:5120']]);
        $upload = $request->file('photo');
        $file = $this->stays->attachIdPhoto($this->property->current(), $this->actor($request), $id, (string) $upload?->get(), $upload?->getClientOriginalName());

        return $this->json(['file_id' => $file->id], 201);
    }

    public function photo(Request $request, string $id): HttpResponse
    {
        $content = $this->stays->idPhoto($this->property->current(), $this->actor($request), $id);

        return response($content->contents, 200, [
            'Content-Type' => $content->file->mimeType,
            'Content-Disposition' => 'inline; filename="id-photo"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function checkOut(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['stay' => $this->stays->checkOut($this->property->current(), $this->actor($request), $id, (int) $data['lock_version'])]);
    }

    /**
     * @param  array<string, mixed>  $reservation
     * @return array<string, mixed>
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
