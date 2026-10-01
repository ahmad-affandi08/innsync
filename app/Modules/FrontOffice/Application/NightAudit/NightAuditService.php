<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\NightAudit;

use App\Modules\FrontOffice\Application\Folios\FolioLedger;
use App\Modules\FrontOffice\Application\Folios\FolioRepository;
use App\Modules\FrontOffice\Application\Inventory\RoomBlockRepository;
use App\Modules\FrontOffice\Application\Reservations\ReservationRepository;
use App\Modules\FrontOffice\Application\Stays\StayRepository;
use App\Modules\FrontOffice\Domain\Folios\Folio;
use App\Modules\FrontOffice\Domain\Folios\Posting;
use App\Modules\FrontOffice\Domain\Stays\Stay;
use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Modules\Property\Application\Ports\PropertyTimeZoneReader;
use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateAdvancer;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Idempotency\IdempotencyRequest;
use App\Shared\Application\Idempotency\IdempotentExecutor;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Money\Money;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\CalendarDate;

/**
 * Night audit (FR-FO-028, BR-001, BR-005). It closes the property's current business date: checks what is still pending,
 * posts one room charge per in-house stay for that night from the price snapshot of its reservation, records a report that
 * is computed from the ledger itself, and only then moves the business date one day forward. Nothing else moves it.
 *
 * It all happens in one transaction, so a failure leaves the day open and untouched, and each room charge carries the
 * source reference "reservation:date", so running it again can never charge a night twice. A day that has been closed takes
 * no more postings (a database trigger backs this up). The clock never starts it: it may run from the property's configured
 * earliest local time on the business date, and has no latest time (docs/OPERATIONS/INDONESIA-COMPLIANCE-BASELINE.md).
 *
 * Checks that find something pending block the run unless a person with the waive privilege waives that check with a reason;
 * every waiver is stored with the record.
 */
final readonly class NightAuditService
{
    public const RUN_PERMISSION = 'front-office.night-audit.run';

    public const VIEW_PERMISSION = 'front-office.night-audit.view';

    public const WAIVE_PERMISSION = 'front-office.night-audit.waive';

    public const GATES = ['pending_arrivals', 'overdue_departures', 'in_house_without_open_folio', 'same_day_stays'];

    private const LIST_LIMIT = 50;

    public function __construct(
        private NightAuditRepository $audits,
        private StayRepository $stays,
        private ReservationRepository $reservations,
        private FolioRepository $folios,
        private FolioLedger $ledger,
        private RoomCatalogReader $rooms,
        private RoomBlockRepository $blocks,
        private BusinessDateProvider $businessDate,
        private BusinessDateAdvancer $advancer,
        private PropertySettingsService $settings,
        private PropertyTimeZoneReader $zones,
        private PropertyCurrencyReader $currencies,
        private PermissionChecker $permissions,
        private IdempotentExecutor $executor,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    /**
     * What the person sees before running: the date to close, whether the clock allows it, the pending checks and how many
     * room charges would be posted.
     *
     * @return array<string, mixed>
     */
    public function preview(PropertyId $property, string $actorId): array
    {
        $this->authorize($property, $actorId, self::VIEW_PERMISSION);
        $today = $this->businessDate->current($property);
        $earliest = $this->earliestStart($property, $today);

        return [
            'business_date' => $today->toString(),
            'earliest_start' => $earliest['utc']->format('Y-m-d\TH:i:s\Z'),
            'earliest_local' => $earliest['local'],
            'can_start' => $this->clock->nowUtc() >= $earliest['utc'],
            'gates' => $this->gates($property, $today),
            'rooms_to_charge' => count(array_filter($this->stays->inHouse($property), fn (Stay $s): bool => $this->nightOf($property, $s, $today) !== null)),
            'may_run' => $this->permissions->allowsInProperty($actorId, self::RUN_PERMISSION, $property),
            'may_waive' => $this->permissions->allowsInProperty($actorId, self::WAIVE_PERMISSION, $property),
            'history' => $this->audits->history($property, 15),
        ];
    }

    /**
     * @param  list<array{gate: string, reason: string}>  $waivers
     * @return array<string, mixed> the stored record of the audit
     */
    public function run(PropertyId $property, string $actorId, array $waivers, IdempotencyKey $key): array
    {
        $this->authorize($property, $actorId, self::RUN_PERMISSION);
        $waivers = $this->cleanWaivers($waivers);

        if ($waivers !== [] && ! $this->permissions->allowsInProperty($actorId, self::WAIVE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not waive night audit checks.');
        }

        $result = $this->executor->execute(
            new IdempotencyRequest($property, $key, 'night_audit.run', ['waivers' => $waivers], strtolower($actorId)),
            fn (): array => ['business_date' => $this->close($property, strtolower($actorId), $waivers)->toString()],
        );

        return $this->audits->find($property, BusinessDate::fromString((string) $result->payload['business_date'])) ?? throw Refusal::notFound('The night audit record was not found.');
    }

    /** @return array<string, mixed> */
    public function find(PropertyId $property, string $actorId, string $date): array
    {
        $this->authorize($property, $actorId, self::VIEW_PERMISSION);

        try {
            $day = BusinessDate::fromString($date);
        } catch (\InvalidArgumentException) {
            throw Refusal::invalid('The date is not valid.', ['date']);
        }

        return $this->audits->find($property, $day) ?? throw Refusal::notFound('No night audit was run for that date.');
    }

    // ---- internals ----

    /** @param list<array{gate: string, reason: string}> $waivers */
    private function close(PropertyId $property, string $actor, array $waivers): BusinessDate
    {
        $today = $this->businessDate->current($property);
        $earliest = $this->earliestStart($property, $today);
        $now = $this->clock->nowUtc();

        if ($now < $earliest['utc']) {
            throw NightAuditRefused::tooEarly($today->toString(), $earliest['local']);
        }

        if ($this->audits->find($property, $today) !== null) {
            throw NightAuditRefused::alreadyClosed($today->toString());
        }

        $gates = $this->gates($property, $today);
        $waived = [];
        $blocked = [];

        foreach ($gates as $gate) {
            if ($gate['count'] === 0) {
                continue;
            }

            $waiver = array_values(array_filter($waivers, static fn (array $w): bool => $w['gate'] === $gate['code']))[0] ?? null;

            if ($waiver === null) {
                $blocked[] = $gate['code'];
            } else {
                $waived[] = ['gate' => $gate['code'], 'reason' => $waiver['reason'], 'count' => $gate['count']];
            }
        }

        if ($blocked !== []) {
            throw NightAuditRefused::blocked($blocked);
        }

        $charged = 0;
        $skipped = 0;
        $inHouse = $this->stays->inHouse($property);

        foreach ($inHouse as $stay) {
            if ($this->chargeNight($property, $actor, $stay, $today) === 'posted') {
                $charged++;
            } else {
                $skipped++;
            }
        }

        $totals = $this->audits->dayTotals($property, $today);
        // Occupancy is rooms in the house over rooms that could be sold: out of order and out of service rooms are not counted
        // (the same definition the dashboard states, FR-DSH-021).
        $activeRooms = $this->rooms->activeRooms($property);
        $roomsTotal = count($activeRooms);
        $roomsBlocked = count(array_filter($activeRooms, fn ($room): bool => $this->blocks->overlapping($property, $room->id, $today->toString(), $today->toString()) !== []));
        $roomsSellable = max(0, $roomsTotal - $roomsBlocked);
        $next = $this->advancer->advance($property, $today, $actor);
        $report = [
            'business_date' => $today->toString(),
            'next_business_date' => $next->toString(),
            'currency' => $this->currencies->currencyOf($property),
            'rooms_total' => $roomsTotal,
            'rooms_blocked' => $roomsBlocked,
            'rooms_sellable' => $roomsSellable,
            'in_house' => count($inHouse),
            'occupancy_bp' => $roomsSellable === 0 ? 0 : intdiv(count($inHouse) * 10_000, $roomsSellable),
            'room_nights_charged' => $charged,
            'room_nights_skipped' => $skipped,
            'arrivals' => $totals['arrivals'],
            'departures' => $totals['departures'],
            'revenue' => $totals['revenue'],
            'collected' => $totals['collected'],
            'checks' => array_column(array_map(static fn (array $g): array => ['code' => $g['code'], 'count' => $g['count']], $gates), 'count', 'code'),
        ];

        $id = $this->ids->next();
        $this->audits->record($property, $id, $today, $next, $report, $waived, $actor, $now);
        $this->audit->record(new AuditEntry(
            $property->toString(), $actor, 'night_audit.completed', 'night_audit', $id,
            ['business_date' => $today->toString()],
            ['business_date' => $next->toString(), 'room_nights_charged' => $charged, 'net_revenue_minor' => $totals['revenue']['net']['total'], 'waived' => array_column($waived, 'gate')],
            $waived === [] ? null : implode('; ', array_map(static fn (array $w): string => $w['gate'].': '.$w['reason'], $waived)),
        ));
        $this->outbox->publish(new OutboxEvent($property, 'frontoffice.night_audit.completed', $id, 1, [
            'night_audit_id' => $id, 'business_date' => $today->toString(), 'next_business_date' => $next->toString(),
            'room_nights_charged' => $charged, 'revenue' => $totals['revenue'], 'actor_id' => $actor,
        ]));

        return $today;
    }

    /** @return 'posted'|'skipped' */
    private function chargeNight(PropertyId $property, string $actor, Stay $stay, BusinessDate $today): string
    {
        $night = $this->nightOf($property, $stay, $today);

        if ($night === null) {
            return 'skipped';
        }

        $folio = $this->openFolio($property, $stay->reservationId);

        if ($folio === null) {
            return 'skipped';
        }

        $room = $this->rooms->room($property, $stay->roomId);
        $reference = $stay->reservationId.':'.$today->toString();

        $this->ledger->post($property, $folio->id, fn (BusinessDate $date, \DateTimeImmutable $at): Posting => Posting::charge(
            $this->ledger->newId(), 'ROOM', 'Room '.($room?->number).', night of '.$today->toString(),
            Money::ofMinor((int) $night['base_minor'], $folio->currency), Money::ofMinor((int) $night['service_charge_minor'], $folio->currency), Money::ofMinor((int) $night['tax_minor'], $folio->currency),
            $date, $at, $actor, 'night_audit', $reference, $night['scheme'] ?? null,
        ));

        return 'posted';
    }

    /**
     * The snapshot night of the stay's reservation for `$date`, or null when that night is not part of the booking (a guest who
     * stays past the booked departure is flagged by a check, not charged a price nobody agreed).
     *
     * @return array<string, mixed>|null
     */
    private function nightOf(PropertyId $property, Stay $stay, BusinessDate $date): ?array
    {
        $reservation = $this->reservations->find($property, $stay->reservationId);

        foreach ($reservation->priceSnapshot['nights'] ?? [] as $night) {
            if (($night['date'] ?? null) === $date->toString()) {
                return $night;
            }
        }

        return null;
    }

    private function openFolio(PropertyId $property, string $reservationId): ?Folio
    {
        foreach ($this->folios->byReservation($property, $reservationId) as $folio) {
            if (! $folio->isClosed) {
                return $folio;
            }
        }

        return null;
    }

    /**
     * @return list<array{code: string, count: int, items: list<string>}>
     */
    private function gates(PropertyId $property, BusinessDate $today): array
    {
        $arrivals = $this->audits->pendingArrivals($property, $today, self::LIST_LIMIT);
        $overdue = [];
        $withoutFolio = [];

        foreach ($this->stays->inHouse($property) as $stay) {
            $label = 'Room '.($this->rooms->room($property, $stay->roomId)?->number ?? '?');

            if (! $stay->expectedDeparture->isAfter($today)) {
                $overdue[] = $label.' (due '.$stay->expectedDeparture->toString().')';
            }

            if ($this->openFolio($property, $stay->reservationId) === null) {
                $withoutFolio[] = $label;
            }
        }

        $sameDay = array_map(fn (array $s): string => $s['number'].' · Room '.($this->rooms->room($property, $s['room_id'])?->number ?? '?'), $this->audits->sameDayStays($property, $today, self::LIST_LIMIT));

        return [
            self::gate('pending_arrivals', array_map(static fn (array $a): string => $a['number'].' (arrival '.$a['arrival'].')', $arrivals)),
            self::gate('overdue_departures', $overdue),
            self::gate('in_house_without_open_folio', $withoutFolio),
            self::gate('same_day_stays', $sameDay),
        ];
    }

    /** @param list<string> $items @return array{code: string, count: int, items: list<string>} */
    private static function gate(string $code, array $items): array
    {
        return ['code' => $code, 'count' => count($items), 'items' => array_slice($items, 0, self::LIST_LIMIT)];
    }

    /** @return array{utc: \DateTimeImmutable, local: string} */
    private function earliestStart(PropertyId $property, BusinessDate $today): array
    {
        $zone = $this->zones->forProperty($property) ?? throw Refusal::notFound('Property not found.');
        $time = $this->settings->get($property)->nightAuditEarliest->value;

        return ['utc' => $zone->utcAt(CalendarDate::fromString($today->toString()), $time), 'local' => $today->toString().' '.$time.' '.$zone->identifier()];
    }

    /**
     * @param  list<array{gate: string, reason: string}>  $waivers
     * @return list<array{gate: string, reason: string}>
     */
    private function cleanWaivers(array $waivers): array
    {
        $clean = [];

        foreach ($waivers as $waiver) {
            $reason = trim((string) ($waiver['reason'] ?? ''));

            if (! in_array($waiver['gate'] ?? null, self::GATES, true) || $reason === '' || mb_strlen($reason) > 300) {
                throw Refusal::invalid('A waiver names a known check and gives a reason of at most 300 characters.', ['waivers']);
            }

            $clean[$waiver['gate']] = ['gate' => $waiver['gate'], 'reason' => $reason];
        }

        return array_values($clean);
    }

    private function authorize(PropertyId $property, string $actorId, string $permission): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }

        $allowed = $this->permissions->allowsInProperty($actorId, $permission, $property)
            || ($permission === self::VIEW_PERMISSION && $this->permissions->allowsInProperty($actorId, self::RUN_PERMISSION, $property));

        if (! $allowed) {
            throw Refusal::forbidden('This person may not use night audit.');
        }
    }
}
