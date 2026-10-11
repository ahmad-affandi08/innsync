<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Waitlist;

use App\Modules\FrontOffice\Application\FrontDeskAccess;
use App\Modules\FrontOffice\Application\Inventory\AvailabilityService;
use App\Modules\FrontOffice\Application\Reservations\ReservationRequest;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Modules\Property\Application\Rates\RatePlanReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\DateMath;
use App\Shared\Domain\Time\StayDates;
use InvalidArgumentException;

/**
 * The waiting list of the front desk: a guest who asked for a room type on dates that were sold out. The desk sees which entries can be sold now (every night of the stay has a room again), calls the guest
 * (marks the entry offered) and books it with the rate plan the guest takes, through the same rules as any reservation, or cancels it with a reason. Nothing is booked or offered on its own, and an entry whose
 * arrival day has passed lapses: it is shown as such and can only be cancelled.
 */
final readonly class WaitlistService
{
    public function __construct(
        private WaitlistStore $store,
        private FrontDeskAccess $access,
        private AvailabilityService $availability,
        private ReservationService $reservations,
        private RoomCatalogReader $rooms,
        private RatePlanReader $plans,
        private BusinessDateProvider $businessDate,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->access->read($property, $actorId);
        $today = $this->businessDate->current($property);
        $types = [];

        foreach ($this->rooms->activeTypes($property) as $t) {
            $types[$t->id] = ['id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'max_adults' => $t->maxAdults, 'max_children' => $t->maxChildren];
        }

        $entries = array_map(fn (array $e): array => $this->shape($property, $e, $today, $types), $this->store->entries($property, DateMath::addDays($today->toString(), -30)));
        usort($entries, static fn (array $a, array $b): int => [$a['status'] === 'waiting' || $a['status'] === 'offered' ? 0 : 1, $a['available'] ? 0 : 1, $a['arrival']] <=> [$b['status'] === 'waiting' || $b['status'] === 'offered' ? 0 : 1, $b['available'] ? 0 : 1, $b['arrival']]);

        return [
            'today' => $today->toString(), 'types' => array_values($types), 'plans' => array_map(static fn ($p): array => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name], $this->plans->activePlans($property)),
            'entries' => $entries, 'available_now' => count(array_filter($entries, static fn (array $e): bool => $e['available'])), 'may_write' => $this->access->mayWrite($property, $actorId),
        ];
    }

    /** @return array<string, mixed> */
    public function add(PropertyId $property, string $actorId, string $guestName, ?string $phone, ?string $email, string $roomTypeId, string $arrival, string $departure, int $adults, int $children, ?string $note): array
    {
        $this->access->write($property, $actorId);
        $guestName = trim($guestName);
        $phone = $phone === null || trim($phone) === '' ? null : trim($phone);
        $email = $email === null || trim($email) === '' ? null : trim($email);
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if ($guestName === '' || mb_strlen($guestName) > 150) {
            throw Refusal::invalid('Give the name of the guest.', ['guest_name']);
        }

        if ($phone === null && $email === null) {
            throw Refusal::invalid('Give a phone number or an e-mail address to call the guest on.', ['phone', 'email']);
        }

        if ($phone !== null && preg_match('/^[0-9 +().-]{5,30}$/', $phone) !== 1) {
            throw Refusal::invalid('Give a phone number of digits, spaces and + - ( ).', ['phone']);
        }

        if ($email !== null && (mb_strlen($email) > 120 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            throw Refusal::invalid('Give a valid e-mail address.', ['email']);
        }

        if ($note !== null && mb_strlen($note) > 300) {
            throw Refusal::invalid('The note is at most 300 characters.', ['note']);
        }

        $type = $this->rooms->type($property, strtolower($roomTypeId));

        if ($type === null || ! $type->isActive) {
            throw Refusal::invalid('Choose an active room type.', ['room_type_id']);
        }

        if ($adults < 1 || $adults > $type->maxAdults || $children < 0 || $children > $type->maxChildren) {
            throw Refusal::invalid('The guests do not fit this room type.', ['adults', 'children']);
        }

        $today = $this->businessDate->current($property);

        try {
            $stay = new StayDates(BusinessDate::fromString($arrival), BusinessDate::fromString($departure));
        } catch (InvalidArgumentException) {
            throw Refusal::invalid('Give the arrival and a later departure.', ['arrival', 'departure']);
        }

        if ($stay->arrival->isBefore($today)) {
            throw Refusal::invalid('The arrival is today or later.', ['arrival']);
        }

        $id = $this->ids->next();
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $guestName, $phone, $email, $type, $stay, $adults, $children, $note): void {
            $this->store->add($property, ['id' => $id, 'guest_name' => $guestName, 'phone' => $phone, 'email' => $email, 'room_type_id' => $type->id, 'arrival' => $stay->arrival->toString(), 'departure' => $stay->departure->toString(), 'adults' => $adults, 'children' => $children, 'note' => $note, 'status' => 'waiting', 'created_by' => $actor], $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'waitlist.added', 'waitlist_entry', $id, null, ['room_type' => $type->code, 'arrival' => $stay->arrival->toString(), 'departure' => $stay->departure->toString(), 'adults' => $adults, 'children' => $children]));
        });

        return $this->entry($property, $id);
    }

    /** The desk called the guest. @return array<string, mixed> */
    public function offer(PropertyId $property, string $actorId, string $id, int $lock): array
    {
        $this->access->write($property, $actorId);
        $e = $this->open($property, $id);

        $this->transactions->run(function () use ($property, $actorId, $e, $lock): void {
            if (! $this->store->update($property, $e['id'], $lock, ['status' => 'offered', 'offered_at' => $this->clock->nowUtc()], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This entry changed after you opened it. Reload.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'waitlist.offered', 'waitlist_entry', $e['id'], ['status' => $e['status']], ['status' => 'offered']));
        });

        return $this->entry($property, $e['id']);
    }

    public function cancel(PropertyId $property, string $actorId, string $id, string $reason, int $lock): void
    {
        $this->access->write($property, $actorId);
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Give the reason in at most 200 characters.', ['reason']);
        }

        $e = $this->open($property, $id);

        $this->transactions->run(function () use ($property, $actorId, $e, $reason, $lock): void {
            if (! $this->store->update($property, $e['id'], $lock, ['status' => 'cancelled', 'cancel_reason' => $reason], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This entry changed after you opened it. Reload.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'waitlist.cancelled', 'waitlist_entry', $e['id'], ['status' => $e['status']], ['status' => 'cancelled'], $reason));
        });
    }

    /** Books the entry through the ordinary reservation rules; refused when the nights are not free. @return array{entry: array<string, mixed>, reservation_id: string} */
    public function book(PropertyId $property, string $actorId, string $id, string $ratePlanId, string $status, int $lock, IdempotencyKey $key): array
    {
        $this->access->write($property, $actorId);
        $e = $this->open($property, $id);

        if (! in_array($status, ['tentative', 'confirmed'], true)) {
            throw Refusal::invalid('A new reservation is tentative or confirmed.', ['status']);
        }

        $reservationId = $this->transactions->run(function () use ($property, $actorId, $e, $ratePlanId, $status, $lock, $key): string {
            $reservation = $this->reservations->create($property, $actorId, new ReservationRequest(
                'phone', $e['guest_name'], $e['phone'], $e['email'], substr((string) $e['arrival'], 0, 10), substr((string) $e['departure'], 0, 10), (int) $e['adults'], (int) $e['children'], $e['room_type_id'], strtolower($ratePlanId),
                $e['note'] === null ? 'From the waiting list' : 'From the waiting list: '.$e['note'], $status,
            ), $key);

            if (! $this->store->update($property, $e['id'], $lock, ['status' => 'booked', 'reservation_id' => $reservation->id], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This entry changed after you opened it. Reload.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'waitlist.booked', 'waitlist_entry', $e['id'], ['status' => $e['status']], ['status' => 'booked', 'reservation' => $reservation->number]));

            return $reservation->id;
        });

        return ['entry' => $this->entry($property, $e['id']), 'reservation_id' => $reservationId];
    }

    /** @return array<string, mixed> */
    private function open(PropertyId $property, string $id): array
    {
        $e = $this->store->find($property, strtolower($id)) ?? throw Refusal::notFound('Entry not found.');

        if (! in_array($e['status'], ['waiting', 'offered'], true)) {
            throw Refusal::stateConflict('This entry is settled already.');
        }

        return $e;
    }

    /** @return array<string, mixed> */
    private function entry(PropertyId $property, string $id): array
    {
        $today = $this->businessDate->current($property);
        $types = [];

        foreach ($this->rooms->activeTypes($property) as $t) {
            $types[$t->id] = ['id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'max_adults' => $t->maxAdults, 'max_children' => $t->maxChildren];
        }

        return $this->shape($property, $this->store->find($property, $id) ?? throw Refusal::notFound('Entry not found.'), $today, $types);
    }

    /**
     * @param  array<string, mixed>  $e
     * @param  array<string, array<string, mixed>>  $types
     * @return array<string, mixed>
     */
    private function shape(PropertyId $property, array $e, BusinessDate $today, array $types): array
    {
        $arrival = substr((string) $e['arrival'], 0, 10);
        $departure = substr((string) $e['departure'], 0, 10);
        $live = in_array($e['status'], ['waiting', 'offered'], true);
        $lapsed = $live && $arrival < $today->toString();
        $available = false;

        if ($live && ! $lapsed && isset($types[$e['room_type_id']])) {
            try {
                $available = true;

                foreach ($this->availability->forStay($property, $e['room_type_id'], new StayDates(BusinessDate::fromString($arrival), BusinessDate::fromString($departure)), false) as $night) {
                    $available = $available && $night->canSellOne() && ! $night->needsOverbooking();
                }
            } catch (InvalidArgumentException) {
                $available = false;
            }
        }

        return [
            'id' => $e['id'], 'guest_name' => $e['guest_name'], 'phone' => $e['phone'], 'email' => $e['email'], 'room_type' => $types[$e['room_type_id']]['code'] ?? '?', 'room_type_id' => $e['room_type_id'], 'arrival' => $arrival, 'departure' => $departure,
            'adults' => (int) $e['adults'], 'children' => (int) $e['children'], 'note' => $e['note'], 'status' => $lapsed ? 'lapsed' : $e['status'], 'available' => $available, 'reservation_id' => $e['reservation_id'], 'cancel_reason' => $e['cancel_reason'],
            'created_at' => (string) $e['created_at'], 'lock_version' => (int) $e['lock_version'],
        ];
    }
}
