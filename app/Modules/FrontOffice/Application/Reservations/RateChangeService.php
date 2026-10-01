<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Reservations;

use App\Modules\FrontOffice\Application\Folios\ApprovalRequired;
use App\Modules\FrontOffice\Domain\Reservations\Reservation;
use App\Modules\Property\Application\Rates\ChargeCalculator;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Approval\ApprovalGate;
use App\Shared\Application\Approval\ApprovalRequestInput;
use App\Shared\Application\Approval\ApprovalView;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use InvalidArgumentException;

/**
 * Changing the room price of a reservation that is already booked (FR-FO-013). The booked price snapshot never changes (BR-002):
 * a change is a new fact for the nights that have not yet been charged (from today's business date, or a later date the person
 * picks, to the departure), with the old and new price, who, why and, when it is a discount large enough to need it, the approval.
 * Night audit charges a night at the latest change that covers it. Service charge and tax are worked out with the scheme in force
 * today, like any price quoted today.
 *
 * "A discount above the threshold needs the Manager on Duty": the threshold is the amount band of the approval policy
 * `front-office.rate.change` that the owner configures. With no policy, no discount needs approval (the subject is not mandatory);
 * a price increase never does.
 */
final readonly class RateChangeService
{
    public const CHANGE_PERMISSION = 'front-office.rate.change';

    public const SUBJECT = 'front-office.rate.change';

    private const MAX_MINOR = 1_000_000_000_000_000;

    public function __construct(
        private ReservationRepository $reservations,
        private ChargeCalculator $charges,
        private BusinessDateProvider $businessDate,
        private ApprovalGate $approvals,
        private PermissionChecker $permissions,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    /**
     * What the change would do, so the person can see it before deciding.
     *
     * @return array<string, mixed>
     */
    public function preview(PropertyId $property, string $actorId, string $reservationId, int $newNightlyMinor, bool $nett, ?string $from): array
    {
        $this->authorize($property, $actorId, self::CHANGE_PERMISSION);
        $plan = $this->plan($property, strtolower($reservationId), $newNightlyMinor, $nett, $from);

        return [
            'from' => $plan['from'], 'currency' => $plan['currency'], 'nights' => $plan['rows'],
            'old_total_minor' => $plan['old'], 'new_total_minor' => $plan['new'], 'discount_minor' => $plan['discount'], 'discount_bp' => $plan['discount_bp'],
            'approval_required' => $plan['discount'] > 0 && $this->approvals->requirementFor($property, self::SUBJECT, $plan['discount'])->required,
        ];
    }

    /** Opens the approval a discount over the threshold needs. */
    public function requestApproval(PropertyId $property, string $actorId, string $reservationId, int $newNightlyMinor, bool $nett, ?string $from, string $reason, IdempotencyKey $key): ApprovalView
    {
        $this->authorize($property, $actorId, self::CHANGE_PERMISSION);
        $reason = $this->reason($reason);
        $plan = $this->plan($property, strtolower($reservationId), $newNightlyMinor, $nett, $from);

        if ($plan['discount'] <= 0) {
            throw Refusal::stateConflict('Only a discount needs approval.');
        }

        return $this->approvals->request(new ApprovalRequestInput(
            $property, self::SUBJECT, $plan['reservation']->id, strtolower($actorId), $reason, $this->payload($plan, $newNightlyMinor, $nett),
            ['reservation' => $plan['reservation']->number, 'from' => $plan['from'], 'old_total_minor' => $plan['old'], 'new_total_minor' => $plan['new'], 'discount_bp' => $plan['discount_bp']],
            $plan['discount'], $plan['currency'],
        ), $key);
    }

    /** @return array<string, mixed> */
    public function change(PropertyId $property, string $actorId, string $reservationId, int $newNightlyMinor, bool $nett, ?string $from, string $reason, ?string $approvalId): array
    {
        $this->authorize($property, $actorId, self::CHANGE_PERMISSION);
        $reason = $this->reason($reason);
        $actor = strtolower($actorId);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actor, $reservationId, $newNightlyMinor, $nett, $from, $reason, $approvalId, $id): void {
            $plan = $this->plan($property, strtolower($reservationId), $newNightlyMinor, $nett, $from);
            $approval = null;

            if ($plan['discount'] > 0 && $this->approvals->requirementFor($property, self::SUBJECT, $plan['discount'])->required) {
                if ($approvalId === null || $approvalId === '') {
                    throw new ApprovalRequired;
                }

                $this->approvals->consume($property, strtolower($approvalId), self::SUBJECT, $plan['reservation']->id, $this->payload($plan, $newNightlyMinor, $nett), $actor);
                $approval = strtolower($approvalId);
            }

            $at = $this->clock->nowUtc();
            $this->reservations->addRateChange($property, $id, $plan['reservation']->id, BusinessDate::fromString($plan['from']), $plan['new_nights'], $plan['old_nights'], $reason, $approval, $this->businessDate->current($property), $actor, $at);

            $this->audit->record(new AuditEntry(
                $property->toString(), $actor, 'reservation.rate_changed', 'reservation', $plan['reservation']->id,
                ['total_minor' => $plan['old'], 'nights' => count($plan['rows'])],
                ['total_minor' => $plan['new'], 'discount_minor' => $plan['discount'], 'discount_bp' => $plan['discount_bp'], 'from' => $plan['from'], 'currency' => $plan['currency']],
                $reason, $approval,
            ));
            $this->outbox->publish(new OutboxEvent($property, 'frontoffice.reservation.rate_changed', $plan['reservation']->id, 1, [
                'reservation_id' => $plan['reservation']->id, 'number' => $plan['reservation']->number, 'change_id' => $id, 'from' => $plan['from'],
                'old_total_minor' => $plan['old'], 'new_total_minor' => $plan['new'], 'discount_minor' => $plan['discount'], 'currency' => $plan['currency'], 'actor_id' => $actor,
            ]));
        });

        return $this->overview($property, $actorId, $reservationId, true);
    }

    /**
     * The changes made to a reservation's price and the person's own approvals for it.
     *
     * @return array<string, mixed>
     */
    public function overview(PropertyId $property, string $actorId, string $reservationId, bool $skipAuthorization = false): array
    {
        $this->assertProperty($property);

        if (! $skipAuthorization && ! $this->permissions->allowsInProperty($actorId, ReservationService::VIEW_PERMISSION, $property) && ! $this->permissions->allowsInProperty($actorId, ReservationService::MANAGE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not see reservations.');
        }

        $reservation = $this->reservations->find($property, strtolower($reservationId)) ?? throw Refusal::notFound('Reservation not found.');
        $approvals = [];

        foreach ($this->approvals->requestedBy($property, strtolower($actorId), 200) as $view) {
            if ($view->subjectType === self::SUBJECT && $view->subjectRef === $reservation->id) {
                $approvals[] = ['id' => $view->id, 'status' => $view->status, 'consumed' => $view->consumed, 'amount_minor' => $view->amountMinor, 'payload' => $view->payload];
            }
        }

        return [
            'changes' => array_map(static fn (array $c): array => [...$c, 'nights' => count($c['nights'])], $this->reservations->rateChanges($property, $reservation->id)),
            'approvals' => $approvals,
            'may_change' => $this->permissions->allowsInProperty($actorId, self::CHANGE_PERMISSION, $property) && $reservation->status->holdsInventory(),
            'business_date' => $this->businessDate->current($property)->toString(),
        ];
    }

    // ---- internals ----

    /**
     * @return array{reservation: Reservation, from: string, currency: string, rows: list<array<string, mixed>>, old: int, new: int, discount: int, discount_bp: int, new_nights: list<array<string, mixed>>, old_nights: list<array<string, mixed>>}
     */
    private function plan(PropertyId $property, string $reservationId, int $newNightlyMinor, bool $nett, ?string $from): array
    {
        $this->assertProperty($property);

        if ($newNightlyMinor < 0 || $newNightlyMinor > self::MAX_MINOR) {
            throw Refusal::invalid('Enter the new nightly price: zero or more.', ['price']);
        }

        $reservation = $this->reservations->find($property, $reservationId) ?? throw Refusal::notFound('Reservation not found.');

        if (! $reservation->status->holdsInventory()) {
            throw Refusal::stateConflict('Only a booking that is still active can have its price changed.');
        }

        $today = $this->businessDate->current($property);

        try {
            $start = $from === null || $from === '' ? $today : BusinessDate::fromString($from);
        } catch (InvalidArgumentException $e) {
            throw Refusal::invalid($e->getMessage(), ['from']);
        }

        if ($start->isBefore($today)) {
            throw Refusal::invalid('A night that has been charged cannot be re-priced: start from today or later.', ['from']);
        }

        $overrides = $this->reservations->rateOverrides($property, $reservation->id);
        $nights = [];

        foreach ([...($reservation->priceSnapshot['nights'] ?? []), ...$this->reservations->extensionNights($property, $reservation->id)] as $night) {
            $date = (string) $night['date'];
            $nights[$date] = $overrides[$date] ?? $night;
        }

        $new = [];
        $old = [];
        $rows = [];
        $split = $this->charges->breakdown($property, 'rooms', $today, $newNightlyMinor, $nett);

        foreach ($nights as $date => $night) {
            if (BusinessDate::fromString($date)->isBefore($start) || ! BusinessDate::fromString($date)->isBefore($reservation->stay->departure)) {
                continue;
            }

            $priced = ['date' => $date, 'quoted_minor' => $newNightlyMinor, 'base_minor' => $split['base_minor'], 'service_charge_minor' => $split['service_charge_minor'], 'tax_minor' => $split['tax_minor'], 'total_minor' => $split['total_minor'], 'scheme' => $split['scheme']];
            $old[] = $night;
            $new[] = $priced;
            $rows[] = ['date' => $date, 'old_total_minor' => (int) $night['total_minor'], 'new_total_minor' => $split['total_minor']];
        }

        if ($new === []) {
            throw Refusal::stateConflict('No night from that date is left to change.');
        }

        $oldTotal = array_sum(array_column($old, 'total_minor'));
        $newTotal = array_sum(array_column($new, 'total_minor'));
        $discount = $oldTotal - $newTotal;

        if ($discount === 0) {
            throw Refusal::invalid('The new price is the same as the current one.', ['price']);
        }

        return [
            'reservation' => $reservation, 'from' => $start->toString(), 'currency' => $reservation->total->currency, 'rows' => $rows, 'old' => $oldTotal, 'new' => $newTotal,
            'discount' => $discount, 'discount_bp' => $discount > 0 && $oldTotal > 0 ? intdiv($discount * 10_000, $oldTotal) : 0, 'new_nights' => $new, 'old_nights' => $old,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function payload(array $plan, int $newNightlyMinor, bool $nett): array
    {
        return ['reservation_id' => $plan['reservation']->id, 'from' => $plan['from'], 'new_nightly_minor' => $newNightlyMinor, 'nett' => $nett, 'discount_minor' => $plan['discount']];
    }

    private function reason(string $reason): string
    {
        if (trim($reason) === '' || mb_strlen($reason) > 300) {
            throw Refusal::invalid('A reason of at most 300 characters is required.', ['reason']);
        }

        return trim($reason);
    }

    private function authorize(PropertyId $property, string $actorId, string $permission): void
    {
        $this->assertProperty($property);

        if (! $this->permissions->allowsInProperty($actorId, $permission, $property)) {
            throw Refusal::forbidden('This person may not change room prices.');
        }
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
