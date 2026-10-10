<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\DoorLocks;

use App\Modules\FrontOffice\Application\Reservations\ReservationRepository;
use App\Modules\FrontOffice\Application\Stays\StayRepository;
use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxConsumer;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Time\Clock;

/**
 * Makes the door lock jobs of the stay events (docs/OPERATIONS/DOOR-LOCKS.md): check-in makes the keys, check-out takes them back, a room move takes back the keys of the old room and makes new ones for the new
 * room, an extension gives the keys a new last day. Nothing is made while door locks are switched off. One event makes one job however often it is delivered.
 */
final readonly class DoorLockJobConsumer implements OutboxConsumer
{
    public function __construct(private DoorLockStore $store, private RoomCatalogReader $rooms, private ReservationRepository $reservations, private StayRepository $stays, private IdentifierGenerator $ids, private PropertyContext $context, private Clock $clock) {}

    public function name(): string
    {
        return 'frontoffice.door-locks';
    }

    public function supports(string $eventType): bool
    {
        return in_array($eventType, ['frontoffice.stay.checked_in', 'frontoffice.stay.checked_out', 'frontoffice.stay.room_moved', 'frontoffice.stay.extended'], true);
    }

    public function consume(OutboxMessage $message): void
    {
        // The room catalogue answers only for the property in force; the message names it.
        $this->context->run($message->event->propertyId, fn () => $this->make($message));
    }

    private function make(OutboxMessage $message): void
    {
        $event = $message->event;
        $property = $event->propertyId;
        $settings = $this->store->settings($property);

        if ($settings === null || ! $settings['enabled']) {
            return;
        }

        $d = $event->data;
        $reservation = $this->reservations->find($property, (string) ($d['reservation_id'] ?? ''));
        $number = $reservation?->number;
        $source = hash('sha256', $message->eventId);
        $keys = (int) $settings['key_count'];
        $now = $this->clock->nowUtc();
        $make = function (string $kind, ?string $roomId, ?string $until) use ($property, $d, $source, $number, $keys, $now): void {
            $room = $roomId === null ? null : $this->rooms->room($property, $roomId);

            if ($room === null) {
                return;
            }

            $this->store->addJob($property, ['id' => $this->ids->next(), 'source_key' => $source, 'stay_id' => (string) $d['stay_id'], 'reservation_number' => $number, 'kind' => $kind, 'room_number' => $room->number, 'key_count' => $keys, 'valid_until' => $until], $now);
        };

        match ($event->eventType) {
            'frontoffice.stay.checked_in' => $make('issue', $d['room_id'] ?? null, $d['expected_departure'] ?? null),
            'frontoffice.stay.checked_out' => $make('revoke', $d['room_id'] ?? null, null),
            'frontoffice.stay.room_moved' => [$make('revoke', $d['from_room_id'] ?? null, null), $make('issue', $d['to_room_id'] ?? null, $reservation?->stay->departure->toString())],
            default => $make('update', $this->stays->find($property, (string) $d['stay_id'])?->roomId, $d['new_departure'] ?? null),
        };
    }
}
