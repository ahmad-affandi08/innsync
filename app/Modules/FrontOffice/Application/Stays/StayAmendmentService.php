<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Stays;

use App\Modules\FrontOffice\Application\BookingRefused;
use App\Modules\FrontOffice\Application\Inventory\AvailabilityService;
use App\Modules\FrontOffice\Application\Inventory\InventoryRepository;
use App\Modules\FrontOffice\Application\Inventory\RoomBlockRepository;
use App\Modules\FrontOffice\Application\Reservations\ReservationRepository;
use App\Modules\FrontOffice\Domain\Reservations\Reservation;
use App\Modules\FrontOffice\Domain\Stays\Stay;
use App\Modules\Housekeeping\Application\RoomHandover;
use App\Modules\Housekeeping\Application\RoomReadiness;
use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Modules\Property\Application\Rates\RateQuoter;
use App\Modules\Property\Application\Rates\StayQuote;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\StayDates;
use InvalidArgumentException;

/**
 * Changes to a guest who is already in the house (FR-FO-018, FR-FO-019): moving them to another room and extending the stay.
 *
 * A move keeps the folio with the guest (it belongs to the reservation, so the whole balance goes with them), keeps the price
 * that was agreed (re-pricing a move to another room type is a decision for a person, not a side effect), records who moved
 * whom and why, tells housekeeping the old room is vacated, and, when the new room is of another type, moves the inventory of
 * the remaining nights. An extension prices only the added nights, with today's rates, as a new fact; the booked price is
 * never touched (BR-002); the added nights are charged by night audit like any other. Shortening a stay needs no action: a guest
 * who leaves early checks out, and nights after the departure are never charged.
 */
final readonly class StayAmendmentService
{
    /** Violations that make an extension impossible. The arrival and stay-length rules belong to the original booking. */
    private const BLOCKING = ['no_price', 'stop_sell', 'price_not_rounded', 'charges_not_configured', 'rate_plan_unavailable', 'room_type_unavailable'];

    public function __construct(
        private StayRepository $stays,
        private ReservationRepository $reservations,
        private InventoryRepository $inventory,
        private AvailabilityService $availability,
        private RoomBlockRepository $blocks,
        private RoomCatalogReader $rooms,
        private RoomReadiness $readiness,
        private RoomHandover $handover,
        private RateQuoter $quoter,
        private BusinessDateProvider $businessDate,
        private PropertySettingsService $settings,
        private PermissionChecker $permissions,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    /**
     * Rooms the guest can be moved to: active, free, ready and not blocked for the rest of the stay, with their type.
     *
     * @return list<array{id: string, number: string, floor: ?string, type: string, same_type: bool}>
     */
    public function moveOptions(PropertyId $property, string $actorId, string $stayId): array
    {
        $this->authorize($property, $actorId);
        [$stay, $reservation] = $this->load($property, $stayId);
        $today = $this->businessDate->current($property);
        $current = $this->rooms->room($property, $stay->roomId);
        $types = [];

        foreach ($this->rooms->activeTypes($property) as $type) {
            $types[$type->id] = $type->code;
        }

        $result = [];

        foreach ($this->rooms->activeRooms($property) as $room) {
            if ($room->id === $stay->roomId || $this->stays->roomIsOccupied($property, $room->id) || ! $this->readiness->isReady($property, $room->id)
                || $this->blockedFor($property, $room->id, $today, $stay->expectedDeparture)) {
                continue;
            }

            $result[] = ['id' => $room->id, 'number' => $room->number, 'floor' => $room->floor, 'type' => $types[$room->roomTypeId] ?? '', 'same_type' => $room->roomTypeId === $current?->roomTypeId];
        }

        usort($result, static fn (array $a, array $b): int => strnatcmp($a['number'], $b['number']));

        return $result;
    }

    /**
     * @return array<string, mixed> the stay after the move
     */
    public function moveRoom(PropertyId $property, string $actorId, string $stayId, string $toRoomId, string $reason, int $expectedLockVersion): array
    {
        $this->authorize($property, $actorId);
        $actor = strtolower($actorId);
        $reason = $this->reason($reason);
        $toRoomId = strtolower($toRoomId);

        $this->transactions->run(function () use ($property, $actor, $stayId, $toRoomId, $reason, $expectedLockVersion): void {
            [$stay, $reservation] = $this->load($property, $stayId);
            $today = $this->businessDate->current($property);
            $from = $this->rooms->room($property, $stay->roomId) ?? throw Refusal::notFound('Room not found.');
            $to = $this->rooms->room($property, $toRoomId);

            if ($to === null || ! $to->isActive || $to->id === $from->id) {
                throw Refusal::invalid('Choose another active room.', ['room_id']);
            }

            // Both room types are locked, always in the same order, so two moves can not wait for each other.
            $typeIds = array_values(array_unique([$from->roomTypeId, $to->roomTypeId]));
            sort($typeIds);

            foreach ($typeIds as $typeId) {
                $this->inventory->lockRoomType($property, $typeId);
            }

            $stay = $this->stays->find($property, $stay->id) ?? throw Refusal::notFound('Stay not found.');

            if ($stay->lockVersion !== $expectedLockVersion) {
                throw Refusal::stateConflict('This stay changed after you opened it.');
            }

            if (! $stay->isInHouse() || $stay->roomId !== $from->id) {
                throw Refusal::stateConflict('This guest is no longer in that room.');
            }

            if ($this->stays->roomIsOccupied($property, $to->id) || $this->blockedFor($property, $to->id, $today, $stay->expectedDeparture)) {
                throw Refusal::stateConflict('That room is occupied or out of service.');
            }

            if (! $this->readiness->isReady($property, $to->id)) {
                throw Refusal::stateConflict('Housekeeping has not made that room ready.');
            }

            if ($to->roomTypeId !== $from->roomTypeId) {
                $remaining = new StayDates($today, $stay->expectedDeparture);

                foreach ($this->availability->forStay($property, $to->roomTypeId, $remaining) as $night => $availability) {
                    if (! $availability->canSellOne() || $availability->needsOverbooking()) {
                        throw BookingRefused::noAvailability($night);
                    }
                }

                $this->reservations->shiftNights($property, $reservation->id, $today, $to->roomTypeId);
            }

            if (! $this->stays->moveRoom($property, $stay->id, $to->id, $expectedLockVersion)) {
                throw Refusal::stateConflict('This stay changed, or that room was taken, while you were moving the guest.');
            }

            $now = $this->clock->nowUtc();
            $moveId = $this->ids->next();
            $this->stays->addRoomMove($property, $moveId, $stay->id, $from->id, $to->id, $from->roomTypeId, $to->roomTypeId, $today, $reason, $actor, $now);
            $this->reservations->changeRoom($property, $reservation->id, $to->id, $now);
            $this->handover->vacated($property, $from->id, 'move:'.$moveId, $actor);
            $this->audit->record(new AuditEntry(
                $property->toString(), $actor, 'stay.room_moved', 'stay', $stay->id,
                ['room_id' => $from->id, 'room_type_id' => $from->roomTypeId], ['room_id' => $to->id, 'room_type_id' => $to->roomTypeId, 'business_date' => $today->toString()], $reason,
            ));
            $this->outbox->publish(new OutboxEvent($property, 'frontoffice.stay.room_moved', $stay->id, 1, [
                'stay_id' => $stay->id, 'reservation_id' => $reservation->id, 'from_room_id' => $from->id, 'to_room_id' => $to->id, 'business_date' => $today->toString(), 'actor_id' => $actor,
            ]));
        });

        return $this->describe($property, $stayId);
    }

    /**
     * The price of staying until `$newDeparture`, night by night, for the added nights only, and whether it can be sold.
     *
     * @return array<string, mixed>
     */
    public function quoteExtension(PropertyId $property, string $actorId, string $stayId, string $newDeparture): array
    {
        $this->authorize($property, $actorId);
        [$stay, $reservation] = $this->load($property, $stayId);
        $current = $this->rooms->room($property, $stay->roomId) ?? throw Refusal::notFound('Room not found.');
        $plan = $this->plan($property, $stay, $current->roomTypeId, $reservation->ratePlanId, $this->newDeparture($property, $stay, $newDeparture), $reservation->roomTypeId);

        return [
            'old_departure' => $stay->expectedDeparture->toString(), 'new_departure' => $plan['stay']->departure->toString(), 'currency' => $plan['quote']->currency,
            'nights' => $plan['rows'], 'total_minor' => $plan['quote']->total()->amountMinor, 'bookable' => $plan['blockers'] === [], 'violations' => $plan['blockers'],
            'availability' => $plan['sold_out'],
        ];
    }

    /** @return array<string, mixed> the stay after the extension */
    public function extend(PropertyId $property, string $actorId, string $stayId, string $newDeparture, string $reason, int $expectedLockVersion): array
    {
        $this->authorize($property, $actorId);
        $actor = strtolower($actorId);
        $reason = $this->reason($reason);

        $this->transactions->run(function () use ($property, $actor, $stayId, $newDeparture, $reason, $expectedLockVersion): void {
            [$stay, $reservation] = $this->load($property, $stayId);
            $current = $this->rooms->room($property, $stay->roomId) ?? throw Refusal::notFound('Room not found.');
            $this->inventory->lockRoomType($property, $current->roomTypeId);
            $stay = $this->stays->find($property, $stay->id) ?? throw Refusal::notFound('Stay not found.');

            if ($stay->lockVersion !== $expectedLockVersion) {
                throw Refusal::stateConflict('This stay changed after you opened it.');
            }

            if (! $stay->isInHouse()) {
                throw Refusal::stateConflict('Only a guest who is in the house can stay longer.');
            }

            $departure = $this->newDeparture($property, $stay, $newDeparture);
            $plan = $this->plan($property, $stay, $current->roomTypeId, $reservation->ratePlanId, $departure, $reservation->roomTypeId);

            if ($plan['blockers'] !== []) {
                throw BookingRefused::notBookable($plan['blockers']);
            }

            if ($plan['sold_out'] !== []) {
                throw BookingRefused::noAvailability($plan['sold_out'][0]);
            }

            if ($this->blockedFor($property, $stay->roomId, $stay->expectedDeparture, $departure)) {
                throw Refusal::stateConflict('The guest\'s room is out of order or service for those nights; move the guest first.');
            }

            $nights = array_map(static fn (array $n): array => [
                'date' => $n['date'], 'quoted_minor' => $n['quoted_minor'], 'base_minor' => $n['base_minor'], 'service_charge_minor' => $n['service_charge_minor'],
                'tax_minor' => $n['tax_minor'], 'total_minor' => $n['total_minor'], 'scheme' => $n['scheme'],
            ], $plan['rows']);
            $now = $this->clock->nowUtc();
            $this->reservations->addExtension($property, $this->ids->next(), $reservation->id, $stay->expectedDeparture, $departure, $current->roomTypeId, $nights, $reason, $this->businessDate->current($property), $actor, $now);

            if (! $this->stays->extend($property, $stay->id, $departure, $expectedLockVersion)) {
                throw Refusal::stateConflict('This stay changed after you opened it.');
            }

            $this->audit->record(new AuditEntry(
                $property->toString(), $actor, 'stay.extended', 'stay', $stay->id,
                ['expected_departure' => $stay->expectedDeparture->toString()], ['expected_departure' => $departure->toString(), 'nights_added' => count($nights), 'added_total_minor' => $plan['quote']->total()->amountMinor], $reason,
            ));
            $this->outbox->publish(new OutboxEvent($property, 'frontoffice.stay.extended', $stay->id, 1, [
                'stay_id' => $stay->id, 'reservation_id' => $reservation->id, 'old_departure' => $stay->expectedDeparture->toString(), 'new_departure' => $departure->toString(), 'actor_id' => $actor,
            ]));
        });

        return $this->describe($property, $stayId);
    }

    /** @return list<array<string, mixed>> the moves of a stay, oldest first */
    public function moves(PropertyId $property, string $actorId, string $stayId): array
    {
        $this->authorize($property, $actorId);

        return $this->stays->roomMoves($property, strtolower($stayId));
    }

    // ---- internals ----

    /**
     * @return array{stay: StayDates, quote: StayQuote, rows: list<array<string, mixed>>, blockers: list<string>, sold_out: list<string>}
     */
    private function plan(PropertyId $property, Stay $stay, string $typeForAvailability, string $ratePlanId, BusinessDate $newDeparture, ?string $priceType = null): array
    {
        $added = new StayDates($stay->expectedDeparture, $newDeparture);
        $quote = $this->quoter->quote($property, $ratePlanId, $priceType ?? $typeForAvailability, $added);
        $blockers = array_values(array_unique(array_map(
            static fn (array $v): string => $v['code'].($v['date'] === null ? '' : '@'.$v['date']),
            array_filter($quote->violations, static fn (array $v): bool => in_array($v['code'], self::BLOCKING, true)),
        )));
        $soldOut = [];

        // Advisory when only quoting: the decision is taken again under the room type's lock when the stay is extended.
        foreach ($this->availability->forStay($property, $typeForAvailability, $added, false) as $night => $availability) {
            if (! $availability->canSellOne() || $availability->needsOverbooking()) {
                $soldOut[] = $night;
            }
        }

        $rows = array_map(static fn (array $n): array => [
            'date' => $n['date']->toString(), 'quoted_minor' => $n['quoted']->amountMinor, 'base_minor' => $n['breakdown']->base->amountMinor, 'service_charge_minor' => $n['breakdown']->serviceCharge->amountMinor,
            'tax_minor' => $n['breakdown']->tax->amountMinor, 'total_minor' => $n['breakdown']->total->amountMinor, 'scheme' => $n['scheme'],
        ], $quote->nights);

        return ['stay' => $added, 'quote' => $quote, 'rows' => $rows, 'blockers' => $blockers, 'sold_out' => $soldOut];
    }

    private function newDeparture(PropertyId $property, Stay $stay, string $date): BusinessDate
    {
        try {
            $departure = BusinessDate::fromString($date);
        } catch (InvalidArgumentException) {
            throw Refusal::invalid('Give a valid departure date.', ['departure']);
        }

        if (! $departure->isAfter($stay->expectedDeparture)) {
            throw Refusal::invalid('The new departure must be after the current one; a guest who leaves earlier checks out.', ['departure']);
        }

        if ($this->businessDate->current($property)->daysUntil($departure) > $this->settings->get($property)->availabilityHorizonDays) {
            throw Refusal::invalid('That is further ahead than the sales horizon.', ['departure']);
        }

        return $departure;
    }

    private function blockedFor(PropertyId $property, string $roomId, BusinessDate $fromNight, BusinessDate $departure): bool
    {
        return $this->blocks->overlapping($property, $roomId, $fromNight->toString(), $departure->previous()->toString()) !== [];
    }

    /** @return array{0: Stay, 1: Reservation} */
    private function load(PropertyId $property, string $stayId): array
    {
        $stay = $this->stays->find($property, strtolower($stayId)) ?? throw Refusal::notFound('Stay not found.');
        $reservation = $this->reservations->find($property, $stay->reservationId) ?? throw Refusal::notFound('Reservation not found.');

        return [$stay, $reservation];
    }

    /** @return array<string, mixed> */
    private function describe(PropertyId $property, string $stayId): array
    {
        $stay = $this->stays->find($property, strtolower($stayId)) ?? throw Refusal::notFound('Stay not found.');

        return [...$stay->toArray(), 'room_number' => $this->rooms->room($property, $stay->roomId)?->number, 'moves' => $this->stays->roomMoves($property, $stay->id)];
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 300) {
            throw Refusal::invalid('A reason of at most 300 characters is required.', ['reason']);
        }

        return $reason;
    }

    private function authorize(PropertyId $property, string $actorId): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }

        if (! $this->permissions->allowsInProperty($actorId, StayService::MANAGE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not change a stay.');
        }
    }
}
