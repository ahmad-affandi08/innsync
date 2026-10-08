<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\RoomPlan;

use App\Modules\FrontOffice\Application\FrontDeskAccess;
use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The room a booking that has not arrived yet is planned for. It is a plan and nothing more: it takes nothing from availability, does not stop another booking, and
 * the room is still chosen at check-in, where the planned room is simply offered first. A plan is refused when the room is of another type, is taken
 * on those nights by another plan or a stay, or is out of order.
 */
final readonly class RoomPlanService
{
    private const PLANNABLE = ['tentative', 'confirmed', 'guaranteed'];

    public function __construct(private RoomPlanStore $store, private RoomCatalogReader $rooms, private FrontDeskAccess $access, private TransactionRunner $transactions, private AuditTrail $audit) {}

    public function plannedRoom(PropertyId $property, string $actorId, string $reservationId): ?string
    {
        $this->access->read($property, $actorId);

        return $this->store->plannedRoom($property, strtolower($reservationId));
    }

    /** A null room clears the plan. */
    public function plan(PropertyId $property, string $actorId, string $reservationId, ?string $roomId): void
    {
        $this->access->write($property, $actorId);
        $reservation = $this->store->reservation($property, strtolower($reservationId)) ?? throw Refusal::notFound('Reservation not found.');

        if (! in_array($reservation['status'], self::PLANNABLE, true)) {
            throw Refusal::stateConflict('Only a booking that has not arrived yet can be planned for a room.');
        }

        $before = $this->store->plannedRoom($property, $reservation['id']);

        if ($roomId === null || $roomId === '') {
            $this->transactions->run(function () use ($property, $actorId, $reservation, $before): void {
                if ($this->store->clear($property, $reservation['id'])) {
                    $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'room_plan.cleared', 'reservation', $reservation['id'], ['room_id' => $before], null));
                }
            });

            return;
        }

        $roomId = strtolower($roomId);
        $room = $this->rooms->room($property, $roomId);

        if ($room === null || ! $room->isActive || $room->roomTypeId !== $reservation['room_type_id']) {
            throw Refusal::invalid('Choose an active room of the booked room type.', ['room_id']);
        }

        $conflict = $this->store->conflict($property, $roomId, $reservation['arrival'], $reservation['departure'], $reservation['id']);

        if ($conflict !== null) {
            throw Refusal::stateConflict($conflict);
        }

        $this->transactions->run(function () use ($property, $actorId, $reservation, $roomId, $before): void {
            $this->store->set($property, $reservation['id'], $roomId, strtolower($actorId));
            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'room_plan.set', 'reservation', $reservation['id'], $before === null ? null : ['room_id' => $before], ['room_id' => $roomId]));
        });
    }
}
