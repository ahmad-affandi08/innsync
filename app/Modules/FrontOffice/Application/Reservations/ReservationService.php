<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Reservations;

use App\Modules\FrontOffice\Application\BookingRefused;
use App\Modules\FrontOffice\Application\Inventory\AvailabilityService;
use App\Modules\FrontOffice\Application\Inventory\InventoryRepository;
use App\Modules\FrontOffice\Domain\Reservations\BookingSource;
use App\Modules\FrontOffice\Domain\Reservations\PolicySnapshot;
use App\Modules\FrontOffice\Domain\Reservations\Reservation;
use App\Modules\FrontOffice\Domain\Reservations\ReservationRuleViolation;
use App\Modules\FrontOffice\Domain\Reservations\ReservationStatus;
use App\Modules\FrontOffice\Domain\Stays\StayRuleViolation;
use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Modules\Property\Application\Policies\BookingPolicyReader;
use App\Modules\Property\Application\Rates\RatePlanReader;
use App\Modules\Property\Application\Rates\RateQuoter;
use App\Modules\Property\Application\Rates\StayQuote;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Modules\Property\Application\Settings\PropertySettingsService;
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
use App\Shared\Application\Privacy\PiiAccessAudit;
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
 * Reservations (FR-FO-003, FR-FO-004, FR-FO-007). Creating one is idempotent, priced from the rate plan, checked against
 * availability under a per-room-type lock so two people cannot sell the last room twice, and keeps the price as a snapshot.
 * Selling beyond the physical rooms needs the property's overbooking allowance, an explicit acknowledgement, a reason and
 * a privilege (BR-007). Reservations are never deleted: they are cancelled or marked no-show with a reason (BR-003).
 */
final readonly class ReservationService
{
    public const MANAGE_PERMISSION = 'front-office.reservation.manage';

    public const VIEW_PERMISSION = 'front-office.reservation.view';

    public const CONTACT_PERMISSION = 'front-office.guest-contact.view';

    public const OVERBOOKING_PERMISSION = 'front-office.overbooking.override';

    public const WAIVE_PENALTY_PERMISSION = 'front-office.penalty.waive';

    public const GUARANTEE_OVERRIDE_PERMISSION = 'front-office.guarantee.override';

    public function __construct(
        private ReservationRepository $reservations,
        private InventoryRepository $inventory,
        private AvailabilityService $availability,
        private RoomCatalogReader $rooms,
        private RateQuoter $quoter,
        private RatePlanReader $plans,
        private BusinessDateProvider $businessDate,
        private PropertySettingsService $settings,
        private PermissionChecker $permissions,
        private IdempotentExecutor $executor,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private DocumentNumbers $numbers,
        private IdentifierGenerator $ids,
        private PiiAccessAudit $piiAccess,
        private Clock $clock,
        private PropertyContext $property,
        private BookingPolicyReader $policies,
        private PenaltyPoster $penalties,
        private DepositLedger $deposits,
    ) {}

    public function create(PropertyId $property, string $actorId, ReservationRequest $request, IdempotencyKey $key): Reservation
    {
        $this->authorize($property, $actorId, self::MANAGE_PERMISSION);

        if ($request->acknowledgeOversell && ! $this->permissions->allowsInProperty($actorId, self::OVERBOOKING_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not sell beyond the available rooms.');
        }

        $result = $this->executor->execute(
            new IdempotencyRequest($property, $key, 'reservation.create', $request->fingerprint(), strtolower($actorId)),
            fn (): array => ['id' => $this->open($property, strtolower($actorId), $request)->id],
        );

        return $this->reservations->find($property, (string) $result->payload['id']) ?? throw Refusal::notFound('Reservation not found.');
    }

    public function confirm(PropertyId $property, string $actorId, string $id, int $expectedLockVersion): Reservation
    {
        return $this->change($property, $actorId, $id, $expectedLockVersion, 'reservation.confirmed', null, static fn (Reservation $r): Reservation => $r->confirm());
    }

    /** A cancellation can cost a fee under the policy the reservation was given; a person with the privilege may waive it, with the reason recorded. */
    public function cancel(PropertyId $property, string $actorId, string $id, string $reason, int $expectedLockVersion, bool $waivePenalty = false): Reservation
    {
        $today = $this->businessDate->current($property);

        return $this->change(
            $property, $actorId, $id, $expectedLockVersion, 'reservation.cancelled', $reason, static fn (Reservation $r): Reservation => $r->cancel($reason),
            fn (Reservation $before): array => $this->penaltyFor($before, 'cancel', $today), $waivePenalty,
        );
    }

    public function noShow(PropertyId $property, string $actorId, string $id, string $reason, int $expectedLockVersion, bool $waivePenalty = false): Reservation
    {
        $today = $this->businessDate->current($property);

        return $this->change(
            $property, $actorId, $id, $expectedLockVersion, 'reservation.no_show', $reason, static fn (Reservation $r): Reservation => $r->noShow($reason, $today),
            fn (Reservation $before): array => $this->penaltyFor($before, 'no_show', $today), $waivePenalty,
        );
    }

    /** Confirmed becomes guaranteed once the deposit the policy asked for is held. Without a policy deposit it needs the override privilege and a reason. */
    public function guarantee(PropertyId $property, string $actorId, string $id, int $expectedLockVersion, ?string $reason = null): Reservation
    {
        $this->authorize($property, $actorId, self::MANAGE_PERMISSION);
        $before = $this->reservations->find($property, strtolower($id)) ?? throw Refusal::notFound('Reservation not found.');
        $held = $this->deposits->heldMinor($property, $before->id);

        if ($before->depositRequiredMinor > 0 && $held < $before->depositRequiredMinor) {
            throw Refusal::stateConflict(sprintf('The deposit is not complete: %d of %d held.', $held, $before->depositRequiredMinor));
        }

        if ($before->depositRequiredMinor === 0) {
            if (! $this->permissions->allowsInProperty($actorId, self::GUARANTEE_OVERRIDE_PERMISSION, $property)) {
                throw Refusal::forbidden('This booking has no deposit to guarantee it; guaranteeing it needs the override privilege.');
            }

            if ($reason === null || trim($reason) === '' || mb_strlen($reason) > 500) {
                throw Refusal::invalid('Say why it is guaranteed without a deposit.', ['reason']);
            }
        }

        return $this->change($property, $actorId, $id, $expectedLockVersion, 'reservation.guaranteed', $reason, static fn (Reservation $r): Reservation => $r->guarantee());
    }

    /**
     * What cancelling or marking no-show would cost under the policy the reservation was given, for the screen to show before
     * the person decides.
     *
     * @return array{amount_minor: int, free: bool, free_until: ?string, currency: string, may_waive: bool}
     */
    public function penaltyPreview(PropertyId $property, string $actorId, string $id, string $kind): array
    {
        $this->authorize($property, $actorId, self::MANAGE_PERMISSION);
        $reservation = $this->reservations->find($property, strtolower($id)) ?? throw Refusal::notFound('Reservation not found.');

        if (! in_array($kind, ['cancel', 'no_show'], true)) {
            throw Refusal::invalid('Choose cancel or no_show.', ['kind']);
        }

        $penalty = $this->penaltyFor($reservation, $kind, $this->businessDate->current($property));
        $snapshot = PolicySnapshot::fromArray($reservation->policy);

        return [
            'amount_minor' => $penalty['amount_minor'], 'free' => $penalty['free'], 'currency' => $reservation->total->currency,
            'free_until' => $snapshot?->freeCancellationUntil($reservation->stay->arrival)->toString(),
            'may_waive' => $this->permissions->allowsInProperty($actorId, self::WAIVE_PENALTY_PERMISSION, $property),
        ];
    }

    /**
     * The policy a reservation was given and where it stands: deposit required, due and held, the last free cancellation date and
     * the penalties. Nothing here is read from the current policy.
     *
     * @return array<string, mixed>|null null when no policy applied
     */
    public function policyView(PropertyId $property, string $actorId, string $id): ?array
    {
        $this->authorize($property, $actorId, self::VIEW_PERMISSION);
        $reservation = $this->reservations->find($property, strtolower($id)) ?? throw Refusal::notFound('Reservation not found.');
        $snapshot = PolicySnapshot::fromArray($reservation->policy);

        if ($snapshot === null) {
            return null;
        }

        $held = $this->deposits->heldMinor($property, $reservation->id);
        $data = $snapshot->toArray();

        return [
            'guarantee_required' => $snapshot->guaranteeRequired(),
            'deposit_required_minor' => $reservation->depositRequiredMinor,
            'deposit_due_date' => $reservation->depositDueDate?->toString(),
            'deposit_held_minor' => $held,
            'deposit_complete' => $reservation->depositRequiredMinor === 0 || $held >= $reservation->depositRequiredMinor,
            'free_cancellation_until' => $snapshot->freeCancellationUntil($reservation->stay->arrival)->toString(),
            'cancellation_penalty' => $data['cancellation']['penalty'],
            'no_show_penalty' => $data['no_show']['penalty'],
            'currency' => $reservation->total->currency,
            'may_guarantee' => $reservation->status->value === 'confirmed' && ($reservation->depositRequiredMinor === 0 || $held >= $reservation->depositRequiredMinor),
        ];
    }

    /**
     * What the booking screen needs to start: the room types and rate plans that can be sold.
     *
     * @return array{types: list<array<string, mixed>>, plans: list<array<string, mixed>>, business_date: string}
     */
    public function lookups(PropertyId $property, string $actorId): array
    {
        $this->authorize($property, $actorId, self::VIEW_PERMISSION);

        return [
            'types' => array_map(static fn ($t): array => ['id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'max_adults' => $t->maxAdults, 'max_children' => $t->maxChildren], $this->rooms->activeTypes($property)),
            'plans' => array_map(static fn ($p): array => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'kind' => $p->kind, 'inclusions' => $p->inclusions, 'prices_include_charges' => $p->pricesIncludeCharges], $this->plans->activePlans($property)),
            'business_date' => $this->businessDate->current($property)->toString(),
        ];
    }

    /** @return array<string, mixed> the price and whether the stay can be sold, as plain data */
    public function quote(PropertyId $property, string $actorId, string $ratePlanId, string $roomTypeId, string $arrival, string $departure): array
    {
        $this->authorize($property, $actorId, self::VIEW_PERMISSION);

        $quote = $this->quoter->describe($property, $ratePlanId, $roomTypeId, $arrival, $departure);
        $stay = new StayDates(BusinessDate::fromString($arrival), BusinessDate::fromString($departure));
        $soldOut = [];
        $overbooking = [];

        // Advisory only: the real decision is taken under the room type's lock when the reservation is created.
        foreach ($this->availability->forStay($property, strtolower($roomTypeId), $stay, false) as $night => $availability) {
            if (! $availability->canSellOne()) {
                $soldOut[] = $night;
            } elseif ($availability->needsOverbooking()) {
                $overbooking[] = $night;
            }
        }

        return [...$quote, 'availability' => ['sold_out_nights' => $soldOut, 'overbooking_nights' => $overbooking]];
    }

    /** One reservation. Guest contact details are shown only to people allowed to see them, and that access is audited (BR-009). */
    public function find(PropertyId $property, string $actorId, string $id): Reservation
    {
        $this->authorize($property, $actorId, self::VIEW_PERMISSION);
        $reservation = $this->reservations->find($property, strtolower($id)) ?? throw Refusal::notFound('Reservation not found.');

        if (! $this->mayViewContact($property, $actorId)) {
            return $this->withoutContact($reservation);
        }

        if ($reservation->guestPhone !== null || $reservation->guestEmail !== null) {
            $this->piiAccess->record($property, $actorId, 'reservation', $reservation->id, 'Viewed the reservation', array_values(array_filter([
                $reservation->guestPhone !== null ? 'guest_phone' : null,
                $reservation->guestEmail !== null ? 'guest_email' : null,
            ])));
        }

        return $reservation;
    }

    /**
     * @param  array{status?: string, arrival_from?: string, arrival_to?: string, query?: string}  $filters
     * @return list<Reservation> contact details are never part of a list
     */
    public function search(PropertyId $property, string $actorId, array $filters, int $limit = 50, int $offset = 0): array
    {
        $this->authorize($property, $actorId, self::VIEW_PERMISSION);

        return array_map(fn (Reservation $r): Reservation => $this->withoutContact($r), $this->reservations->search($property, $filters, max(1, min($limit, 200)), max(0, $offset)));
    }

    private function open(PropertyId $property, string $actorId, ReservationRequest $request): Reservation
    {
        try {
            $source = BookingSource::tryFrom($request->source) ?? throw new InvalidArgumentException('Unknown booking source.');
            $status = ReservationStatus::tryFrom($request->status);

            if ($status !== ReservationStatus::Tentative && $status !== ReservationStatus::Confirmed) {
                throw new InvalidArgumentException('A new reservation is tentative or confirmed.');
            }

            $stay = new StayDates(BusinessDate::fromString($request->arrival), BusinessDate::fromString($request->departure));
        } catch (InvalidArgumentException $e) {
            throw Refusal::invalid($e->getMessage(), ['source', 'status', 'arrival', 'departure']);
        }

        $today = $this->businessDate->current($property);
        $horizon = $this->settings->get($property)->availabilityHorizonDays;

        if ($stay->arrival->isBefore($today)) {
            throw BookingRefused::arrivalInThePast();
        }

        if ($today->daysUntil($stay->arrival) > $horizon) {
            throw BookingRefused::beyondHorizon($horizon);
        }

        $type = $this->rooms->type($property, $request->roomTypeId);

        if ($type === null || ! $type->isActive) {
            throw Refusal::invalid('Choose an active room type.', ['room_type_id']);
        }

        if ($request->adults > $type->maxAdults || $request->children > $type->maxChildren) {
            throw BookingRefused::occupancyExceeded();
        }

        $quote = $this->quoter->quote($property, $request->ratePlanId, $type->id, $stay);

        if (! $quote->isBookable()) {
            throw BookingRefused::notBookable(array_map(static fn (array $v): string => $v['code'].($v['date'] === null ? '' : '@'.$v['date']), $quote->violations));
        }

        // From here the room type is locked: nobody else can sell or release its inventory until this transaction ends.
        $this->inventory->lockRoomType($property, $type->id);
        $oversoldNights = [];

        foreach ($this->availability->forStay($property, $type->id, $stay) as $night => $availability) {
            if (! $availability->canSellOne()) {
                throw BookingRefused::noAvailability($night);
            }

            if ($availability->needsOverbooking()) {
                $oversoldNights[] = $night;
            }
        }

        if ($oversoldNights !== []) {
            if (! $request->acknowledgeOversell) {
                throw BookingRefused::oversellWarning($oversoldNights);
            }

            if (trim((string) $request->oversellReason) === '' || mb_strlen((string) $request->oversellReason) > 500) {
                throw Refusal::invalid('Selling beyond the available rooms needs a reason of at most 500 characters.', ['oversell_reason']);
            }
        }

        $now = $this->clock->nowUtc();
        $policy = $this->policies->policyFor($property, $request->ratePlanId, $source->value, $today);
        $snapshot = PolicySnapshot::fromArray($policy);
        $snapshotNights = $this->snapshot($quote, $request)['nights'];
        $depositRequired = $snapshot?->depositRequiredMinor($snapshotNights, $quote->total()->amountMinor) ?? 0;

        try {
            $reservation = new Reservation(
                $this->ids->next(),
                $this->numbers->next($property, 'RSV'),
                $status,
                $source,
                trim($request->guestName),
                $request->guestPhone === null || trim($request->guestPhone) === '' ? null : trim($request->guestPhone),
                $request->guestEmail === null || trim($request->guestEmail) === '' ? null : trim($request->guestEmail),
                $stay,
                $request->adults,
                $request->children,
                $type->id,
                strtolower($request->ratePlanId),
                null,
                $request->notes === null || trim($request->notes) === '' ? null : trim($request->notes),
                $quote->total(),
                $this->snapshot($quote, $request),
                $oversoldNights !== [],
                $oversoldNights === [] ? null : trim((string) $request->oversellReason),
                null,
                $actorId,
                0,
                $policy,
                $depositRequired,
                $snapshot?->depositDueDate($stay->arrival, $today),
            );
        } catch (ReservationRuleViolation $e) {
            throw Refusal::invalid($e->getMessage(), ['guest_name', 'guest_phone', 'guest_email', 'adults', 'children', 'notes']);
        }

        $this->reservations->add($property, $reservation, $quote->currency, $quote->totalBase()->amountMinor, $this->sumOf($quote, 'serviceCharge'), $this->sumOf($quote, 'tax'), $now);
        $this->audit->record(new AuditEntry($property->toString(), $actorId, 'reservation.created', 'reservation', $reservation->id, null, $reservation->auditView(), $oversoldNights === [] ? null : $reservation->oversellReason));
        $this->announce($property, 'frontoffice.reservation.created', $reservation, $actorId);

        return $reservation;
    }

    /**
     * @param  \Closure(Reservation): Reservation  $transition
     * @param  (\Closure(Reservation): array{amount_minor: int, free: bool, kind: string})|null  $penaltyOf  the fee this change triggers, worked out before it happens
     */
    private function change(PropertyId $property, string $actorId, string $id, int $expectedLockVersion, string $action, ?string $reason, \Closure $transition, ?\Closure $penaltyOf = null, bool $waivePenalty = false): Reservation
    {
        $this->authorize($property, $actorId, self::MANAGE_PERMISSION);
        $before = $this->reservations->find($property, strtolower($id)) ?? throw Refusal::notFound('Reservation not found.');

        if ($before->lockVersion !== $expectedLockVersion) {
            throw Refusal::stateConflict('This reservation changed after you opened it.');
        }

        try {
            $after = $transition($before);
        } catch (ReservationRuleViolation $e) {
            throw $e->reasonCode === ReservationRuleViolation::REASON_REQUIRED ? Refusal::invalid($e->getMessage(), ['reason']) : Refusal::stateConflict($e->getMessage());
        } catch (StayRuleViolation $e) {
            throw Refusal::stateConflict($e->getMessage());
        }

        $penalty = $penaltyOf === null ? null : $penaltyOf($before);

        if ($waivePenalty && ! $this->permissions->allowsInProperty($actorId, self::WAIVE_PENALTY_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not waive a cancellation fee.');
        }

        $this->transactions->run(function () use ($property, $actorId, $before, $after, $expectedLockVersion, $action, $reason, $penalty, $waivePenalty): void {
            // Releasing inventory changes availability, so it takes the same lock as selling it.
            $this->inventory->lockRoomType($property, $before->roomTypeId);

            if (! $this->reservations->saveStatus($property, $after, $expectedLockVersion, strtolower($actorId), $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This reservation changed after you opened it.');
            }

            $charged = $penalty !== null && $penalty['amount_minor'] > 0 && ! $waivePenalty;

            if ($charged) {
                $this->penalties->post($property, $actorId, $before->id, $penalty['amount_minor'], ($action === 'reservation.no_show' ? 'No-show fee ' : 'Cancellation fee ').$before->number, $before->id.':'.$action);
            }

            $this->audit->record(new AuditEntry(
                $property->toString(), strtolower($actorId), $action, 'reservation', $before->id, $before->auditView(),
                [...$after->auditView(), ...($penalty === null || $penalty['amount_minor'] === 0 ? [] : ['penalty_minor' => $penalty['amount_minor'], 'penalty_charged' => $charged])], $reason,
            ));
            $this->announce($property, 'frontoffice.'.$action, $after, strtolower($actorId));
        });

        return $this->reservations->find($property, $before->id) ?? $after;
    }

    /**
     * What breaking the booking costs now, under the policy the reservation was given.
     *
     * @return array{amount_minor: int, free: bool, kind: string}
     */
    private function penaltyFor(Reservation $reservation, string $kind, BusinessDate $today): array
    {
        $snapshot = PolicySnapshot::fromArray($reservation->policy);

        if ($snapshot === null) {
            return ['amount_minor' => 0, 'free' => true, 'kind' => 'none'];
        }

        return $kind === 'cancel'
            ? $snapshot->cancellationPenalty($today, $reservation->stay->arrival, $reservation->bookedNights())
            : $snapshot->noShowPenalty($reservation->bookedNights());
    }

    /** @return array<string, mixed> */
    private function snapshot(StayQuote $quote, ReservationRequest $request): array
    {
        return [
            'version' => 1,
            'currency' => $quote->currency,
            'rate_plan_id' => strtolower($request->ratePlanId),
            'nights' => array_map(static fn (array $n): array => [
                'date' => $n['date']->toString(),
                'quoted_minor' => $n['quoted']->amountMinor,
                'base_minor' => $n['breakdown']->base->amountMinor,
                'service_charge_minor' => $n['breakdown']->serviceCharge->amountMinor,
                'tax_minor' => $n['breakdown']->tax->amountMinor,
                'total_minor' => $n['breakdown']->total->amountMinor,
                'scheme' => $n['scheme'],
            ], $quote->nights),
        ];
    }

    private function sumOf(StayQuote $quote, string $part): int
    {
        return array_reduce($quote->nights, static fn (int $sum, array $n): int => $sum + $n['breakdown']->{$part}->amountMinor, 0);
    }

    private function announce(PropertyId $property, string $type, Reservation $r, string $actorId): void
    {
        $this->outbox->publish(new OutboxEvent($property, $type, $r->id, 1, [
            'reservation_id' => $r->id,
            'number' => $r->number,
            'status' => $r->status->value,
            'arrival' => $r->stay->arrival->toString(),
            'departure' => $r->stay->departure->toString(),
            'room_type_id' => $r->roomTypeId,
            'actor_id' => $actorId,
        ]));
    }

    private function withoutContact(Reservation $r): Reservation
    {
        return new Reservation($r->id, $r->number, $r->status, $r->source, $r->guestName, null, null, $r->stay, $r->adults, $r->children, $r->roomTypeId, $r->ratePlanId, $r->roomId, $r->notes, $r->total, $r->priceSnapshot, $r->oversold, $r->oversellReason, $r->statusReason, $r->createdBy, $r->lockVersion, $r->policy, $r->depositRequiredMinor, $r->depositDueDate);
    }

    private function mayViewContact(PropertyId $property, string $actorId): bool
    {
        return $this->permissions->allowsInProperty($actorId, self::CONTACT_PERMISSION, $property)
            || $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property);
    }

    private function authorize(PropertyId $property, string $actorId, string $permission): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }

        $allowed = $this->permissions->allowsInProperty($actorId, $permission, $property)
            || ($permission === self::VIEW_PERMISSION && $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property));

        if (! $allowed) {
            throw Refusal::forbidden('This person may not use reservations.');
        }
    }
}
