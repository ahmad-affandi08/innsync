<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Spa;

use App\Modules\FrontOffice\Application\Cashier\ShiftAttribution;
use App\Modules\FrontOffice\Application\DayUse\DayUseTimes;
use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Reservations\ReservationRepository;
use App\Modules\FrontOffice\Application\Stays\StayRepository;
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
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\DateMath;

/**
 * The spa (docs/OPERATIONS/SPA.md), as the desk sees it. Treatment rooms, treatments and therapists are set up by the manager (a therapist has the commission the owner set for them). A booking names a room, a day,
 * a start, a treatment and a therapist; the room and the therapist cannot be in two bookings at once. The therapist accepts or declines it on the phone (the desk gives a declined one to someone else), starts and
 * stops it (`SpaTherapistService`), and when it is done it is charged to the folio of a guest of the house, or paid at the desk by a guest who is not staying (cash, card or QRIS: revenue of the day and cash in the drawer).
 * The commission of the therapist is fixed when the treatment is done, from the price and the share they had when it was booked.
 */
final readonly class SpaService
{
    public const VIEW_PERMISSION = 'front-office.spa.view';

    public const BOOK_PERMISSION = 'front-office.spa.book';

    public const MANAGE_PERMISSION = 'front-office.spa.manage';

    public const OPERATE_PERMISSION = 'front-office.spa.operate';

    public const METHODS = ['cash', 'card', 'qris'];

    public const PAID_EVENT = 'frontoffice.spa.paid';

    public const SOURCE = 'spa';

    public const CHARGE_CODE = 'SPA';

    public function __construct(
        private SpaStore $store,
        private StayRepository $stays,
        private ReservationRepository $reservations,
        private RoomCatalogReader $rooms,
        private FolioService $folios,
        private ChargeCalculator $charges,
        private ShiftAttribution $shifts,
        private PermissionChecker $permissions,
        private StaffDirectory $staff,
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

    /** The scheme the price is charged with: the one named for activities (`rooms` unless the hotel chose another). */
    public static function chargeScope(): string
    {
        return (string) config('frontdesk.spa.charge_scope', 'rooms');
    }

    // ---- the board ----

    /** A person whose only work in the front office is the spa on the phone (a therapist): the front desk's day is not theirs, their own page is. */
    public function worksSpaOnly(PropertyId $property, string $actorId): bool
    {
        return $this->may($property, $actorId, self::OPERATE_PERMISSION) && ! $this->mayUseDesk($property, $actorId)
            && ! $this->may($property, $actorId, 'front-office.stay.view') && ! $this->may($property, $actorId, 'front-office.reservation.view');
    }

    /** Whether the person looks at the board and books (the front desk), as against working a treatment on the phone only (a therapist). */
    public function mayUseDesk(PropertyId $property, string $actorId): bool
    {
        return $this->may($property, $actorId, self::VIEW_PERMISSION) || $this->may($property, $actorId, self::BOOK_PERMISSION) || $this->may($property, $actorId, self::MANAGE_PERMISSION);
    }

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId, ?string $date = null): array
    {
        $this->requireAny($property, $actorId, [self::VIEW_PERMISSION, self::BOOK_PERMISSION, self::MANAGE_PERMISSION], 'This person may not see the spa.');
        $today = $this->businessDate->current($property)->toString();
        $day = $date === null || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1 ? $today : $date;
        $bookings = array_map(SpaView::booking(...), $this->store->bookingsOn($property, $day));
        $present = $this->store->attendanceOn($property, $day);
        $room = [];

        foreach ($this->rooms->activeRooms($property) as $r) {
            $room[$r->id] = $r->number;
        }

        $mayManage = $this->may($property, $actorId, self::MANAGE_PERMISSION);
        $guests = [];

        foreach ($this->stays->inHouse($property) as $stay) {
            $guests[] = ['reservation_id' => $stay->reservationId, 'guest_name' => $this->reservations->find($property, $stay->reservationId)?->guestName ?? '', 'room' => $room[$stay->roomId] ?? ''];
        }

        usort($guests, static fn (array $a, array $b): int => strnatcmp($a['room'], $b['room']));
        $rooms = [];

        foreach ($this->store->rooms($property) as $r) {
            $mine = array_values(array_filter($bookings, static fn (array $b): bool => $b['room_id'] === $r['id'] && ! in_array($b['status'], ['cancelled', 'no_show', 'declined'], true)));
            $rooms[] = [
                'id' => $r['id'], 'code' => $r['code'], 'name' => $r['name'], 'is_active' => (bool) $r['is_active'], 'lock_version' => (int) $r['lock_version'],
                'running' => array_values(array_filter($mine, static fn (array $b): bool => $b['status'] === 'in_progress'))[0] ?? null,
                'bookings' => $mine,
            ];
        }

        return [
            'date' => $day, 'today' => $today, 'now' => $this->clock->nowUtc()->format('Y-m-d\TH:i:s\Z'), 'currency' => $this->currency->currencyOf($property), 'methods' => self::METHODS,
            'rooms' => $rooms, 'bookings' => $bookings,
            'treatments' => array_map(static fn (array $t): array => ['id' => $t['id'], 'name' => $t['name'], 'minutes' => (int) $t['minutes'], 'price_minor' => (int) $t['price_minor'], 'is_active' => (bool) $t['is_active'], 'lock_version' => (int) $t['lock_version']], $this->store->treatments($property)),
            'therapists' => array_map(static fn (array $t): array => ['id' => $t['id'], 'name' => $t['name'], 'user_id' => $mayManage ? $t['user_id'] : null, 'is_active' => (bool) $t['is_active'], 'present' => isset($present[$t['id']]) && $present[$t['id']]['clock_out_at'] === null,
                'clock_in_at' => $present[$t['id']]['clock_in_at'] ?? null, 'clock_out_at' => $present[$t['id']]['clock_out_at'] ?? null,
                'commission_bp' => $mayManage ? (int) $t['commission_bp'] : null, 'lock_version' => (int) $t['lock_version']], $this->store->therapists($property)),
            'guests' => $guests,
            // Who can be linked to a therapist: the people who hold the right to work the spa on the phone.
            'staff' => $mayManage ? $this->staff->withPermission($property, self::OPERATE_PERMISSION) : [],
            'may' => ['book' => $this->may($property, $actorId, self::BOOK_PERMISSION), 'manage' => $mayManage],
        ];
    }

    // ---- set-up: rooms, treatments and therapists ----

    public function addRoom(PropertyId $property, string $actorId, string $code, string $name): void
    {
        $this->require($property, $actorId, self::MANAGE_PERMISSION, 'This person may not set up the spa.');
        $code = strtoupper(trim($code));
        $name = trim($name);

        if (preg_match('/^[A-Z0-9-]{1,12}$/D', $code) !== 1 || $name === '' || mb_strlen($name) > 60) {
            throw Refusal::invalid('Give a short code of letters and digits and a name of at most 60 characters.', ['code', 'name']);
        }

        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actorId, $id, $code, $name): void {
            if (! $this->store->addRoom($property, ['id' => $id, 'code' => $code, 'name' => $name, 'is_active' => true], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('A treatment room has this code already.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'spa.room.added', 'spa_room', $id, null, ['code' => $code, 'name' => $name]));
        });
    }

    public function changeRoom(PropertyId $property, string $actorId, string $id, string $name, bool $active, int $lock): void
    {
        $this->require($property, $actorId, self::MANAGE_PERMISSION, 'This person may not set up the spa.');
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 60) {
            throw Refusal::invalid('Give a name of at most 60 characters.', ['name']);
        }

        $this->transactions->run(function () use ($property, $actorId, $id, $name, $active, $lock): void {
            $before = $this->store->room($property, strtolower($id)) ?? throw Refusal::notFound('Treatment room not found.');

            if (! $this->store->updateRoom($property, $before['id'], $lock, ['name' => $name, 'is_active' => $active], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This room changed after you opened it. Reload.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'spa.room.changed', 'spa_room', $before['id'], ['name' => $before['name'], 'is_active' => $before['is_active']], ['name' => $name, 'is_active' => $active]));
        });
    }

    public function addTreatment(PropertyId $property, string $actorId, string $name, int $minutes, int $priceMinor): void
    {
        $this->require($property, $actorId, self::MANAGE_PERMISSION, 'This person may not set up the spa.');
        $name = $this->checkTreatment($name, $minutes, $priceMinor);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actorId, $id, $name, $minutes, $priceMinor): void {
            if (! $this->store->addTreatment($property, ['id' => $id, 'name' => $name, 'minutes' => $minutes, 'price_minor' => $priceMinor, 'is_active' => true], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('A treatment has this name already.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'spa.treatment.added', 'spa_treatment', $id, null, ['name' => $name, 'minutes' => $minutes, 'price_minor' => $priceMinor]));
        });
    }

    public function changeTreatment(PropertyId $property, string $actorId, string $id, string $name, int $minutes, int $priceMinor, bool $active, int $lock): void
    {
        $this->require($property, $actorId, self::MANAGE_PERMISSION, 'This person may not set up the spa.');
        $name = $this->checkTreatment($name, $minutes, $priceMinor);

        $this->transactions->run(function () use ($property, $actorId, $id, $name, $minutes, $priceMinor, $active, $lock): void {
            $before = $this->store->treatment($property, strtolower($id)) ?? throw Refusal::notFound('Treatment not found.');

            if (! $this->store->updateTreatment($property, $before['id'], $lock, ['name' => $name, 'minutes' => $minutes, 'price_minor' => $priceMinor, 'is_active' => $active], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This treatment changed after you opened it, or the name is used. Reload.');
            }

            // A booking keeps the price and the length it was made with.
            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'spa.treatment.changed', 'spa_treatment', $before['id'], ['name' => $before['name'], 'minutes' => (int) $before['minutes'], 'price_minor' => (int) $before['price_minor'], 'is_active' => $before['is_active']], ['name' => $name, 'minutes' => $minutes, 'price_minor' => $priceMinor, 'is_active' => $active]));
        });
    }

    public function addTherapist(PropertyId $property, string $actorId, string $name, ?string $userId, int $commissionBp): void
    {
        $this->require($property, $actorId, self::MANAGE_PERMISSION, 'This person may not set up the spa.');
        $name = $this->checkTherapist($property, null, $name, $userId, $commissionBp);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actorId, $id, $name, $userId, $commissionBp): void {
            if (! $this->store->addTherapist($property, ['id' => $id, 'name' => $name, 'user_id' => $userId === null ? null : strtolower($userId), 'commission_bp' => $commissionBp, 'is_active' => true], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('A therapist has this name already.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'spa.therapist.added', 'spa_therapist', $id, null, ['name' => $name, 'commission_bp' => $commissionBp, 'signs_in' => $userId !== null]));
        });
    }

    public function changeTherapist(PropertyId $property, string $actorId, string $id, string $name, ?string $userId, int $commissionBp, bool $active, int $lock): void
    {
        $this->require($property, $actorId, self::MANAGE_PERMISSION, 'This person may not set up the spa.');
        $name = $this->checkTherapist($property, strtolower($id), $name, $userId, $commissionBp);

        $this->transactions->run(function () use ($property, $actorId, $id, $name, $userId, $commissionBp, $active, $lock): void {
            $before = $this->store->therapist($property, strtolower($id)) ?? throw Refusal::notFound('Therapist not found.');

            if (! $this->store->updateTherapist($property, $before['id'], $lock, ['name' => $name, 'user_id' => $userId === null ? null : strtolower($userId), 'commission_bp' => $commissionBp, 'is_active' => $active], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This therapist changed after you opened it, or the name is used. Reload.');
            }

            // The share a booking was made with is kept on the booking: changing it here changes later bookings only.
            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'spa.therapist.changed', 'spa_therapist', $before['id'], ['name' => $before['name'], 'commission_bp' => (int) $before['commission_bp'], 'is_active' => $before['is_active']], ['name' => $name, 'commission_bp' => $commissionBp, 'is_active' => $active]));
        });
    }

    // ---- bookings ----

    /**
     * @param  array{room_id: string, treatment_id: string, therapist_id: string, date: string, time: string, guest_name: string, reservation_id: ?string, waive_service_charge: bool, waive_tax: bool}  $in
     * @return array<string, mixed>
     */
    public function book(PropertyId $property, string $actorId, IdempotencyKey $key, array $in): array
    {
        $this->require($property, $actorId, self::BOOK_PERMISSION, 'This person may not book the spa.');
        $today = $this->businessDate->current($property)->toString();
        $guestName = trim($in['guest_name']);
        $start = DayUseTimes::minutes($in['time']);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $in['date']) !== 1 || $in['date'] < $today || $in['date'] > DateMath::addDays($today, 60)) {
            throw Refusal::invalid('The day is today or later, within 60 days.', ['date']);
        }

        if ($start === null) {
            throw Refusal::invalid('Give the start as hours and minutes, like 14:30.', ['time']);
        }

        if ($guestName === '' || mb_strlen($guestName) > 120) {
            throw Refusal::invalid('Give the guest name, at most 120 characters.', ['guest_name']);
        }

        if (($in['waive_service_charge'] || $in['waive_tax']) && ! $this->may($property, $actorId, self::MANAGE_PERMISSION)) {
            throw Refusal::forbidden('Only a manager may leave off the service charge or the tax.');
        }

        $room = $this->store->room($property, strtolower($in['room_id']));
        $treatment = $this->store->treatment($property, strtolower($in['treatment_id']));
        $therapist = $this->store->therapist($property, strtolower($in['therapist_id']));

        if ($room === null || ! (bool) $room['is_active']) {
            throw Refusal::invalid('Choose a treatment room.', ['room_id']);
        }

        if ($treatment === null || ! (bool) $treatment['is_active']) {
            throw Refusal::invalid('Choose a treatment.', ['treatment_id']);
        }

        if ($therapist === null || ! (bool) $therapist['is_active']) {
            throw Refusal::invalid('Choose a therapist.', ['therapist_id']);
        }

        $minutes = (int) $treatment['minutes'];
        $end = $start + $minutes;

        if ($end > 1440) {
            throw Refusal::invalid('A treatment ends on the day it starts: choose an earlier start.', ['time']);
        }

        $guestRoom = null;
        $reservationId = $in['reservation_id'] === null || $in['reservation_id'] === '' ? null : strtolower($in['reservation_id']);

        if ($reservationId !== null) {
            $stay = null;

            foreach ($this->stays->inHouse($property) as $candidate) {
                if ($candidate->reservationId === $reservationId) {
                    $stay = $candidate;
                }
            }

            if ($stay === null) {
                throw Refusal::stateConflict('This guest is not in the house, so the treatment cannot be charged to a room.');
            }

            $guestRoom = $this->rooms->room($property, $stay->roomId)?->number;
        }

        $actor = strtolower($actorId);
        $result = $this->executor->execute(
            new IdempotencyRequest($property, $key, 'spa.book', ['room' => $room['id'], 'treatment' => $treatment['id'], 'therapist' => $therapist['id'], 'date' => $in['date'], 'time' => $in['time'], 'guest' => $guestName, 'reservation' => $reservationId, 'waive' => [$in['waive_service_charge'], $in['waive_tax']]], $actor),
            function () use ($property, $actor, $room, $treatment, $therapist, $in, $start, $end, $minutes, $guestName, $reservationId, $guestRoom): array {
                $this->store->lockRoomAndTherapist($property, $room['id'], $therapist['id']);

                if (! DayUseTimes::free($this->store->holding($property, 'room_id', $room['id'], $in['date'], null), $start, $end)) {
                    throw Refusal::stateConflict('This treatment room is taken at that time.', 'spa_room_taken');
                }

                if (! DayUseTimes::free($this->store->holding($property, 'therapist_id', $therapist['id'], $in['date'], null), $start, $end)) {
                    throw Refusal::stateConflict('This therapist has another booking at that time.', 'spa_therapist_taken');
                }

                $id = $this->ids->next();
                $number = $this->numbers->next($property, 'SPA');
                $this->store->addBooking($property, [
                    'id' => $id, 'number' => $number, 'room_id' => $room['id'], 'room_name' => $room['name'], 'treatment_id' => $treatment['id'], 'treatment_name' => $treatment['name'], 'minutes' => $minutes,
                    'therapist_id' => $therapist['id'], 'therapist_name' => $therapist['name'], 'booking_date' => $in['date'], 'start_minute' => $start, 'guest_name' => $guestName, 'reservation_id' => $reservationId, 'guest_room' => $guestRoom,
                    'price_minor' => (int) $treatment['price_minor'], 'waive_service_charge' => $in['waive_service_charge'], 'waive_tax' => $in['waive_tax'], 'status' => 'booked',
                    'commission_bp' => (int) $therapist['commission_bp'], 'created_by' => $actor,
                ], $this->clock->nowUtc());
                $this->audit->record(new AuditEntry($property->toString(), $actor, 'spa.booked', 'spa_booking', $id, null, ['number' => $number, 'room' => $room['name'], 'date' => $in['date'], 'from' => DayUseTimes::time($start), 'until' => DayUseTimes::time($end), 'treatment' => $treatment['name'], 'therapist' => $therapist['name'], 'charged_to_room' => $reservationId !== null, 'waive_service_charge' => $in['waive_service_charge'], 'waive_tax' => $in['waive_tax']]));

                return ['booking_id' => $id];
            },
        );

        return ['booking' => SpaView::booking($this->store->booking($property, (string) $result->payload['booking_id']) ?? throw Refusal::notFound('Booking not found.')), 'replayed' => $result->replayed];
    }

    /** A booking the therapist declined, or one not yet answered, goes to another therapist and waits for their answer. @return array<string, mixed> */
    public function assign(PropertyId $property, string $actorId, string $id, string $therapistId, int $lock): array
    {
        $this->require($property, $actorId, self::BOOK_PERMISSION, 'This person may not book the spa.');
        $b = $this->openBooking($property, $id, ['booked', 'declined']);
        $therapist = $this->store->therapist($property, strtolower($therapistId));

        if ($therapist === null || ! (bool) $therapist['is_active']) {
            throw Refusal::invalid('Choose a therapist.', ['therapist_id']);
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $b, $therapist, $lock): void {
            $this->store->lockRoomAndTherapist($property, $b['room_id'], $therapist['id']);
            $start = (int) $b['start_minute'];

            if (! DayUseTimes::free($this->store->holding($property, 'therapist_id', $therapist['id'], $b['booking_date'], $b['id']), $start, $start + (int) $b['minutes'])) {
                throw Refusal::stateConflict('This therapist has another booking at that time.', 'spa_therapist_taken');
            }

            if (! $this->store->updateBooking($property, $b['id'], $lock, ['therapist_id' => $therapist['id'], 'therapist_name' => $therapist['name'], 'commission_bp' => (int) $therapist['commission_bp'], 'status' => 'booked', 'reason' => null], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This booking changed after you opened it. Reload.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'spa.assigned', 'spa_booking', $b['id'], ['therapist' => $b['therapist_name'], 'status' => $b['status']], ['therapist' => $therapist['name'], 'status' => 'booked']));
        });

        return SpaView::booking($this->store->booking($property, $b['id']) ?? throw Refusal::notFound('Booking not found.'));
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

    /**
     * A guest who is not staying pays for the treatment that was done: the price with the service charge and tax (unless left off), by cash, card or QRIS. It is revenue of the day, and cash is in the cashier's drawer.
     *
     * @return array<string, mixed>
     */
    public function pay(PropertyId $property, string $actorId, string $id, string $method, ?string $reference, int $lock): array
    {
        $this->require($property, $actorId, self::BOOK_PERMISSION, 'This person may not take payments of the spa.');
        $reference = $reference === null || trim($reference) === '' ? null : mb_substr(trim($reference), 0, 60);

        if (! in_array($method, self::METHODS, true)) {
            throw Refusal::invalid('Choose how it is paid: cash, card or QRIS.', ['method']);
        }

        if ($method !== 'cash' && ($reference === null || mb_strlen($reference) < 4)) {
            throw Refusal::invalid('Give the approval code or QRIS reference of the payment.', ['reference']);
        }

        $b = $this->openBooking($property, $id, ['done']);

        if ($b['folio_posting_id'] !== null || $b['payment_method'] !== null) {
            throw Refusal::stateConflict('This treatment is paid already.');
        }

        $today = $this->businessDate->current($property);
        $actor = strtolower($actorId);
        $split = $this->charges->breakdown($property, self::chargeScope(), $today, (int) $b['price_minor'], false, self::waived($b));
        $currency = $this->currency->currencyOf($property);

        $this->transactions->run(function () use ($property, $actor, $b, $method, $reference, $lock, $split, $today, $currency): void {
            $shift = null;

            if ($method === 'cash') {
                $this->shifts->assertMayHandleMoney($property, $actor);
                $shift = $this->store->openShiftOf($property, $actor);
            }

            if (! $this->store->updateBooking($property, $b['id'], $lock, [
                'service_charge_minor' => $split['service_charge_minor'], 'tax_minor' => $split['tax_minor'], 'total_minor' => $split['total_minor'], 'payment_method' => $method, 'payment_reference' => $reference, 'shift_id' => $shift, 'paid_on' => $today->toString(),
            ], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This booking changed after you opened it. Reload.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'spa.paid', 'spa_booking', $b['id'], null, ['number' => $b['number'], 'method' => $method, 'total_minor' => $split['total_minor']]));
            $this->outbox->publish(new OutboxEvent($property, self::PAID_EVENT, $b['id'], 1, [
                'bill_id' => $b['id'], 'bill_number' => $b['number'], 'outlet_id' => $b['room_id'], 'outlet_code' => 'SPA', 'source' => self::SOURCE, 'settled_business_date' => $today->toString(), 'currency' => $currency,
                'base_minor' => $split['base_minor'], 'service_charge_minor' => $split['service_charge_minor'], 'tax_minor' => $split['tax_minor'], 'total_minor' => $split['total_minor'],
                'payments' => [['method' => $method, 'amount_minor' => $split['total_minor']]], 'actor_id' => $actor,
            ]));
        });

        return SpaView::booking($this->store->booking($property, $b['id']) ?? throw Refusal::notFound('Booking not found.'));
    }

    // ---- the manager's report ----

    /** @return array<string, mixed> */
    public function report(PropertyId $property, string $actorId, ?string $from, ?string $to): array
    {
        $this->require($property, $actorId, self::MANAGE_PERMISSION, 'This person may not see the spa report.');
        $today = $this->businessDate->current($property)->toString();
        $to = $to === null || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $to) !== 1 ? $today : $to;
        $from = $from === null || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $from) !== 1 ? substr($to, 0, 8).'01' : $from;

        if ($from > $to || DateMath::daysBetween($from, $to) > 366) {
            throw Refusal::invalid('The period runs from a day to a later one, at most a year.', ['from', 'to']);
        }

        $byTherapist = [];
        $byTreatment = [];
        $money = ['base_minor' => 0, 'service_charge_minor' => 0, 'tax_minor' => 0, 'total_minor' => 0, 'charged_to_room_minor' => 0, 'paid_at_desk_minor' => 0, 'unpaid_minor' => 0];
        $count = 0;

        foreach ($this->store->doneBetween($property, $from, $to) as $b) {
            $count++;
            $t = &$byTherapist[$b['therapist_id']];
            $t ??= ['therapist_id' => $b['therapist_id'], 'name' => $b['therapist_name'], 'treatments' => 0, 'revenue_minor' => 0, 'commission_minor' => 0];
            $t['treatments']++;
            $t['revenue_minor'] += (int) $b['price_minor'];
            $t['commission_minor'] += (int) $b['commission_minor'];
            unset($t);

            $k = &$byTreatment[$b['treatment_name']];
            $k ??= ['name' => $b['treatment_name'], 'count' => 0, 'revenue_minor' => 0];
            $k['count']++;
            $k['revenue_minor'] += (int) $b['price_minor'];
            unset($k);

            $money['base_minor'] += (int) $b['price_minor'];
            $money['service_charge_minor'] += (int) $b['service_charge_minor'];
            $money['tax_minor'] += (int) $b['tax_minor'];
            $money['total_minor'] += (int) $b['total_minor'];

            if ($b['folio_posting_id'] !== null) {
                $money['charged_to_room_minor'] += (int) $b['total_minor'];
            } elseif ($b['payment_method'] !== null) {
                $money['paid_at_desk_minor'] += (int) $b['total_minor'];
            } else {
                $money['unpaid_minor'] += (int) $b['price_minor'];
            }
        }

        $treatments = array_values($byTreatment);
        usort($treatments, static fn (array $a, array $b): int => [$b['count'], $b['revenue_minor'], $a['name']] <=> [$a['count'], $a['revenue_minor'], $b['name']]);
        $therapists = array_values($byTherapist);
        usort($therapists, static fn (array $a, array $b): int => [$b['commission_minor'], $a['name']] <=> [$a['commission_minor'], $b['name']]);

        return ['from' => $from, 'to' => $to, 'currency' => $this->currency->currencyOf($property), 'treatments_done' => $count, 'therapists' => $therapists, 'treatments' => $treatments, 'money' => $money];
    }

    // ---- shared ----

    /** @param array<string, mixed> $b @return list<string> */
    public static function waived(array $b): array
    {
        return [...((bool) $b['waive_service_charge'] ? ['service_charge'] : []), ...((bool) $b['waive_tax'] ? ['tax'] : [])];
    }

    /** @param list<string> $statuses @return array<string, mixed> */
    private function openBooking(PropertyId $property, string $id, array $statuses): array
    {
        $this->store->lockBooking($property, strtolower($id));
        $b = $this->store->booking($property, strtolower($id)) ?? throw Refusal::notFound('Booking not found.');

        if (! in_array($b['status'], $statuses, true)) {
            throw Refusal::stateConflict('This booking is not in a state that allows that.');
        }

        return $b;
    }

    /** @return array<string, mixed> */
    private function settle(PropertyId $property, string $actorId, string $id, int $lock, string $to, ?string $reason): array
    {
        $this->require($property, $actorId, self::BOOK_PERMISSION, 'This person may not book the spa.');
        $b = $this->openBooking($property, $id, ['booked', 'accepted', 'declined']);
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $b, $lock, $to, $reason): void {
            if (! $this->store->updateBooking($property, $b['id'], $lock, ['status' => $to, 'reason' => $reason], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This booking changed after you opened it. Reload.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'spa.'.$to, 'spa_booking', $b['id'], ['status' => $b['status']], ['status' => $to, 'number' => $b['number']], $reason));
        });

        return SpaView::booking($this->store->booking($property, $b['id']) ?? throw Refusal::notFound('Booking not found.'));
    }

    private function checkTreatment(string $name, int $minutes, int $priceMinor): string
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 80 || $minutes < 5 || $minutes > 480 || $priceMinor < 1) {
            throw Refusal::invalid('Give a name of at most 80 characters, a length of 5 to 480 minutes and a price above zero.', ['name', 'minutes', 'price_minor']);
        }

        return $name;
    }

    private function checkTherapist(PropertyId $property, ?string $id, string $name, ?string $userId, int $commissionBp): string
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 80 || $commissionBp < 0 || $commissionBp > 10000) {
            throw Refusal::invalid('Give a name of at most 80 characters and a commission from 0 to 100 percent.', ['name', 'commission']);
        }

        if ($userId !== null && $userId !== '') {
            $taken = $this->store->therapistOfUser($property, strtolower($userId));

            if ($taken !== null && $taken['id'] !== $id) {
                throw Refusal::stateConflict('This account is another therapist\'s already.');
            }
        }

        return $name;
    }

    private function may(PropertyId $property, string $actorId, string $permission): bool
    {
        return $this->permissions->allowsInProperty($actorId, $permission, $property);
    }

    private function require(PropertyId $property, string $actorId, string $permission, string $message): void
    {
        if (! $this->may($property, $actorId, $permission)) {
            throw Refusal::forbidden($message);
        }
    }

    /** @param list<string> $permissions */
    private function requireAny(PropertyId $property, string $actorId, array $permissions, string $message): void
    {
        foreach ($permissions as $permission) {
            if ($this->may($property, $actorId, $permission)) {
                return;
            }
        }

        throw Refusal::forbidden($message);
    }
}
