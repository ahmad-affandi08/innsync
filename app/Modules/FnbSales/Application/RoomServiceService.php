<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Application;

use App\Modules\FrontOffice\Application\Charging\GuestCharging;
use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Modules\Property\Application\Ports\PropertyTimeZoneReader;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Room service (FR-FBS-024). An order is a bill of an outlet of the kind room service, opened for a room that has a guest in it; this keeps the room, the time promised for the delivery (the clock of the property) and how far
 * the delivery is: ordered, on the way, delivered. The board lists what is still to deliver, soonest promise first, with what is late. The bill is paid as any bill, and charged to the room in full when the guest asks.
 */
final readonly class RoomServiceService
{
    public const NEXT = ['ordered' => 'on_the_way', 'on_the_way' => 'delivered'];

    public function __construct(
        private MinibarStore $store,
        private BillService $bills,
        private SetupStore $setup,
        private RoomCatalogReader $rooms,
        private GuestCharging $guests,
        private PropertyTimeZoneReader $zones,
        private FnbAccess $access,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function board(PropertyId $property, string $actorId): array
    {
        $this->access->require($property, $actorId, FnbAccess::POS_OPERATE, 'This person may not see room service.');
        $now = $this->clock->nowUtc();
        $rows = $this->store->orders($property, $now->modify('-6 hours')->format('Y-m-d H:i:s.u'), 200);
        $outlets = array_values(array_filter($this->setup->outlets($property), static fn (array $o): bool => $o['kind'] === 'room_service' && (bool) $o['is_active']));
        $inHouse = [];

        foreach ($this->rooms->activeRooms($property) as $r) {
            $stay = $this->guests->inHouseStayOfRoom($property, $r->id);

            if ($stay !== null) {
                $inHouse[] = ['id' => $r->id, 'number' => $r->number, 'guest_name' => $stay['guest_name'] ?? ''];
            }
        }

        usort($inHouse, static fn (array $a, array $b): int => strnatcmp($a['number'], $b['number']));

        return [
            'now' => $now->format('Y-m-d\TH:i:s\Z'), 'orders' => array_map(fn (array $o): array => $this->shape($o, $now), $rows), 'outlets' => array_map(static fn (array $o): array => ['id' => $o['id'], 'code' => $o['code'], 'name' => $o['name']], $outlets), 'rooms' => $inHouse,
        ];
    }

    /** @return array<string, mixed> */
    public function place(PropertyId $property, string $actorId, string $outletId, string $roomId, string $promisedTime, int $covers, ?string $note, string $source = 'staff'): array
    {
        $this->access->require($property, $actorId, FnbAccess::POS_OPERATE, 'This person may not take orders.');
        $actor = strtolower($actorId);
        $outlet = $this->setup->outlet($property, strtolower($outletId));

        if ($outlet === null || $outlet['kind'] !== 'room_service') {
            throw Refusal::invalid('Choose an outlet of the kind room service.', ['outlet_id']);
        }

        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $promisedTime) !== 1) {
            throw Refusal::invalid('Give the time promised as hours and minutes, for example 19:30.', ['promised_time']);
        }

        $zone = $this->zones->forProperty($property) ?? throw Refusal::notFound('Property not found.');
        $now = $this->clock->nowUtc();
        $promised = $zone->utcAt($zone->calendarDateAt($now), $promisedTime);

        if ($promised <= $now) {
            throw Refusal::invalid('The time promised is later than now.', ['promised_time']);
        }

        $room = $this->rooms->room($property, strtolower($roomId));
        $stay = $room === null ? null : $this->guests->inHouseStayOfRoom($property, $room->id);

        if ($room === null || $stay === null) {
            throw Refusal::invalid('Choose a room that has a guest in it.', ['room_id']);
        }

        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actor, $actorId, $outlet, $room, $stay, $promised, $covers, $note, $id, $now, $source): void {
            $bill = $this->bills->open($property, $actorId, $outlet['id'], null, $room->id, $covers, $note, $source);
            $this->store->addOrder($property, ['id' => $id, 'bill_id' => $bill['bill']['id'], 'room_id' => $room->id, 'room_number' => $room->number, 'guest_name' => $stay['guest_name'] ?? null, 'promised_at' => $promised->format('Y-m-d H:i:s.u'), 'status' => 'ordered', 'status_changed_by' => $actor, 'status_changed_at' => $now->format('Y-m-d H:i:s.u'), 'created_by' => $actor], $now);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'room_service.ordered', 'room_service_order', $id, null, ['room' => $room->number, 'bill' => $bill['bill']['number'], 'promised_at' => $promised->format('Y-m-d\TH:i:s\Z')]));
        });

        return $this->shape($this->store->order($property, $id) ?? throw Refusal::notFound('Order not found.'), $now);
    }

    /** @return array<string, mixed> */
    public function advance(PropertyId $property, string $actorId, string $id, string $to, int $lock): array
    {
        $this->access->require($property, $actorId, FnbAccess::POS_OPERATE, 'This person may not take orders.');
        $actor = strtolower($actorId);
        $now = $this->clock->nowUtc();

        $this->transactions->run(function () use ($property, $actor, $id, $to, $lock, $now): void {
            $o = $this->store->order($property, strtolower($id)) ?? throw Refusal::notFound('Order not found.');

            if ($o['bill_status'] === 'cancelled') {
                throw Refusal::stateConflict('The bill of this order was cancelled.');
            }

            if ((self::NEXT[$o['status']] ?? null) !== $to) {
                throw Refusal::stateConflict($o['status'] === 'delivered' ? 'This order was delivered already.' : 'An order that is '.$o['status'].' goes to '.(self::NEXT[$o['status']] ?? '—').' next.');
            }

            $fields = ['status' => $to, 'status_changed_by' => $actor, 'status_changed_at' => $now->format('Y-m-d H:i:s.u')];

            if ($to === 'delivered') {
                $fields['delivered_at'] = $now->format('Y-m-d H:i:s.u');
            }

            if (! $this->store->updateOrder($property, $o['id'], $lock, $fields, $now)) {
                throw Refusal::stateConflict('This order changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'room_service.'.$to, 'room_service_order', $o['id'], ['status' => $o['status']], ['status' => $to, 'room' => $o['room_number'], 'bill' => $o['bill_number'], 'late_minutes' => $to === 'delivered' ? max(0, intdiv($now->getTimestamp() - (new \DateTimeImmutable((string) $o['promised_at'], new \DateTimeZone('UTC')))->getTimestamp(), 60)) : null]));
        });

        return $this->shape($this->store->order($property, strtolower($id)) ?? throw Refusal::notFound('Order not found.'), $now);
    }

    /** @param array<string, mixed> $o @return array<string, mixed> */
    private function shape(array $o, \DateTimeImmutable $now): array
    {
        $promised = new \DateTimeImmutable((string) $o['promised_at'], new \DateTimeZone('UTC'));
        $open = $o['status'] !== 'delivered' && $o['bill_status'] !== 'cancelled';

        return [
            'id' => $o['id'], 'bill_id' => $o['bill_id'], 'bill_number' => $o['bill_number'], 'room' => ['id' => $o['room_id'], 'number' => $o['room_number']], 'guest_name' => $o['guest_name'], 'promised_at' => $promised->format('Y-m-d\TH:i:s\Z'),
            'status' => $o['bill_status'] === 'cancelled' ? 'cancelled' : $o['status'], 'late' => $open && $promised < $now, 'late_minutes' => $open && $promised < $now ? intdiv($now->getTimestamp() - $promised->getTimestamp(), 60) : 0,
            'delivered_at' => $o['delivered_at'] === null ? null : (new \DateTimeImmutable((string) $o['delivered_at'], new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'), 'lock_version' => (int) $o['lock_version'],
            'next' => $open ? (self::NEXT[$o['status']] ?? null) : null,
        ];
    }
}
