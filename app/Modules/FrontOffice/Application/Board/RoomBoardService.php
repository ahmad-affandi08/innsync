<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Board;

use App\Modules\FrontOffice\Application\Inventory\RoomBlockRepository;
use App\Modules\FrontOffice\Application\Requests\GuestRequestRepository;
use App\Modules\FrontOffice\Application\Reservations\ReservationRepository;
use App\Modules\FrontOffice\Application\Stays\StayRepository;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\Housekeeping\Application\RoomReadiness;
use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The room board of the front desk (FR-FO-001): every active room with its occupancy, its housekeeping state and whether it is
 * out of order or out of service today, side by side and never merged (BR-008). It also lists the arrivals still to check in,
 * so a vacant room can be given to a guest from the same screen. It reads; it changes nothing.
 */
final readonly class RoomBoardService
{
    public function __construct(
        private RoomCatalogReader $rooms,
        private StayRepository $stays,
        private ReservationRepository $reservations,
        private RoomBlockRepository $blocks,
        private RoomReadiness $readiness,
        private GuestRequestRepository $requests,
        private BusinessDateProvider $businessDate,
        private PermissionChecker $permissions,
        private PropertyContext $property,
    ) {}

    /**
     * @return array{business_date: string, rooms: list<array<string, mixed>>, arrivals: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function board(PropertyId $property, string $actorId): array
    {
        $this->authorize($property, $actorId);
        $today = $this->businessDate->current($property);
        $statuses = $this->readiness->statuses($property);
        $types = [];

        foreach ($this->rooms->activeTypes($property) as $type) {
            $types[$type->id] = $type->code;
        }

        $occupied = [];

        foreach ($this->stays->inHouse($property) as $stay) {
            $occupied[$stay->roomId] = $stay;
        }

        $openRequests = $this->requests->openCountsByRoom($property);
        $blockedRooms = $this->blocks->blockedRooms($property, $today->toString(), $today->toString());
        $rows = [];
        $counts = ['occupied' => 0, 'vacant_ready' => 0, 'vacant_not_ready' => 0, 'blocked' => 0];

        foreach ($this->rooms->activeRooms($property) as $room) {
            $stay = $occupied[$room->id] ?? null;
            $blocked = isset($blockedRooms[$room->id]);
            $hk = $statuses[$room->id] ?? 'ready';
            $counts[match (true) {
                $stay !== null => 'occupied',
                $blocked => 'blocked',
                $hk === 'ready' => 'vacant_ready',
                default => 'vacant_not_ready',
            }]++;

            $rows[] = [
                'room_id' => $room->id,
                'number' => $room->number,
                'floor' => $room->floor,
                'type' => $types[$room->roomTypeId] ?? '',
                'room_type_id' => $room->roomTypeId,
                'housekeeping' => $hk,
                'blocked' => $blocked,
                'stay_id' => $stay?->id,
                'expected_departure' => $stay?->expectedDeparture->toString(),
                'open_requests' => $openRequests[$room->id] ?? 0,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strnatcmp((string) $a['number'], (string) $b['number']));

        $arrivals = [];

        foreach (['confirmed', 'guaranteed'] as $status) {
            foreach ($this->reservations->search($property, ['status' => $status, 'arrival_to' => $today->toString()], 100, 0) as $reservation) {
                $arrivals[] = [
                    'reservation_id' => $reservation->id,
                    'number' => $reservation->number,
                    'guest_name' => $reservation->guestName,
                    'room_type_id' => $reservation->roomTypeId,
                    'type' => $types[$reservation->roomTypeId] ?? '',
                    'arrival' => $reservation->stay->arrival->toString(),
                    'departure' => $reservation->stay->departure->toString(),
                ];
            }
        }

        usort($arrivals, static fn (array $a, array $b): int => [$a['arrival'], $a['number']] <=> [$b['arrival'], $b['number']]);

        return ['business_date' => $today->toString(), 'rooms' => $rows, 'arrivals' => $arrivals, 'counts' => $counts];
    }

    private function authorize(PropertyId $property, string $actorId): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }

        foreach ([StayService::VIEW_PERMISSION, StayService::MANAGE_PERMISSION] as $permission) {
            if ($this->permissions->allowsInProperty($actorId, $permission, $property)) {
                return;
            }
        }

        throw Refusal::forbidden('This person may not see the room board.');
    }
}
