<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Charging;

use App\Modules\FrontOffice\Application\Folios\FolioRepository;
use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Folios\LateChargeService;
use App\Modules\FrontOffice\Application\Reservations\ReservationRepository;
use App\Modules\FrontOffice\Application\Stays\LaundryExceptionStore;
use App\Modules\FrontOffice\Application\Stays\StayRepository;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Domain\Tenancy\PropertyId;

final readonly class GuestChargingService implements GuestCharging
{
    public function __construct(private StayRepository $stays, private FolioService $folios, private ReservationRepository $reservations, private LateChargeService $lateCharges, private FolioRepository $folioStore, private LaundryExceptionStore $exceptions) {}

    public function inHouseStayOfRoom(PropertyId $property, string $roomId): ?array
    {
        foreach ($this->stays->inHouse($property) as $stay) {
            if ($stay->roomId === strtolower($roomId)) {
                return ['stay_id' => $stay->id, 'reservation_id' => $stay->reservationId, 'guest_name' => $this->reservations->find($property, $stay->reservationId)?->guestName ?? ''];
            }
        }

        return null;
    }

    public function charge(PropertyId $property, string $actorId, string $reservationId, string $scope, string $code, string $description, int $quotedMinor, string $source, string $sourceRef): array
    {
        $result = $this->folios->postGuestCharge($property, $actorId, $reservationId, $scope, $code, $description, $quotedMinor, $source, $sourceRef);

        return ['posting_id' => $result['posting']['id'], 'total_minor' => $result['posting']['total_minor'], 'currency' => $result['posting']['currency'], 'replayed' => $result['replayed']];
    }

    public function chargeLate(PropertyId $property, string $actorId, string $reservationId, string $scope, string $code, string $description, int $quotedMinor, string $source, string $sourceRef, string $reason): array
    {
        $reservationId = strtolower($reservationId);
        $exception = $this->exceptions->ofReservation($property, $reservationId);

        if ($exception === null || $exception['mode'] !== 'late_charge') {
            throw Refusal::stateConflict('No late charge was approved for this guest at check-out.');
        }

        $origin = null;

        foreach ($this->folioStore->byReservation($property, $reservationId) as $folio) {
            if ($folio->originFolioId === null) {
                $origin = $folio;

                break;
            }
        }

        if ($origin === null) {
            throw Refusal::notFound('This guest has no folio.');
        }

        $result = $this->lateCharges->postAuthorized($property, $actorId, $origin->id, strtoupper($code), $description, $quotedMinor, false, $reason, $sourceRef, $scope);

        return ['posting_id' => $result['posting']['id'], 'total_minor' => $result['posting']['total_minor'], 'currency' => $result['posting']['currency'], 'replayed' => $result['replayed']];
    }
}
