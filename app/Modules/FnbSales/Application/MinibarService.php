<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Application;

use App\Modules\FrontOffice\Application\Charging\GuestCharging;
use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Documents\DocumentNumbers;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The mini bars of the rooms (FR-FBS-020 to -023, -025). The attendant scans the code of the room (the room number, with or without a `room:` in front), sees whom the room is let to and how many of each item it should hold, and
 * enters what the guest consumed and what was put back. What was consumed goes on the folio of the guest at once, with the service charge and tax of the F&B scheme, and is listed for the cashier; nothing is typed twice. What each room
 * holds is kept, so the list of what to refill for the next shift is the difference from the number each room should hold. Every check is kept per room and per person and never changed. When nobody is in the room, or the folio is closed,
 * nothing is charged here: the late charge procedure of the front office is used instead.
 */
final readonly class MinibarService
{
    public const SCOPE = 'fnb';

    public const SOURCE = 'fnb_minibar';

    public function __construct(
        private MinibarStore $store,
        private RoomCatalogReader $rooms,
        private GuestCharging $guests,
        private FnbAccess $access,
        private StaffDirectory $staff,
        private PropertyCurrencyReader $currencies,
        private BusinessDateProvider $businessDate,
        private DocumentNumbers $numbers,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->access->assertProperty($property);
        $operate = $this->access->may($property, $actorId, FnbAccess::MINIBAR_OPERATE);
        $manage = $this->access->may($property, $actorId, FnbAccess::MINIBAR_MANAGE);

        if (! $operate && ! $manage) {
            throw Refusal::forbidden('This person may not see the mini bars.');
        }

        return ['currency' => $this->currencies->currencyOf($property), 'may' => ['operate' => $operate, 'manage' => $manage], 'items' => array_map(self::item(...), $this->store->items($property, ! $manage)), 'today' => $this->businessDate->current($property)->toString()];
    }

    /** Finds the room by what was scanned and says whom it is let to and what it holds. @return array<string, mixed> */
    public function room(PropertyId $property, string $actorId, string $code): array
    {
        $this->access->require($property, $actorId, FnbAccess::MINIBAR_OPERATE, 'This person may not check the mini bars.');
        $room = $this->find($property, $code);
        $stay = $this->guests->inHouseStayOfRoom($property, $room->id);
        $items = $this->store->items($property, true);
        $held = $this->store->stock($property, [$room->id])[$room->id] ?? [];

        return [
            'room' => ['id' => $room->id, 'number' => $room->number], 'guest_name' => $stay['guest_name'] ?? null, 'in_house' => $stay !== null,
            'items' => array_map(static fn (array $i): array => [...self::item($i), 'held' => $held[$i['id']] ?? (int) $i['par_qty']], $items),
        ];
    }

    /**
     * Records a check: what each item consumed (charged to the guest) and refilled (put back). Items not listed are unchanged.
     *
     * @param  list<array{item_id: string, consumed: int, refilled: int}>  $lines
     * @return array<string, mixed>
     */
    public function check(PropertyId $property, string $actorId, string $roomCode, array $lines): array
    {
        $this->access->require($property, $actorId, FnbAccess::MINIBAR_OPERATE, 'This person may not check the mini bars.');
        $actor = strtolower($actorId);
        $room = $this->find($property, $roomCode);

        if ($lines === [] || count($lines) > 60) {
            throw Refusal::invalid('Give what was consumed or put back, for at most 60 items.', ['lines']);
        }

        $id = $this->ids->next();
        $now = $this->clock->nowUtc();
        $date = $this->businessDate->current($property)->toString();

        $this->transactions->run(function () use ($property, $actor, $room, $lines, $id, $now, $date): void {
            $stay = $this->guests->inHouseStayOfRoom($property, $room->id);
            $items = array_column($this->store->items($property, false), null, 'id');
            $held = $this->store->stock($property, [$room->id])[$room->id] ?? [];
            $seen = [];
            $rows = [];
            $consumedTotal = 0;
            $stockNow = [];

            foreach ($lines as $l) {
                $item = $items[strtolower((string) ($l['item_id'] ?? ''))] ?? throw Refusal::invalid('Choose items of the mini bar.', ['lines']);
                $consumed = $l['consumed'] ?? 0;
                $refilled = $l['refilled'] ?? 0;

                if (isset($seen[$item['id']]) || ! is_int($consumed) || ! is_int($refilled) || $consumed < 0 || $refilled < 0 || $consumed > 99 || $refilled > 99 || ($consumed === 0 && $refilled === 0)) {
                    throw Refusal::invalid('Each item once, with what was consumed or put back, from 0 to 99.', ['lines']);
                }

                if (! (bool) $item['is_active']) {
                    throw Refusal::stateConflict($item['name'].' is no longer in the mini bar.');
                }

                $seen[$item['id']] = true;
                $before = $held[$item['id']] ?? (int) $item['par_qty'];

                if ($consumed > $before) {
                    throw Refusal::invalid($item['name'].': the room held only '.$before.', so '.$consumed.' cannot have been consumed.', ['lines']);
                }

                $after = $before - $consumed + $refilled;

                if ($after > 99) {
                    throw Refusal::invalid($item['name'].': a room holds at most 99.', ['lines']);
                }

                $consumedTotal += $consumed * (int) $item['price_minor'];
                $stockNow[$item['id']] = $after;
                $rows[] = ['item_id' => $item['id'], 'item_code' => $item['code'], 'item_name' => $item['name'], 'unit_price_minor' => (int) $item['price_minor'], 'consumed' => $consumed, 'refilled' => $refilled, 'stock_after' => $after];
            }

            $posting = null;
            $charged = null;

            if ($consumedTotal > 0) {
                // FR-FBS-025: nobody in the room, or no open folio, means the stay is over; a late charge is the way, not this.
                if ($stay === null) {
                    throw Refusal::stateConflict('Nobody is in this room, so the folio is closed. Charge what was consumed with the late charge procedure of the front office.');
                }

                try {
                    $result = $this->guests->charge($property, $actor, $stay['reservation_id'], self::SCOPE, 'MINIBAR', 'Mini bar room '.$room->number, $consumedTotal, self::SOURCE, $id);
                } catch (Refusal $e) {
                    throw Refusal::stateConflict($e->getMessage().' If the folio is closed, charge it with the late charge procedure of the front office.');
                }

                $posting = $result['posting_id'];
                $charged = (int) $result['total_minor'];
            }

            $number = $this->numbers->next($property, 'MBAR');
            $this->store->addCheck($property, ['id' => $id, 'number' => $number, 'room_id' => $room->id, 'room_number' => $room->number, 'stay_id' => $stay['stay_id'] ?? null, 'reservation_id' => $stay['reservation_id'] ?? null, 'guest_name' => $stay['guest_name'] ?? null, 'checked_by' => $actor, 'checked_at' => $now->format('Y-m-d H:i:s.u'),
                'business_date' => $date, 'consumed_minor' => $consumedTotal, 'folio_posting_id' => $posting, 'charged_total_minor' => $charged], $rows, $now);

            foreach ($stockNow as $itemId => $qty) {
                $this->store->setStock($property, $room->id, $itemId, $qty, $now);
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'minibar.checked', 'minibar_check', $id, null, ['number' => $number, 'room' => $room->number, 'consumed_minor' => $consumedTotal, 'charged_total_minor' => $charged, 'items' => count($rows)]));

            if ($consumedTotal > 0) {
                $this->outbox->publish(new OutboxEvent($property, 'fnb.minibar.consumed', $id, 1, ['check_id' => $id, 'room_id' => $room->id, 'reservation_id' => $stay['reservation_id'] ?? null, 'consumed_minor' => $consumedTotal, 'charged_total_minor' => $charged, 'posting_id' => $posting]));
            }
        });

        return $this->history($property, $actorId, $room->id, null, null, null)['checks'][0];
    }

    /** What to put back in each room before the next shift: the difference between what a room should hold and what it holds. @return array<string, mixed> */
    public function refillList(PropertyId $property, string $actorId): array
    {
        $this->access->require($property, $actorId, FnbAccess::MINIBAR_OPERATE, 'This person may not see the refill list.');
        $items = array_column($this->store->items($property, true), null, 'id');
        $numbers = [];

        foreach ($this->rooms->activeRooms($property) as $r) {
            $numbers[$r->id] = $r->number;
        }

        $out = [];
        $totals = [];

        foreach ($this->store->stock($property, null) as $roomId => $held) {
            $need = [];

            foreach ($held as $itemId => $qty) {
                if (isset($items[$itemId]) && $qty < (int) $items[$itemId]['par_qty'] && isset($numbers[$roomId])) {
                    $need[] = ['item_id' => $itemId, 'code' => $items[$itemId]['code'], 'name' => $items[$itemId]['name'], 'quantity' => (int) $items[$itemId]['par_qty'] - $qty];
                    $totals[$itemId] = ($totals[$itemId] ?? 0) + (int) $items[$itemId]['par_qty'] - $qty;
                }
            }

            if ($need !== []) {
                $out[] = ['room' => ['id' => $roomId, 'number' => $numbers[$roomId]], 'items' => $need];
            }
        }

        usort($out, static fn (array $a, array $b): int => strnatcmp($a['room']['number'], $b['room']['number']));

        return ['rooms' => $out, 'totals' => array_map(static fn (string $itemId, int $qty): array => ['item_id' => $itemId, 'code' => $items[$itemId]['code'], 'name' => $items[$itemId]['name'], 'quantity' => $qty], array_keys($totals), array_values($totals))];
    }

    /** @return array<string, mixed> */
    public function history(PropertyId $property, string $actorId, ?string $roomId, ?string $staffId, ?string $from, ?string $to): array
    {
        $this->access->require($property, $actorId, FnbAccess::MINIBAR_OPERATE, 'This person may not see the mini bar history.');

        $checks = $this->store->checks($property, $roomId === null ? null : strtolower($roomId), $staffId === null ? null : strtolower($staffId), $from, $to, 200);
        $names = $this->staff->namesOf($property, array_values(array_unique(array_column($checks, 'checked_by'))));

        return ['checks' => array_map(static fn (array $c): array => [
            'id' => $c['id'], 'number' => $c['number'], 'room' => ['id' => $c['room_id'], 'number' => $c['room_number']], 'guest_name' => $c['guest_name'], 'checked_by' => $c['checked_by'], 'checked_by_name' => $names[$c['checked_by']] ?? '', 'checked_at' => (new \DateTimeImmutable((string) $c['checked_at'], new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'), 'business_date' => substr((string) $c['business_date'], 0, 10),
            'consumed_minor' => (int) $c['consumed_minor'], 'charged_total_minor' => $c['charged_total_minor'] === null ? null : (int) $c['charged_total_minor'], 'posted' => $c['folio_posting_id'] !== null,
            'lines' => array_map(static fn (array $l): array => ['code' => $l['item_code'], 'name' => $l['item_name'], 'unit_price_minor' => (int) $l['unit_price_minor'], 'consumed' => (int) $l['consumed'], 'refilled' => (int) $l['refilled'], 'stock_after' => (int) $l['stock_after']], $c['lines']),
        ], $checks)];
    }

    /** @return array<string, mixed> */
    public function createItem(PropertyId $property, string $actorId, string $code, string $name, int $priceMinor, int $par): array
    {
        $this->access->require($property, $actorId, FnbAccess::MINIBAR_MANAGE, 'This person may not set the mini bar.');
        $clean = $this->clean($code, $name, $priceMinor, $par);
        $id = $this->ids->next();
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $clean): void {
            if (! $this->store->addItem($property, ['id' => $id, ...$clean, 'is_active' => true], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('An item with this code exists already.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'minibar_item.created', 'minibar_item', $id, null, $clean));
        });

        return $this->overview($property, $actorId);
    }

    /** @return array<string, mixed> */
    public function updateItem(PropertyId $property, string $actorId, string $id, string $name, int $priceMinor, int $par, int $lock): array
    {
        $this->access->require($property, $actorId, FnbAccess::MINIBAR_MANAGE, 'This person may not set the mini bar.');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $name, $priceMinor, $par, $lock): void {
            $before = $this->store->item($property, strtolower($id)) ?? throw Refusal::notFound('Item not found.');
            $clean = $this->clean($before['code'], $name, $priceMinor, $par);
            unset($clean['code']);

            if (! $this->store->updateItem($property, $before['id'], $lock, $clean, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This item changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'minibar_item.changed', 'minibar_item', $before['id'], array_intersect_key($before, $clean), $clean + ['code' => $before['code']]));
        });

        return $this->overview($property, $actorId);
    }

    /** @return array<string, mixed> */
    public function setItemActive(PropertyId $property, string $actorId, string $id, bool $active, int $lock): array
    {
        $this->access->require($property, $actorId, FnbAccess::MINIBAR_MANAGE, 'This person may not set the mini bar.');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $active, $lock): void {
            $before = $this->store->item($property, strtolower($id)) ?? throw Refusal::notFound('Item not found.');

            if ((bool) $before['is_active'] === $active) {
                throw Refusal::stateConflict($active ? 'This item is in the mini bar already.' : 'This item is retired already.');
            }

            if (! $this->store->updateItem($property, $before['id'], $lock, ['is_active' => $active], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This item changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, $active ? 'minibar_item.resumed' : 'minibar_item.retired', 'minibar_item', $before['id'], ['is_active' => (bool) $before['is_active']], ['is_active' => $active, 'code' => $before['code']]));
        });

        return $this->overview($property, $actorId);
    }

    /** @return object{id: string, number: string} */
    private function find(PropertyId $property, string $code): object
    {
        $code = mb_strtolower(trim((string) preg_replace('/^room\s*[:#-]?\s*/i', '', trim($code))));

        foreach ($this->rooms->activeRooms($property) as $r) {
            if (mb_strtolower($r->number) === $code) {
                return $r;
            }
        }

        throw Refusal::notFound('No room has this code. Scan the code on the door again.');
    }

    /** @return array<string, mixed> */
    private function clean(string $code, string $name, int $priceMinor, int $par): array
    {
        $code = strtoupper(trim($code));
        $name = trim($name);

        if (preg_match('/^[A-Z0-9-]{1,12}$/D', $code) !== 1) {
            throw Refusal::invalid('The code is 1 to 12 letters, digits and dashes.', ['code']);
        }

        if ($name === '' || mb_strlen($name) > 80) {
            throw Refusal::invalid('Give a name of at most 80 characters.', ['name']);
        }

        if ($priceMinor < 1 || $priceMinor > 9_000_000_000) {
            throw Refusal::invalid('Give a price above zero.', ['price_minor']);
        }

        if ($par < 1 || $par > 99) {
            throw Refusal::invalid('A room holds from 1 to 99 of an item.', ['par_qty']);
        }

        return ['code' => $code, 'name' => $name, 'price_minor' => $priceMinor, 'par_qty' => $par];
    }

    /** @param array<string, mixed> $i @return array<string, mixed> */
    private static function item(array $i): array
    {
        return ['id' => $i['id'], 'code' => $i['code'], 'name' => $i['name'], 'price_minor' => (int) $i['price_minor'], 'par_qty' => (int) $i['par_qty'], 'active' => (bool) $i['is_active'], 'lock_version' => (int) $i['lock_version']];
    }
}
