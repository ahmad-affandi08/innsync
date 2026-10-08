<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\TapeChart;

use App\Shared\Domain\Tenancy\PropertyId;

interface TapeChartReader
{
    /**
     * Reservations that hold at least one night in [from, to), not cancelled and not no-show.
     *
     * @return list<array{id: string, number: string, status: string, guest_name: string, room_id: string|null, planned_room_id: string|null, room_type_id: string, arrival: string, departure: string}>
     */
    public function reservations(PropertyId $property, string $from, string $to): array;

    /**
     * Rooms taken out of sale that overlap [from, to) and have not been released.
     *
     * @return list<array{room_id: string, kind: string, start: string, end: string, reason: string}>
     */
    public function blocks(PropertyId $property, string $from, string $to): array;
}
