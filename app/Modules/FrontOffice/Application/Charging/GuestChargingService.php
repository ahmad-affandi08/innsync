<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Charging;

use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Stays\StayRepository;
use App\Shared\Domain\Tenancy\PropertyId;

final readonly class GuestChargingService implements GuestCharging
{
    public function __construct(private StayRepository $stays, private FolioService $folios) {}

    public function inHouseStayOfRoom(PropertyId $property, string $roomId): ?array
    {
        foreach ($this->stays->inHouse($property) as $stay) {
            if ($stay->roomId === strtolower($roomId)) {
                return ['stay_id' => $stay->id, 'reservation_id' => $stay->reservationId];
            }
        }

        return null;
    }

    public function charge(PropertyId $property, string $actorId, string $reservationId, string $scope, string $code, string $description, int $quotedMinor, string $source, string $sourceRef): array
    {
        $result = $this->folios->postGuestCharge($property, $actorId, $reservationId, $scope, $code, $description, $quotedMinor, $source, $sourceRef);

        return ['posting_id' => $result['posting']['id'], 'total_minor' => $result['posting']['total_minor'], 'currency' => $result['posting']['currency'], 'replayed' => $result['replayed']];
    }
}
