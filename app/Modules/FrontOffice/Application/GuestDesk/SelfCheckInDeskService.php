<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\GuestDesk;

use App\Modules\FrontOffice\Application\Folios\FolioRepository;
use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Reservations\DepositLedger;
use App\Modules\FrontOffice\Application\Reservations\ReservationRepository;
use App\Modules\FrontOffice\Application\Stays\CheckInRequest;
use App\Modules\FrontOffice\Application\Stays\RegistrationCardService;
use App\Modules\FrontOffice\Application\Stays\StayRepository;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\FrontOffice\Domain\Reservations\Reservation;
use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Domain\Tenancy\PropertyId;

/** Front office for the guest self check-in (see `SelfCheckInDesk`). */
final readonly class SelfCheckInDeskService implements SelfCheckInDesk
{
    public function __construct(
        private ReservationRepository $reservations,
        private StayRepository $stays,
        private StayService $stayService,
        private RegistrationCardService $cards,
        private RoomCatalogReader $rooms,
        private DepositLedger $deposits,
        private FolioRepository $folioStore,
        private FolioService $folios,
    ) {}

    public function arrival(PropertyId $property, string $reservationId): ?array
    {
        $reservation = $this->reservations->find($property, strtolower($reservationId));

        return $reservation === null ? null : $this->describe($property, $reservation);
    }

    public function arrivalByNumber(PropertyId $property, string $number): ?array
    {
        $number = trim($number);

        if ($number === '') {
            return null;
        }

        foreach ($this->reservations->search($property, ['query' => $number], 20, 0) as $reservation) {
            if (strcasecmp($reservation->number, $number) === 0) {
                return $this->describe($property, $reservation);
            }
        }

        return null;
    }

    public function arrivals(PropertyId $property, string $from, string $to): array
    {
        $rows = [];

        foreach (['confirmed', 'guaranteed'] as $status) {
            foreach ($this->reservations->search($property, ['status' => $status, 'arrival_from' => $from, 'arrival_to' => $to], 200, 0) as $reservation) {
                $d = $this->describe($property, $reservation);
                $rows[] = ['reservation_id' => $d['reservation_id'], 'number' => $d['number'], 'status' => $d['status'], 'guest_name' => $d['guest_name'], 'arrival' => $d['arrival'], 'departure' => $d['departure'], 'nights' => $d['nights'], 'adults' => $d['adults'], 'children' => $d['children'], 'room_type' => $d['room_type'], 'has_stay' => $d['has_stay']];
            }
        }

        usort($rows, static fn (array $a, array $b): int => [$a['arrival'], $a['number']] <=> [$b['arrival'], $b['number']]);

        return $rows;
    }

    public function rooms(PropertyId $property, string $actorId, string $reservationId): array
    {
        return $this->stayService->availableRooms($property, $actorId, $reservationId);
    }

    public function checkIn(PropertyId $property, string $actorId, string $reservationId, string $roomId, array $guest, ?string $photo, ?string $photoName, ?string $signaturePng, string $key): array
    {
        $stay = $this->stayService->checkIn($property, $actorId, new CheckInRequest(
            strtolower($reservationId), strtolower($roomId), $guest['full_name'], $guest['nationality'], $guest['id_type'], $guest['id_number'], $guest['id_valid_until'], $guest['visa_number'], $guest['address'], $guest['adults'], $guest['children'],
        ), IdempotencyKey::fromString($key));

        if ($photo !== null && $photo !== '' && ($stay['has_id_photo'] ?? false) !== true) {
            $this->stayService->attachIdPhoto($property, $actorId, (string) $stay['id'], $photo, $photoName);
        }

        if ($signaturePng !== null && $signaturePng !== '') {
            try {
                $this->cards->sign($property, $actorId, (string) $stay['id'], $signaturePng);
            } catch (Refusal $e) {
                // A retry after the card was signed is not an error.
                if ($e->status() !== 409) {
                    throw $e;
                }
            }
        }

        return ['stay_id' => (string) $stay['id'], 'room_number' => $stay['room_number'] ?? null, 'warnings' => array_values(array_map('strval', $stay['warnings'] ?? []))];
    }

    public function receiveDeposit(PropertyId $property, string $actorId, string $reservationId, int $amountMinor, ?string $reference, string $sourceRef): void
    {
        $folioId = null;

        foreach ($this->folioStore->byReservation($property, strtolower($reservationId)) as $folio) {
            if (! $folio->isClosed) {
                $folioId = $folio->id;

                break;
            }
        }

        // A booking that has not arrived may have no folio yet; the deposit is the first thing on it, and check-in will use it.
        $folioId ??= (string) $this->folios->open($property, $actorId, strtolower($reservationId))['id'];
        $this->folios->pay($property, $actorId, $folioId, 'qris', $amountMinor, $reference, 'deposit', $sourceRef);
    }

    /** @return array<string, mixed> */
    private function describe(PropertyId $property, Reservation $r): array
    {
        $type = $this->rooms->type($property, $r->roomTypeId);
        $a = $r->toArray();

        return [
            'reservation_id' => $r->id, 'number' => $r->number, 'status' => $r->status->value, 'guest_name' => $r->guestName, 'arrival' => $a['arrival'], 'departure' => $a['departure'], 'nights' => $a['nights'],
            'adults' => $r->adults, 'children' => $r->children, 'max_adults' => $type?->maxAdults, 'max_children' => $type?->maxChildren, 'room_type' => $type?->name, 'currency' => $a['currency'],
            'deposit_required_minor' => $r->depositRequiredMinor, 'deposit_held_minor' => $this->deposits->heldMinor($property, $r->id), 'has_stay' => $this->stays->findByReservation($property, $r->id) !== null,
        ];
    }
}
