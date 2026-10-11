<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\DayUse;

use App\Modules\FrontOffice\Application\Cashier\ShiftAttribution;
use App\Modules\FrontOffice\Application\Inventory\AvailabilityService;
use App\Modules\FrontOffice\Application\Inventory\InventoryRepository;
use App\Modules\FrontOffice\Application\Inventory\RoomBlockRepository;
use App\Modules\Housekeeping\Application\RoomHandover;
use App\Modules\Housekeeping\Application\RoomReadiness;
use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Modules\Property\Application\Rates\ChargeCalculator;
use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Documents\DocumentNumbers;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Idempotency\IdempotencyRequest;
use App\Shared\Application\Idempotency\IdempotentExecutor;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\DateMath;
use App\Shared\Domain\Time\StayDates;

/**
 * Day use (docs/OPERATIONS/DAY-USE.md): a room rented for some hours of the day with no night in it, for a guest who is not staying. A room type has a price for the whole booking and the longest it can be; a booking
 * names a room, a day, a start and a number of hours. It is refused when a guest of the house has the room, a reservation is to have it that night, it is out of order, another day use overlaps it, or the hotel has no spare
 * room of that type that night (a day use never takes a room that the night needs). The guest pays when let in: the price with the service charge and tax of the `rooms` scheme, in cash, card or QRIS, which is revenue of the day
 * and in the drawer of the cashier. When the guest leaves the room goes back to housekeeping to be cleaned. A day use is not a night: it is not in the occupancy of the night audit, but one still in use blocks the audit.
 */
final readonly class DayUseService
{
    public const VIEW_PERMISSION = 'front-office.day-use.view';

    public const BOOK_PERMISSION = 'front-office.day-use.book';

    public const MANAGE_PERMISSION = 'front-office.day-use.manage';

    public const METHODS = ['cash', 'card', 'qris'];

    public const PAID_EVENT = 'frontoffice.day_use.paid';

    public const SOURCE = 'day_use';

    public function __construct(
        private DayUseStore $store,
        private RoomCatalogReader $rooms,
        private AvailabilityService $availability,
        private InventoryRepository $inventory,
        private RoomBlockRepository $blocks,
        private RoomReadiness $readiness,
        private RoomHandover $handover,
        private ChargeCalculator $charges,
        private ShiftAttribution $shifts,
        private PermissionChecker $permissions,
        private PropertyCurrencyReader $currency,
        private BusinessDateProvider $businessDate,
        private DocumentNumbers $numbers,
        private IdempotentExecutor $executor,
        private TransactionRunner $transactions,
        private OutboxPublisher $outbox,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId): array
    {
        if (! $this->may($property, $actorId, self::VIEW_PERMISSION) && ! $this->may($property, $actorId, self::BOOK_PERMISSION) && ! $this->may($property, $actorId, self::MANAGE_PERMISSION)) {
            throw Refusal::forbidden('This person may not see day use.');
        }

        $today = $this->businessDate->current($property)->toString();
        $types = [];

        foreach ($this->rooms->activeTypes($property) as $t) {
            $types[$t->id] = ['id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'rooms' => []];
        }

        foreach ($this->rooms->activeRooms($property) as $r) {
            if (isset($types[$r->roomTypeId])) {
                $types[$r->roomTypeId]['rooms'][] = ['id' => $r->id, 'number' => $r->number];
            }
        }

        $rules = self::rules();

        return [
            'currency' => $this->currency->currencyOf($property), 'today' => $today, 'rules' => $rules, 'methods' => self::METHODS, 'types' => array_values($types),
            'rates' => array_map(static fn (array $r): array => ['id' => $r['id'], 'room_type_id' => $r['room_type_id'], 'price_minor' => (int) $r['price_minor'], 'max_hours' => (int) $r['max_hours'], 'is_active' => (bool) $r['is_active'], 'lock_version' => (int) $r['lock_version']], $this->store->rates($property)),
            'bookings' => array_map(self::shape(...), $this->store->bookings($property, DateMath::addDays($today, -7), 300)),
            'may' => ['book' => $this->may($property, $actorId, self::BOOK_PERMISSION), 'manage' => $this->may($property, $actorId, self::MANAGE_PERMISSION)],
        ];
    }

    public function addRate(PropertyId $property, string $actorId, string $roomTypeId, int $priceMinor, int $maxHours): void
    {
        $this->manage($property, $actorId);
        $this->checkRate($priceMinor, $maxHours);
        $type = $this->rooms->type($property, strtolower($roomTypeId)) ?? throw Refusal::invalid('Choose a room type.', ['room_type_id']);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actorId, $id, $type, $priceMinor, $maxHours): void {
            if (! $this->store->addRate($property, ['id' => $id, 'room_type_id' => $type->id, 'price_minor' => $priceMinor, 'max_hours' => $maxHours], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This room type has a day use price already. Change it instead.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'day_use.rate_added', 'day_use_rate', $id, null, ['room_type' => $type->code, 'price_minor' => $priceMinor, 'max_hours' => $maxHours]));
        });
    }

    /** A new price counts for bookings made from now on; a booking keeps the price it was made with. */
    public function changeRate(PropertyId $property, string $actorId, string $id, int $priceMinor, int $maxHours, bool $active, int $lock): void
    {
        $this->manage($property, $actorId);
        $this->checkRate($priceMinor, $maxHours);
        $before = $this->store->rate($property, strtolower($id)) ?? throw Refusal::notFound('Price not found.');

        $this->transactions->run(function () use ($property, $actorId, $before, $priceMinor, $maxHours, $active, $lock): void {
            if (! $this->store->updateRate($property, $before['id'], $lock, ['price_minor' => $priceMinor, 'max_hours' => $maxHours, 'is_active' => $active], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This price changed after you opened it. Reload.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'day_use.rate_changed', 'day_use_rate', $before['id'], ['price_minor' => (int) $before['price_minor'], 'max_hours' => (int) $before['max_hours'], 'is_active' => (bool) $before['is_active']], ['price_minor' => $priceMinor, 'max_hours' => $maxHours, 'is_active' => $active]));
        });
    }

    /** @return array{booking: array<string, mixed>, replayed: bool} */
    public function book(PropertyId $property, string $actorId, IdempotencyKey $key, string $roomId, string $day, string $time, int $hours, string $guestName, ?string $phone, int $guests, ?string $note): array
    {
        if (! $this->may($property, $actorId, self::BOOK_PERMISSION)) {
            throw Refusal::forbidden('This person may not book day use.');
        }

        $rules = self::rules();
        $today = $this->businessDate->current($property)->toString();
        $start = DayUseTimes::minutes($time) ?? throw Refusal::invalid('Give the time as hours and minutes, for example 10:00.', ['starts_at']);
        $guestName = trim($guestName);
        $phone = $phone === null || trim($phone) === '' ? null : trim($phone);
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) !== 1 || $day < $today || $day > DateMath::addDays($today, $rules['max_advance_days'])) {
            throw Refusal::invalid("The day is today or later, within {$rules['max_advance_days']} days.", ['starts_on']);
        }

        if ($guestName === '' || mb_strlen($guestName) > 120) {
            throw Refusal::invalid('Give the guest name, at most 120 characters.', ['guest_name']);
        }

        if ($phone !== null && preg_match('/^[0-9+()\s.-]{6,30}$/D', $phone) !== 1) {
            throw Refusal::invalid('Give a phone number of digits, with an optional plus sign.', ['guest_phone']);
        }

        if ($guests < 1 || $guests > 20) {
            throw Refusal::invalid('Give how many guests, from 1 to 20.', ['guests']);
        }

        if ($note !== null && mb_strlen($note) > 200) {
            throw Refusal::invalid('The note is at most 200 characters.', ['note']);
        }

        $room = $this->rooms->room($property, strtolower($roomId));

        if ($room === null || ! $room->isActive) {
            throw Refusal::invalid('Choose a room.', ['room_id']);
        }

        $rate = $this->store->rateOfType($property, $room->roomTypeId);

        if ($rate === null || ! (bool) $rate['is_active']) {
            throw Refusal::stateConflict('This room type has no day use price. Set one first.');
        }

        if ($hours < 1 || $hours > (int) $rate['max_hours']) {
            throw Refusal::invalid('This room type is rented for 1 to '.(int) $rate['max_hours'].' hours.', ['hours']);
        }

        $end = $start + $hours * 60;

        if ($start < (int) DayUseTimes::minutes($rules['earliest_start']) || $end > (int) DayUseTimes::minutes($rules['latest_end'])) {
            throw Refusal::invalid("A day use is between {$rules['earliest_start']} and {$rules['latest_end']}.", ['starts_at']);
        }

        $actor = strtolower($actorId);
        $result = $this->executor->execute(
            new IdempotencyRequest($property, $key, 'day_use.book', ['room' => $room->id, 'day' => $day, 'time' => $time, 'hours' => $hours, 'guest' => $guestName, 'phone' => $phone, 'guests' => $guests, 'note' => $note], $actor),
            function () use ($property, $actor, $room, $rate, $day, $time, $start, $end, $guestName, $phone, $guests, $note, $rules): array {
                $this->inventory->lockRoomType($property, $room->roomTypeId);
                $due = $this->store->stayDueOut($property, $room->id);

                if ($due !== null && ($due > $day || ($due === $day && $start < (int) DayUseTimes::minutes($rules['after_checkout'])) || $due < $day)) {
                    throw Refusal::stateConflict($due === $day ? "A guest of the house leaves this room that day: start at {$rules['after_checkout']} or later." : 'A guest of the house has this room.');
                }

                if ($this->store->reservedForNight($property, $room->id, $day) || $this->blocks->overlapping($property, $room->id, $day, $day) !== []) {
                    throw Refusal::stateConflict('This room is reserved or out of order that day.');
                }

                $night = $this->availability->forStay($property, $room->roomTypeId, new StayDates(BusinessDate::fromString($day), BusinessDate::fromString(DateMath::addDays($day, 1))))[$day] ?? null;

                if ($night === null || $night->available() < 1) {
                    throw Refusal::stateConflict('The hotel has no spare room of this type that night, and a day use never takes a room the night needs.');
                }

                if (! DayUseTimes::free($this->store->holdingRoom($property, $room->id, $day), $start, $end)) {
                    throw Refusal::stateConflict('This room is rented at that time already.');
                }

                $id = $this->ids->next();
                $number = $this->numbers->next($property, 'DUS');
                $this->store->addBooking($property, [
                    'id' => $id, 'number' => $number, 'room_type_id' => $room->roomTypeId, 'room_id' => $room->id, 'room_number' => $room->number, 'starts_on' => $day, 'starts_at' => $time, 'ends_at' => DayUseTimes::time($end),
                    'guest_name' => $guestName, 'guest_phone' => $phone, 'guests' => $guests, 'price_minor' => (int) $rate['price_minor'], 'note' => $note, 'created_by' => $actor,
                ], $this->clock->nowUtc());
                $this->audit->record(new AuditEntry($property->toString(), $actor, 'day_use.booked', 'day_use_booking', $id, null, ['number' => $number, 'room' => $room->number, 'day' => $day, 'from' => $time, 'until' => DayUseTimes::time($end), 'price_minor' => (int) $rate['price_minor']]));

                return ['booking_id' => $id];
            },
        );

        return ['booking' => self::shape($this->store->booking($property, (string) $result->payload['booking_id']) ?? throw Refusal::notFound('Booking not found.')), 'replayed' => $result->replayed];
    }

    /** The guest is let in and pays: the price with the service charge and tax, which is revenue of the day. @return array<string, mixed> */
    public function start(PropertyId $property, string $actorId, string $id, string $method, ?string $reference, int $lock): array
    {
        $b = $this->openBooking($property, $actorId, $id, 'booked');
        $today = $this->businessDate->current($property);
        $reference = $reference === null || trim($reference) === '' ? null : mb_substr(trim($reference), 0, 60);

        if (substr((string) $b['starts_on'], 0, 10) !== $today->toString()) {
            throw Refusal::stateConflict('A day use is started on its own day.');
        }

        if (! in_array($method, self::METHODS, true)) {
            throw Refusal::invalid('Choose cash, card or QRIS.', ['payment_method']);
        }

        if ($method !== 'cash' && ($reference === null || mb_strlen($reference) < 4)) {
            throw Refusal::invalid('Give the approval code or QRIS reference of the payment.', ['reference']);
        }

        if ($this->store->stayDueOut($property, $b['room_id']) !== null) {
            throw Refusal::stateConflict('A guest of the house is still in this room.');
        }

        if (! $this->readiness->isReady($property, $b['room_id'])) {
            throw Refusal::stateConflict('This room is not ready yet: housekeeping has not cleaned it.');
        }

        $actor = strtolower($actorId);
        $split = $this->charges->breakdown($property, self::rules()['charge_scope'], $today, (int) $b['price_minor'], false);
        $currency = $this->currency->currencyOf($property);

        $this->transactions->run(function () use ($property, $actor, $b, $method, $reference, $lock, $split, $today, $currency): void {
            $shift = null;

            if ($method === 'cash') {
                $this->shifts->assertMayHandleMoney($property, $actor);
                $shift = $this->store->openShiftOf($property, $actor);
            }

            $now = $this->clock->nowUtc();

            if (! $this->store->updateBooking($property, $b['id'], $lock, [
                'status' => 'in_use', 'service_charge_minor' => $split['service_charge_minor'], 'tax_minor' => $split['tax_minor'], 'total_minor' => $split['total_minor'], 'payment_method' => $method, 'payment_reference' => $reference,
                'shift_id' => $shift, 'paid_on' => $today->toString(), 'started_at' => $now,
            ], $now)) {
                throw Refusal::stateConflict('This booking changed after you opened it. Reload.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'day_use.started', 'day_use_booking', $b['id'], ['status' => 'booked'], ['status' => 'in_use', 'number' => $b['number'], 'method' => $method, 'total_minor' => $split['total_minor']]));
            $this->outbox->publish(new OutboxEvent($property, self::PAID_EVENT, $b['id'], 1, [
                'bill_id' => $b['id'], 'bill_number' => $b['number'], 'outlet_id' => $b['room_type_id'], 'outlet_code' => 'DAYUSE', 'source' => self::SOURCE, 'settled_business_date' => $today->toString(), 'currency' => $currency,
                'base_minor' => $split['base_minor'], 'service_charge_minor' => $split['service_charge_minor'], 'tax_minor' => $split['tax_minor'], 'total_minor' => $split['total_minor'],
                'payments' => [['method' => $method, 'amount_minor' => $split['total_minor']]], 'actor_id' => $actor,
            ]));
        });

        return self::shape($this->store->booking($property, $b['id']) ?? throw Refusal::notFound('Booking not found.'));
    }

    /** The guest has left: the room goes back to housekeeping to be cleaned. @return array<string, mixed> */
    public function finish(PropertyId $property, string $actorId, string $id, int $lock): array
    {
        $b = $this->openBooking($property, $actorId, $id, 'in_use');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $b, $lock): void {
            $now = $this->clock->nowUtc();

            if (! $this->store->updateBooking($property, $b['id'], $lock, ['status' => 'done', 'finished_at' => $now], $now)) {
                throw Refusal::stateConflict('This booking changed after you opened it. Reload.');
            }

            $this->handover->vacated($property, $b['room_id'], $b['id'], $actor);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'day_use.finished', 'day_use_booking', $b['id'], ['status' => 'in_use'], ['status' => 'done', 'number' => $b['number']]));
        });

        return self::shape($this->store->booking($property, $b['id']) ?? throw Refusal::notFound('Booking not found.'));
    }

    /** @return array<string, mixed> */
    public function cancel(PropertyId $property, string $actorId, string $id, string $reason, int $lock): array
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Give the reason in at most 200 characters.', ['reason']);
        }

        return $this->settle($property, $actorId, $id, $lock, 'cancelled', $reason);
    }

    /** @return array<string, mixed> */
    public function noShow(PropertyId $property, string $actorId, string $id, int $lock): array
    {
        return $this->settle($property, $actorId, $id, $lock, 'no_show', null);
    }

    /** @return array<string, mixed> */
    private function settle(PropertyId $property, string $actorId, string $id, int $lock, string $status, ?string $reason): array
    {
        $b = $this->openBooking($property, $actorId, $id, 'booked');

        if ($status === 'no_show' && substr((string) $b['starts_on'], 0, 10) > $this->businessDate->current($property)->toString()) {
            throw Refusal::stateConflict('This booking is for a later day.');
        }

        $this->transactions->run(function () use ($property, $actorId, $b, $lock, $status, $reason): void {
            if (! $this->store->updateBooking($property, $b['id'], $lock, ['status' => $status, 'reason' => $reason], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This booking changed after you opened it. Reload.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'day_use.'.$status, 'day_use_booking', $b['id'], ['status' => 'booked'], ['status' => $status, 'number' => $b['number']], $reason));
        });

        return self::shape($this->store->booking($property, $b['id']) ?? throw Refusal::notFound('Booking not found.'));
    }

    /** @return array<string, mixed> */
    private function openBooking(PropertyId $property, string $actorId, string $id, string $status): array
    {
        if (! $this->may($property, $actorId, self::BOOK_PERMISSION)) {
            throw Refusal::forbidden('This person may not work on day use bookings.');
        }

        $b = $this->store->booking($property, strtolower($id)) ?? throw Refusal::notFound('Booking not found.');

        if ($b['status'] !== $status) {
            throw Refusal::stateConflict($status === 'booked' ? 'This booking is settled or started already.' : 'This booking is not in use.');
        }

        return $b;
    }

    private function checkRate(int $priceMinor, int $maxHours): void
    {
        if ($priceMinor < 1 || $priceMinor > 10_000_000_00) {
            throw Refusal::invalid('Give a price above zero.', ['price_minor']);
        }

        if ($maxHours < 1 || $maxHours > 12) {
            throw Refusal::invalid('The longest stay is from 1 to 12 hours.', ['max_hours']);
        }
    }

    /** @return array{earliest_start: string, latest_end: string, after_checkout: string, max_advance_days: int, charge_scope: string} */
    public static function rules(): array
    {
        $time = static fn (string $key, string $fallback): string => DayUseTimes::minutes((string) config("frontdesk.day_use.{$key}")) === null ? $fallback : (string) config("frontdesk.day_use.{$key}");

        return [
            'earliest_start' => $time('earliest_start', '06:00'), 'latest_end' => $time('latest_end', '21:00'), 'after_checkout' => $time('after_checkout', '12:00'),
            'max_advance_days' => max(0, min(365, (int) config('frontdesk.day_use.max_advance_days'))), 'charge_scope' => in_array((string) config('frontdesk.day_use.charge_scope'), ['rooms', 'laundry', 'fnb'], true) ? (string) config('frontdesk.day_use.charge_scope') : 'rooms',
        ];
    }

    /** @param array<string, mixed> $b @return array<string, mixed> */
    private static function shape(array $b): array
    {
        return [
            'id' => $b['id'], 'number' => $b['number'], 'room_id' => $b['room_id'], 'room_number' => $b['room_number'], 'starts_on' => substr((string) $b['starts_on'], 0, 10), 'starts_at' => $b['starts_at'], 'ends_at' => $b['ends_at'],
            'guest_name' => $b['guest_name'], 'guests' => (int) $b['guests'], 'price_minor' => (int) $b['price_minor'], 'total_minor' => (int) $b['total_minor'], 'status' => $b['status'], 'payment_method' => $b['payment_method'],
            'note' => $b['note'], 'reason' => $b['reason'], 'lock_version' => (int) $b['lock_version'],
        ];
    }

    private function manage(PropertyId $property, string $actorId): void
    {
        if (! $this->may($property, $actorId, self::MANAGE_PERMISSION)) {
            throw Refusal::forbidden('This person may not set day use prices.');
        }
    }

    private function may(PropertyId $property, string $actorId, string $permission): bool
    {
        return $this->permissions->allowsInProperty($actorId, $permission, $property);
    }
}
