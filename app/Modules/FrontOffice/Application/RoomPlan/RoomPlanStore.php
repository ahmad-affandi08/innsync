<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\RoomPlan;

use App\Shared\Domain\Tenancy\PropertyId;

interface RoomPlanStore
{
    /** @return array{id: string, status: string, room_type_id: string, arrival: string, departure: string}|null */
    public function reservation(PropertyId $property, string $reservationId): ?array;

    public function plannedRoom(PropertyId $property, string $reservationId): ?string;

    /** Why the room cannot be planned for these nights: another plan or reservation on it, or a block; null when it is free. */
    public function conflict(PropertyId $property, string $roomId, string $arrival, string $departure, string $exceptReservationId): ?string;

    public function set(PropertyId $property, string $reservationId, string $roomId, string $actorId): void;

    public function clear(PropertyId $property, string $reservationId): bool;
}
