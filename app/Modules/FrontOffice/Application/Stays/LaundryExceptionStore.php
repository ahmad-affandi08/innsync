<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Stays;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** What was agreed at check-out for the laundry a guest left in hand (FR-LDY-012): one immutable record per stay. */
interface LaundryExceptionStore
{
    /**
     * @param  list<string>  $orderIds
     * @return bool false when the stay already has one
     */
    public function add(PropertyId $property, string $id, string $stayId, string $reservationId, string $mode, string $reason, string $approvalId, array $orderIds, string $actorId, DateTimeImmutable $at): bool;

    /** @return array{id: string, stay_id: string, reservation_id: string, mode: string, reason: string, approval_id: string, order_ids: list<string>, created_at: string}|null */
    public function ofStay(PropertyId $property, string $stayId): ?array;

    /** The record of the stay of this reservation, whichever it is; a reservation has one stay. @return array{id: string, stay_id: string, reservation_id: string, mode: string, reason: string, approval_id: string, order_ids: list<string>, created_at: string}|null */
    public function ofReservation(PropertyId $property, string $reservationId): ?array;
}
