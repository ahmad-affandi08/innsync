<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Activities;

use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Reservations\ReservationRepository;
use App\Modules\FrontOffice\Application\Stays\StayRepository;
use App\Modules\FrontOffice\Domain\Reservations\ReservationStatus;
use App\Modules\Property\Application\Catalog\RoomCatalogReader;
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
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\DateMath;

/**
 * Spa and activities (docs/OPERATIONS/SPA-ACTIVITIES.md). A service has a length, a price and how many guests can take part at once; a booking is for a guest who is in the house, at a day and a time, and is
 * refused when it would pass that number at any moment. When the service has been given, the booking is marked done and its price is charged to the guest's folio, once, with the service charge and tax of the
 * scheme named in `config/frontdesk.php`. A booking that is not taken up is cancelled with a reason or marked no-show.
 */
final readonly class ActivityService
{
    public const VIEW_PERMISSION = 'front-office.activity.view';

    public const BOOK_PERMISSION = 'front-office.activity.book';

    public const MANAGE_PERMISSION = 'front-office.activity.manage';

    public const CATEGORIES = ['spa', 'tour', 'sport', 'other'];

    public const CHARGE_CODE = 'ACT';

    public function __construct(
        private ActivityStore $store,
        private StayRepository $stays,
        private ReservationRepository $reservations,
        private RoomCatalogReader $rooms,
        private FolioService $folios,
        private PermissionChecker $permissions,
        private PropertyCurrencyReader $currency,
        private BusinessDateProvider $businessDate,
        private DocumentNumbers $numbers,
        private IdempotentExecutor $executor,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId): array
    {
        if (! $this->may($property, $actorId, self::VIEW_PERMISSION) && ! $this->may($property, $actorId, self::BOOK_PERMISSION) && ! $this->may($property, $actorId, self::MANAGE_PERMISSION)) {
            throw Refusal::forbidden('This person may not see spa and activities.');
        }

        $today = $this->businessDate->current($property)->toString();
        $room = [];

        foreach ($this->rooms->activeRooms($property) as $r) {
            $room[$r->id] = $r->number;
        }

        $guests = [];

        foreach ($this->stays->inHouse($property) as $stay) {
            $guests[] = ['reservation_id' => $stay->reservationId, 'guest_name' => $this->reservations->find($property, $stay->reservationId)?->guestName ?? '', 'room' => $room[$stay->roomId] ?? '', 'departure' => $stay->expectedDeparture->toString()];
        }

        foreach ($this->store->upcomingReservations($property, $today, DateMath::addDays($today, 30)) as $u) {
            $guests[] = ['reservation_id' => $u['id'], 'guest_name' => $u['guest_name'], 'room' => '', 'departure' => $u['departure'], 'arrival' => $u['arrival']];
        }

        usort($guests, static fn (array $a, array $b): int => [isset($a['arrival']), strnatcmp($a['room'], $b['room'])] <=> [isset($b['arrival']), 0]);

        return [
            'currency' => $this->currency->currencyOf($property), 'today' => $today, 'categories' => self::CATEGORIES,
            'services' => array_map(static fn (array $s): array => ['id' => $s['id'], 'code' => $s['code'], 'name' => $s['name'], 'category' => $s['category'], 'duration_minutes' => (int) $s['duration_minutes'], 'price_minor' => (int) $s['price_minor'], 'capacity' => (int) $s['capacity'], 'is_active' => (bool) $s['is_active'], 'lock_version' => (int) $s['lock_version']], $this->store->services($property)),
            'bookings' => array_map(static fn (array $b): array => ['id' => $b['id'], 'number' => $b['number'], 'service_id' => $b['service_id'], 'service_name' => $b['service_name'], 'starts_on' => substr((string) $b['starts_on'], 0, 10), 'starts_at' => $b['starts_at'], 'duration_minutes' => (int) $b['duration_minutes'], 'guests' => (int) $b['guests'], 'guest_name' => $b['guest_name'], 'price_minor' => (int) $b['price_minor'], 'status' => $b['status'], 'note' => $b['note'], 'reason' => $b['reason'], 'lock_version' => (int) $b['lock_version']], $this->store->bookings($property, DateMath::addDays($today, -7), 300)),
            'in_house' => $guests,
            'may' => ['book' => $this->may($property, $actorId, self::BOOK_PERMISSION), 'manage' => $this->may($property, $actorId, self::MANAGE_PERMISSION)],
        ];
    }

    public function addService(PropertyId $property, string $actorId, string $code, string $name, string $category, int $minutes, int $priceMinor, int $capacity): void
    {
        $this->manage($property, $actorId);
        [$code, $name] = $this->checkService($code, $name, $category, $minutes, $priceMinor, $capacity);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actorId, $id, $code, $name, $category, $minutes, $priceMinor, $capacity): void {
            if (! $this->store->addService($property, ['id' => $id, 'code' => $code, 'name' => $name, 'category' => $category, 'duration_minutes' => $minutes, 'price_minor' => $priceMinor, 'capacity' => $capacity], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This code is used by another service.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'activity.service_added', 'activity_service', $id, null, ['code' => $code, 'name' => $name, 'price_minor' => $priceMinor, 'capacity' => $capacity]));
        });
    }

    /** A new price, length or capacity counts for bookings made from now on; bookings made keep what they were made with. */
    public function changeService(PropertyId $property, string $actorId, string $id, string $name, int $minutes, int $priceMinor, int $capacity, bool $active, int $lock): void
    {
        $this->manage($property, $actorId);
        $before = $this->store->service($property, strtolower($id)) ?? throw Refusal::notFound('Service not found.');
        [, $name] = $this->checkService((string) $before['code'], $name, (string) $before['category'], $minutes, $priceMinor, $capacity);

        $this->transactions->run(function () use ($property, $actorId, $before, $name, $minutes, $priceMinor, $capacity, $active, $lock): void {
            if (! $this->store->updateService($property, $before['id'], $lock, ['name' => $name, 'duration_minutes' => $minutes, 'price_minor' => $priceMinor, 'capacity' => $capacity, 'is_active' => $active], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This service changed after you opened it. Reload.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'activity.service_changed', 'activity_service', $before['id'], ['price_minor' => (int) $before['price_minor'], 'capacity' => (int) $before['capacity'], 'is_active' => (bool) $before['is_active']], ['price_minor' => $priceMinor, 'capacity' => $capacity, 'is_active' => $active]));
        });
    }

    /** @return array{booking: array<string, mixed>, replayed: bool} */
    public function book(PropertyId $property, string $actorId, IdempotencyKey $key, string $serviceId, string $reservationId, string $day, string $time, int $guests, ?string $note): array
    {
        if (! $this->may($property, $actorId, self::BOOK_PERMISSION)) {
            throw Refusal::forbidden('This person may not book spa and activities.');
        }

        $start = ActivityCapacity::minutes($time) ?? throw Refusal::invalid('Give the time as hours and minutes, for example 14:30.', ['starts_at']);
        $today = $this->businessDate->current($property)->toString();

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) !== 1 || $day < $today || $day > DateMath::addDays($today, 365)) {
            throw Refusal::invalid('The day is today or later, within a year.', ['starts_on']);
        }

        if ($guests < 1 || $guests > 50) {
            throw Refusal::invalid('Give how many guests take part, from 1 to 50.', ['guests']);
        }

        $note = $note === null || trim($note) === '' ? null : trim($note);

        if ($note !== null && mb_strlen($note) > 200) {
            throw Refusal::invalid('The note is at most 200 characters.', ['note']);
        }

        $actor = strtolower($actorId);
        $result = $this->executor->execute(
            new IdempotencyRequest($property, $key, 'activity.book', ['service' => strtolower($serviceId), 'reservation' => strtolower($reservationId), 'day' => $day, 'time' => $time, 'guests' => $guests, 'note' => $note], $actor),
            function () use ($property, $actor, $serviceId, $reservationId, $day, $time, $start, $guests, $note): array {
                $service = $this->store->lockService($property, strtolower($serviceId)) ?? throw Refusal::notFound('Service not found.');

                if (! (bool) $service['is_active']) {
                    throw Refusal::stateConflict('This service is not offered now.');
                }

                $end = $start + (int) $service['duration_minutes'];

                if ($end > 24 * 60) {
                    throw Refusal::invalid('The service must end before midnight.', ['starts_at']);
                }

                // A guest who is in the house, or who has a confirmed reservation and is still to arrive (the charge is made only once the guest is in the house).
                $reservation = $this->reservations->find($property, strtolower($reservationId));

                if ($reservation === null || ! in_array($reservation->status, [ReservationStatus::Confirmed, ReservationStatus::Guaranteed, ReservationStatus::CheckedIn], true)) {
                    throw Refusal::invalid('Choose a guest who is in the house or has a confirmed reservation.', ['reservation_id']);
                }

                if ($day < $reservation->stay->arrival->toString() || $day > $reservation->stay->departure->toString()) {
                    throw Refusal::invalid('The day is outside the guest\'s stay.', ['starts_on']);
                }

                if (! ActivityCapacity::fits($this->store->holdingPlaces($property, $service['id'], $day), $start, $end, $guests, (int) $service['capacity'])) {
                    throw Refusal::stateConflict('This service is full at that time. Choose another time.');
                }

                $id = $this->ids->next();
                $number = $this->numbers->next($property, 'ACT');
                $price = (int) $service['price_minor'] * $guests;
                $this->store->addBooking($property, [
                    'id' => $id, 'number' => $number, 'service_id' => $service['id'], 'starts_on' => $day, 'starts_at' => $time, 'duration_minutes' => (int) $service['duration_minutes'], 'guests' => $guests, 'reservation_id' => $reservation->id,
                    'guest_name' => mb_substr($reservation->guestName, 0, 120), 'service_name' => $service['name'], 'price_minor' => $price, 'note' => $note, 'created_by' => $actor,
                ], $this->clock->nowUtc());
                $this->audit->record(new AuditEntry($property->toString(), $actor, 'activity.booked', 'activity_booking', $id, null, ['number' => $number, 'service' => $service['name'], 'day' => $day, 'time' => $time, 'guests' => $guests, 'price_minor' => $price]));

                return ['booking_id' => $id];
            },
        );

        return ['booking' => $this->shape($this->store->booking($property, (string) $result->payload['booking_id']) ?? throw Refusal::notFound('Booking not found.')), 'replayed' => $result->replayed];
    }

    /** The service was given: its price is charged to the guest's folio, once. @return array<string, mixed> */
    public function complete(PropertyId $property, string $actorId, string $id, int $lock): array
    {
        $b = $this->openBooking($property, $actorId, $id);

        if (substr((string) $b['starts_on'], 0, 10) > $this->businessDate->current($property)->toString()) {
            throw Refusal::stateConflict('This booking is for a later day.');
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $b, $lock): void {
            $posting = null;

            if ((int) $b['price_minor'] > 0) {
                $charged = $this->folios->postGuestCharge($property, $actor, $b['reservation_id'], (string) config('frontdesk.activities.charge_scope'), self::CHARGE_CODE, mb_substr($b['service_name'].' ×'.$b['guests'].' · '.$b['number'], 0, 120), (int) $b['price_minor'], 'activity', $b['id']);
                $posting = $charged['posting']['id'];
            }

            if (! $this->store->updateBooking($property, $b['id'], $lock, ['status' => 'done', 'posting_id' => $posting], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This booking changed after you opened it. Reload.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'activity.done', 'activity_booking', $b['id'], ['status' => 'booked'], ['status' => 'done', 'number' => $b['number'], 'price_minor' => (int) $b['price_minor']]));
        });

        return $this->shape($this->store->booking($property, $b['id']) ?? throw Refusal::notFound('Booking not found.'));
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

    /** The guest did not come. @return array<string, mixed> */
    public function noShow(PropertyId $property, string $actorId, string $id, int $lock): array
    {
        return $this->settle($property, $actorId, $id, $lock, 'no_show', null);
    }

    /** @return array<string, mixed> */
    private function settle(PropertyId $property, string $actorId, string $id, int $lock, string $status, ?string $reason): array
    {
        $b = $this->openBooking($property, $actorId, $id);

        if ($status === 'no_show' && substr((string) $b['starts_on'], 0, 10) > $this->businessDate->current($property)->toString()) {
            throw Refusal::stateConflict('This booking is for a later day.');
        }

        $this->transactions->run(function () use ($property, $actorId, $b, $lock, $status, $reason): void {
            if (! $this->store->updateBooking($property, $b['id'], $lock, ['status' => $status, 'reason' => $reason], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This booking changed after you opened it. Reload.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'activity.'.$status, 'activity_booking', $b['id'], ['status' => 'booked'], ['status' => $status, 'number' => $b['number']], $reason));
        });

        return $this->shape($this->store->booking($property, $b['id']) ?? throw Refusal::notFound('Booking not found.'));
    }

    /** @return array<string, mixed> */
    private function openBooking(PropertyId $property, string $actorId, string $id): array
    {
        if (! $this->may($property, $actorId, self::BOOK_PERMISSION)) {
            throw Refusal::forbidden('This person may not work on spa and activity bookings.');
        }

        $b = $this->store->booking($property, strtolower($id)) ?? throw Refusal::notFound('Booking not found.');

        if ($b['status'] !== 'booked') {
            throw Refusal::stateConflict('This booking is settled already.');
        }

        return $b;
    }

    /** @return array{0: string, 1: string} */
    private function checkService(string $code, string $name, string $category, int $minutes, int $priceMinor, int $capacity): array
    {
        $code = strtoupper(trim($code));
        $name = trim($name);

        if (preg_match('/^[A-Z0-9_-]{2,20}$/D', $code) !== 1) {
            throw Refusal::invalid('The code is 2 to 20 letters, digits, dashes or underscores.', ['code']);
        }

        if ($name === '' || mb_strlen($name) > 120) {
            throw Refusal::invalid('Give the name in at most 120 characters.', ['name']);
        }

        if (! in_array($category, self::CATEGORIES, true)) {
            throw Refusal::invalid('Choose spa, tour, sport or other.', ['category']);
        }

        if ($minutes < 15 || $minutes > 480) {
            throw Refusal::invalid('A service lasts from 15 minutes to 8 hours.', ['duration_minutes']);
        }

        if ($priceMinor < 0 || $priceMinor > 10_000_000_00) {
            throw Refusal::invalid('Give a price of zero or more.', ['price_minor']);
        }

        if ($capacity < 1 || $capacity > 50) {
            throw Refusal::invalid('Give how many guests can take part at once, from 1 to 50.', ['capacity']);
        }

        return [$code, $name];
    }

    /** @param array<string, mixed> $b @return array<string, mixed> */
    private function shape(array $b): array
    {
        return ['id' => $b['id'], 'number' => $b['number'], 'service_name' => $b['service_name'], 'starts_on' => substr((string) $b['starts_on'], 0, 10), 'starts_at' => $b['starts_at'], 'guests' => (int) $b['guests'], 'guest_name' => $b['guest_name'], 'price_minor' => (int) $b['price_minor'], 'status' => $b['status'], 'lock_version' => (int) $b['lock_version']];
    }

    private function manage(PropertyId $property, string $actorId): void
    {
        if (! $this->may($property, $actorId, self::MANAGE_PERMISSION)) {
            throw Refusal::forbidden('This person may not change the spa and activity services.');
        }
    }

    private function may(PropertyId $property, string $actorId, string $permission): bool
    {
        return $this->permissions->allowsInProperty($actorId, $permission, $property);
    }
}
