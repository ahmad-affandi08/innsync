<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Groups;

use App\Modules\FrontOffice\Application\Companies\CompanyRouting;
use App\Modules\FrontOffice\Application\Folios\FolioLedger;
use App\Modules\FrontOffice\Application\Folios\FolioRepository;
use App\Modules\FrontOffice\Application\Reservations\ReservationRepository;
use App\Modules\FrontOffice\Application\Reservations\ReservationRequest;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\FrontOffice\Domain\Folios\Folio;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Documents\DocumentNumbers;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Idempotency\IdempotencyRequest;
use App\Shared\Application\Idempotency\IdempotentExecutor;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Money\Money;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * A simple group booking (FR-FO-006): one booker with several rooms. Each room is an ordinary reservation with its own guest, room
 * type, rate plan and rules, created together or not at all. The group may have one master folio (on the first room, beside that
 * guest's own folio) that takes the room charges of every room, and optionally the other charges too; or each room keeps its own
 * bill. Anything on the master folio can still be moved to a room's folio, and back, with the move between folios. A group is not
 * changed afterwards except by adding rooms; each room is cancelled or checked out like any reservation. The master folio, like a
 * company's, may stay open with a balance after the guests leave until it is paid.
 */
final readonly class GroupBookingService implements CompanyRouting
{
    public const MANAGE_PERMISSION = 'front-office.group.manage';

    public const VIEW_PERMISSION = 'front-office.group.view';

    public const MAX_ROOMS = 30;

    public function __construct(
        private GroupRepository $groups,
        private ReservationService $reservations,
        private ReservationRepository $reservationStore,
        private FolioRepository $folios,
        private FolioLedger $ledger,
        private DocumentNumbers $numbers,
        private IdempotentExecutor $executor,
        private PermissionChecker $permissions,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    /** @return array{groups: list<array<string, mixed>>, may: array{manage: bool}} */
    public function overview(PropertyId $property, string $actorId, ?string $query = null): array
    {
        $this->authorize($property, $actorId, [self::VIEW_PERMISSION, self::MANAGE_PERMISSION]);

        return [
            'groups' => $this->groups->search($property, $query === null ? null : trim($query), 100),
            'may' => ['manage' => $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property)],
        ];
    }

    /**
     * @param  list<array{room_type_id: string, rate_plan_id: string, adults: int, children: int, guest_name?: string|null}>  $rooms
     * @param  array<string, mixed>  $data  name, booker_name, booker_phone, booker_email, source, arrival, departure, billing_mode, route_extras, notes, status
     * @return array<string, mixed>
     */
    public function create(PropertyId $property, string $actorId, array $data, array $rooms, IdempotencyKey $key): array
    {
        $this->authorize($property, $actorId, [self::MANAGE_PERMISSION]);
        $group = $this->clean($data);
        $lines = $this->lines($rooms);

        $result = $this->executor->execute(
            new IdempotencyRequest($property, $key, 'group.create', ['group' => $group, 'rooms' => $lines], strtolower($actorId)),
            function () use ($property, $actorId, $group, $lines, $key): array {
                $id = $this->ledger->newId();
                $actor = strtolower($actorId);
                $number = $this->numbers->next($property, 'GRP');
                $at = $this->clock->nowUtc();
                $this->groups->add($property, ['id' => $id, 'number' => $number, 'name' => $group['name'], 'booker_name' => $group['booker_name'], 'booker_phone' => $group['booker_phone'], 'booker_email' => $group['booker_email'],
                    'source' => $group['source'], 'arrival_date' => $group['arrival'], 'departure_date' => $group['departure'], 'billing_mode' => $group['billing_mode'], 'route_extras' => $group['route_extras'],
                    'notes' => $group['notes'], 'created_by' => $actor], $at);

                $members = $this->book($property, $actor, ['id' => $id, 'number' => $number, ...$group], $lines, 0, $key);

                if ($group['billing_mode'] === 'master') {
                    $lead = $this->reservationStore->find($property, $members[0]) ?? throw Refusal::notFound('Reservation not found.');
                    $currency = $lead->total->currency;
                    $own = new Folio($this->ledger->newId(), $this->numbers->next($property, 'FOL'), $lead->id, 1, 'Guest', $currency, false, Money::zero($currency), 0, 0);
                    $this->folios->create($property, $own, $actor, $at);
                    $master = new Folio($this->ledger->newId(), $this->numbers->next($property, 'FOL'), $lead->id, 2, mb_substr($number, 0, 60), $currency, false, Money::zero($currency), 0, 0);
                    $this->folios->create($property, $master, $actor, $at);
                    $this->groups->markMasterFolio($property, $id, $master->id, $at);
                }

                $this->audit->record(new AuditEntry($property->toString(), $actor, 'group.created', 'reservation_group', $id, null, ['number' => $number, 'rooms' => count($members), 'billing_mode' => $group['billing_mode'], 'arrival' => $group['arrival'], 'departure' => $group['departure']]));
                $this->outbox->publish(new OutboxEvent($property, 'frontoffice.group.created', $id, 1, ['group_id' => $id, 'number' => $number, 'reservation_ids' => $members, 'actor_id' => $actor]));

                return ['id' => $id];
            },
        );

        return $this->view($property, $actorId, (string) $result->payload['id']);
    }

    /**
     * Adds rooms to a group, for the dates of the group.
     *
     * @param  list<array{room_type_id: string, rate_plan_id: string, adults: int, children: int, guest_name?: string|null}>  $rooms
     * @return array<string, mixed>
     */
    public function addRooms(PropertyId $property, string $actorId, string $groupId, array $rooms, IdempotencyKey $key): array
    {
        $this->authorize($property, $actorId, [self::MANAGE_PERMISSION]);
        $group = $this->groups->find($property, strtolower($groupId)) ?? throw Refusal::notFound('Group not found.');
        $lines = $this->lines($rooms);

        $this->executor->execute(
            new IdempotencyRequest($property, $key, 'group.add_rooms', ['group' => $group['id'], 'rooms' => $lines], strtolower($actorId)),
            function () use ($property, $actorId, $group, $lines, $key): array {
                $first = $this->groups->lastLine($property, $group['id']);
                $members = $this->book($property, strtolower($actorId), [...$group, 'status' => 'confirmed'], $lines, $first, $key);
                $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'group.rooms_added', 'reservation_group', $group['id'], null, ['rooms' => count($members), 'reservation_ids' => $members]));

                return ['added' => count($members)];
            },
        );

        return $this->view($property, $actorId, $group['id']);
    }

    /** @return array<string, mixed> */
    public function view(PropertyId $property, string $actorId, string $groupId): array
    {
        $this->authorize($property, $actorId, [self::VIEW_PERMISSION, self::MANAGE_PERMISSION]);
        $group = $this->groups->find($property, strtolower($groupId)) ?? throw Refusal::notFound('Group not found.');
        $members = $this->groups->members($property, $group['id']);
        $masterId = $this->groups->masterFolioId($property, $group['id']);
        $master = $masterId === null ? null : $this->folios->find($property, $masterId);

        return [
            'group' => $group, 'members' => $members,
            'master' => $master === null ? null : ['folio_id' => $master->id, 'number' => $master->number, 'balance_minor' => $master->balance->amountMinor, 'currency' => $master->currency, 'is_closed' => $master->isClosed],
            'totals' => ['rooms' => count($members), 'stay_minor' => array_sum(array_column(array_filter($members, static fn (array $m): bool => ! in_array($m['status'], ['cancelled', 'no_show'], true)), 'total_minor'))],
            'may' => ['manage' => $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property)],
        ];
    }

    /**
     * What the reservation screen shows: the group this reservation belongs to, if any.
     *
     * @return array{id: string, number: string, name: string, billing_mode: string, master_folio_id: string|null}|null
     */
    public function forReservation(PropertyId $property, string $actorId, string $reservationId): ?array
    {
        $this->assertProperty($property);

        if (! $this->permissions->allowsInProperty($actorId, self::VIEW_PERMISSION, $property) && ! $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property)) {
            return null;
        }

        $group = $this->groups->groupOf($property, strtolower($reservationId));

        return $group === null ? null : ['id' => $group['id'], 'number' => $group['number'], 'name' => $group['name'], 'billing_mode' => $group['billing_mode'], 'master_folio_id' => $this->groups->masterFolioId($property, $group['id'])];
    }

    // ---- routing ----

    public function routeTo(PropertyId $property, string $reservationId, string $category): ?string
    {
        $group = $this->groups->groupOf($property, $reservationId);

        if ($group === null || $group['billing_mode'] !== 'master' || ($category !== 'room' && ! $group['route_extras'])) {
            return null;
        }

        $folioId = $this->groups->masterFolioId($property, $group['id']);

        if ($folioId === null) {
            return null;
        }

        $folio = $this->folios->find($property, $folioId);

        return $folio === null || $folio->isClosed ? null : $folio->id;
    }

    public function isCompanyFolio(PropertyId $property, string $folioId): bool
    {
        return $this->groups->isMasterFolio($property, $folioId);
    }

    // ---- internals ----

    /**
     * @param  array<string, mixed>  $group
     * @param  list<array{room_type_id: string, rate_plan_id: string, adults: int, children: int, guest_name: string|null}>  $lines
     * @return list<string> the reservations made
     */
    private function book(PropertyId $property, string $actor, array $group, array $lines, int $afterLine, IdempotencyKey $key): array
    {
        $made = [];
        $at = $this->clock->nowUtc();

        foreach ($lines as $i => $line) {
            $n = $afterLine + $i + 1;
            $name = $line['guest_name'] ?? null;
            $reservation = $this->reservations->create(
                $property, $actor,
                new ReservationRequest($group['source'], $name === null || trim($name) === '' ? $group['booker_name'] : trim($name), $group['booker_phone'], $group['booker_email'], $group['arrival'], $group['departure'], $line['adults'], $line['children'], $line['room_type_id'], $line['rate_plan_id'], mb_substr('Group '.$group['number'].($group['name'] === '' ? '' : ' · '.$group['name']), 0, 1000), (string) ($group['status'] ?? 'confirmed')),
                IdempotencyKey::fromString('grp-'.substr(hash('sha256', $key->toString().':'.$n), 0, 48)),
            );
            $this->groups->addMember($property, $group['id'], $reservation->id, $n, $actor, $at);
            $made[] = $reservation->id;
        }

        return $made;
    }

    /**
     * @param  list<array<string, mixed>>  $rooms
     * @return list<array{room_type_id: string, rate_plan_id: string, adults: int, children: int, guest_name: string|null}>
     */
    private function lines(array $rooms): array
    {
        if ($rooms === [] || count($rooms) > self::MAX_ROOMS) {
            throw Refusal::invalid(sprintf('A group has 1 to %d rooms.', self::MAX_ROOMS), ['rooms']);
        }

        $lines = [];

        foreach (array_values($rooms) as $room) {
            $adults = $room['adults'] ?? null;
            $children = $room['children'] ?? 0;
            $name = isset($room['guest_name']) ? trim((string) $room['guest_name']) : null;

            if (! is_string($room['room_type_id'] ?? null) || ! is_string($room['rate_plan_id'] ?? null) || ! is_int($adults) || $adults < 1 || ! is_int($children) || $children < 0) {
                throw Refusal::invalid('Each room needs a room type, a rate plan and at least one adult.', ['rooms']);
            }

            if ($name !== null && mb_strlen($name) > 150) {
                throw Refusal::invalid('A guest name is at most 150 characters.', ['rooms']);
            }

            $lines[] = ['room_type_id' => strtolower($room['room_type_id']), 'rate_plan_id' => strtolower($room['rate_plan_id']), 'adults' => $adults, 'children' => $children, 'guest_name' => $name === '' ? null : $name];
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string, booker_name: string, booker_phone: string|null, booker_email: string|null, source: string, arrival: string, departure: string, billing_mode: string, route_extras: bool, notes: string|null, status: string}
     */
    private function clean(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $booker = trim((string) ($data['booker_name'] ?? ''));
        $mode = (string) ($data['billing_mode'] ?? '');
        $status = (string) ($data['status'] ?? 'confirmed');
        $source = (string) ($data['source'] ?? 'direct');
        $arrival = (string) ($data['arrival'] ?? '');
        $departure = (string) ($data['departure'] ?? '');
        $email = isset($data['booker_email']) ? trim((string) $data['booker_email']) : '';
        $text = static fn (mixed $v, int $max): ?string => $v === null || trim((string) $v) === '' ? null : mb_substr(trim((string) $v), 0, $max);

        if ($name === '' || mb_strlen($name) > 120 || $booker === '' || mb_strlen($booker) > 150) {
            throw Refusal::invalid('Give the name of the group (at most 120 characters) and of the booker (at most 150).', ['name', 'booker_name']);
        }

        if (! in_array($mode, ['master', 'per_room'], true)) {
            throw Refusal::invalid('Choose one master folio or a bill per room.', ['billing_mode']);
        }

        if (! in_array($status, ['tentative', 'confirmed'], true)) {
            throw Refusal::invalid('A group is tentative or confirmed.', ['status']);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $arrival) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $departure) !== 1 || $departure <= $arrival) {
            throw Refusal::invalid('Give an arrival date and a later departure date.', ['arrival', 'departure']);
        }

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw Refusal::invalid('Give a valid e-mail address.', ['booker_email']);
        }

        return [
            'name' => $name, 'booker_name' => $booker, 'booker_phone' => $text($data['booker_phone'] ?? null, 30), 'booker_email' => $email === '' ? null : mb_substr($email, 0, 190), 'source' => $source,
            'arrival' => $arrival, 'departure' => $departure, 'billing_mode' => $mode, 'route_extras' => $mode === 'master' && (bool) ($data['route_extras'] ?? false), 'notes' => $text($data['notes'] ?? null, 500), 'status' => $status,
        ];
    }

    /** @param list<string> $permissions any of these */
    private function authorize(PropertyId $property, string $actorId, array $permissions): void
    {
        $this->assertProperty($property);

        foreach ($permissions as $permission) {
            if ($this->permissions->allowsInProperty($actorId, $permission, $property)) {
                return;
            }
        }

        throw Refusal::forbidden('This person may not use group bookings.');
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
