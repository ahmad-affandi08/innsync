<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Stays;

use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Reservations\ReservationRepository;
use App\Modules\FrontOffice\Domain\Stays\Stay;
use App\Modules\Property\Application\Ports\PropertyTimeZoneReader;
use App\Modules\Property\Application\Ports\StandardTimesReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
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
use App\Shared\Domain\Time\CalendarDate;
use InvalidArgumentException;

/**
 * Early check-in and late check-out fees (FR-FO-037). A policy (effective-dated, versioned) gives a grace period after the standard
 * time and bands of minutes with a share of the night's room price (before service charge and tax) for each; beyond the last band
 * another share. The fee of a stay comes from the time the guest actually checked in or is still in the room against the standard
 * time of the property; with no policy nothing is charged. The front desk then charges it to the folio (with the service charge and
 * tax of the rooms scheme) or waives it with a reason: one decision per stay and kind, never changed. Nothing blocks check-in or
 * check-out.
 */
final readonly class StayTimeFeeService
{
    public const POLICY_PERMISSION = 'front-office.stay-fee.policy';

    public const APPLY_PERMISSION = 'front-office.stay-fee.apply';

    public const WAIVE_PERMISSION = 'front-office.stay-fee.waive';

    public const KINDS = ['early_checkin', 'late_checkout'];

    private const CODES = ['early_checkin' => 'EARLYIN', 'late_checkout' => 'LATEOUT'];

    public function __construct(
        private StayTimeFeeRepository $fees,
        private StayRepository $stays,
        private ReservationRepository $reservations,
        private FolioService $folios,
        private StandardTimesReader $times,
        private PropertyTimeZoneReader $zones,
        private BusinessDateProvider $businessDate,
        private PermissionChecker $permissions,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    /** @return array{policies: list<array<string, mixed>>, kinds: list<string>, business_date: string, standard: array{check_in: string, check_out: string}} */
    public function policies(PropertyId $property, string $actorId): array
    {
        $this->assertProperty($property);

        if (! $this->permissions->allowsInProperty($actorId, self::POLICY_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not manage early check-in and late check-out fees.');
        }

        return ['policies' => $this->fees->policies($property), 'kinds' => self::KINDS, 'business_date' => $this->businessDate->current($property)->toString(), 'standard' => $this->times->standardTimes($property)];
    }

    /**
     * A new version of the policy of a kind, from a date not before the current business date.
     *
     * @param  list<array{up_to_minutes: int, percent_bp: int}>  $bands
     * @return array<string, mixed>
     */
    public function definePolicy(PropertyId $property, string $actorId, string $kind, string $effectiveFrom, int $graceMinutes, array $bands, int $beyondBp, string $reason): array
    {
        $this->assertProperty($property);

        if (! $this->permissions->allowsInProperty($actorId, self::POLICY_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not manage early check-in and late check-out fees.');
        }

        if (trim($reason) === '' || mb_strlen($reason) > 300) {
            throw Refusal::invalid('A reason of at most 300 characters is required.', ['reason']);
        }

        if (! in_array($kind, self::KINDS, true) || $graceMinutes < 0 || $graceMinutes > 1_440 || $beyondBp < 0 || $beyondBp > 10_000 || count($bands) > 6) {
            throw Refusal::invalid('Choose early check-in or late check-out, a grace of 0 to 1,440 minutes, at most 6 bands and a share of 0 to 100 percent.', ['kind', 'grace_minutes', 'bands', 'beyond_bp']);
        }

        $previous = $graceMinutes;

        foreach ($bands as $band) {
            if (! isset($band['up_to_minutes'], $band['percent_bp']) || $band['up_to_minutes'] <= $previous || $band['up_to_minutes'] > 1_440 || $band['percent_bp'] < 0 || $band['percent_bp'] > 10_000) {
                throw Refusal::invalid('Each band ends later than the one before (and the grace), at most 1,440 minutes, with a share of 0 to 100 percent.', ['bands']);
            }

            $previous = $band['up_to_minutes'];
        }

        try {
            $from = BusinessDate::fromString($effectiveFrom);
        } catch (InvalidArgumentException $e) {
            throw Refusal::invalid($e->getMessage(), ['effective_from']);
        }

        if ($from->isBefore($this->businessDate->current($property))) {
            throw Refusal::invalid('A policy cannot start before the current business date: stays already assessed keep their decision.', ['effective_from']);
        }

        $id = $this->ids->next();
        $bands = array_values(array_map(static fn (array $b): array => ['up_to_minutes' => (int) $b['up_to_minutes'], 'percent_bp' => (int) $b['percent_bp']], $bands));

        $this->transactions->run(function () use ($property, $actorId, $id, $kind, $from, $graceMinutes, $bands, $beyondBp, $reason): void {
            if (! $this->fees->addPolicy($property, $id, $kind, $from->toString(), $graceMinutes, $bands, $beyondBp, trim($reason), strtolower($actorId), $this->clock->nowUtc())) {
                throw Refusal::invalid('This kind already has a policy starting that date. Choose a later date.', ['effective_from']);
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'stay_fee.policy.defined', 'stay_time_policy', $id, null, ['kind' => $kind, 'effective_from' => $from->toString(), 'grace_minutes' => $graceMinutes, 'bands' => $bands, 'beyond_bp' => $beyondBp], trim($reason)));
        });

        return $this->fees->policyFor($property, $kind, $from->toString()) ?? throw Refusal::notFound('Policy not found.');
    }

    /**
     * What each fee would be for a stay, and what was decided.
     *
     * @return array<string, mixed>
     */
    public function assess(PropertyId $property, string $actorId, string $stayId): array
    {
        $this->assertProperty($property);

        if (! $this->permissions->allowsInProperty($actorId, StayService::VIEW_PERMISSION, $property) && ! $this->permissions->allowsInProperty($actorId, StayService::MANAGE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not see stays.');
        }

        $stay = $this->stays->find($property, strtolower($stayId)) ?? throw Refusal::notFound('Stay not found.');
        $items = [];

        foreach (self::KINDS as $kind) {
            $items[] = $this->assessKind($property, $stay, $kind);
        }

        return [
            'stay_id' => $stay->id, 'items' => $items,
            'may' => ['apply' => $this->permissions->allowsInProperty($actorId, self::APPLY_PERMISSION, $property), 'waive' => $this->permissions->allowsInProperty($actorId, self::WAIVE_PERMISSION, $property)],
        ];
    }

    /**
     * Charges the fee to the stay's folio, or waives it with a reason.
     *
     * @return array<string, mixed> the assessment afterwards
     */
    public function decide(PropertyId $property, string $actorId, string $stayId, string $kind, string $action, ?string $reason): array
    {
        $this->assertProperty($property);

        if (! in_array($kind, self::KINDS, true) || ! in_array($action, ['charge', 'waive'], true)) {
            throw Refusal::invalid('Choose early check-in or late check-out, and charge or waive.', ['kind', 'action']);
        }

        if (! $this->permissions->allowsInProperty($actorId, $action === 'charge' ? self::APPLY_PERMISSION : self::WAIVE_PERMISSION, $property)) {
            throw Refusal::forbidden($action === 'charge' ? 'This person may not charge this fee.' : 'This person may not waive this fee.');
        }

        $reason = $reason === null ? null : trim($reason);

        if ($action === 'waive' && ($reason === null || $reason === '' || mb_strlen($reason) > 300)) {
            throw Refusal::invalid('A reason of at most 300 characters is required to waive a fee.', ['reason']);
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $stayId, $kind, $action, $reason): void {
            $stay = $this->stays->find($property, strtolower($stayId)) ?? throw Refusal::notFound('Stay not found.');
            $item = $this->assessKind($property, $stay, $kind);

            if ($item['decision'] !== null) {
                throw Refusal::stateConflict('This fee was already decided.');
            }

            if (! $item['chargeable']) {
                throw Refusal::stateConflict('There is nothing to charge: there is no policy, the time is within the grace period, or the guest has left.');
            }

            $postingId = null;

            if ($action === 'charge') {
                $label = $kind === 'early_checkin' ? 'Early check-in' : 'Late check-out';
                $posted = $this->folios->postGuestCharge(
                    $property, $actor, $stay->reservationId, 'rooms', self::CODES[$kind], sprintf('%s fee (%d min, %s%%)', $label, $item['minutes'], rtrim(rtrim(number_format($item['percent_bp'] / 100, 2, '.', ''), '0'), '.')),
                    $item['fee_base_minor'], 'stay_fee', 'stayfee:'.$stay->id.':'.$kind,
                );
                $postingId = (string) $posted['posting']['id'];
            }

            $id = $this->ids->next();

            if (! $this->fees->addDecision($property, $id, $stay->id, $kind, $action === 'charge' ? 'charged' : 'waived', $item['minutes'], $item['percent_bp'], $item['night_base_minor'], $item['fee_base_minor'], (string) $item['policy_id'], $postingId, $action === 'waive' ? $reason : null, $actor, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This fee was already decided.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, $action === 'charge' ? 'stay_fee.charged' : 'stay_fee.waived', 'stay', $stay->id, null, ['kind' => $kind, 'minutes' => $item['minutes'], 'percent_bp' => $item['percent_bp'], 'fee_base_minor' => $item['fee_base_minor'], 'posting_id' => $postingId], $reason));
            $this->outbox->publish(new OutboxEvent($property, 'frontoffice.stay_fee.'.($action === 'charge' ? 'charged' : 'waived'), $id, 1, ['stay_id' => $stay->id, 'kind' => $kind, 'fee_base_minor' => $item['fee_base_minor'], 'actor_id' => $actor]));
        });

        return $this->assess($property, $actorId, $stayId);
    }

    /** @return array<string, mixed> */
    private function assessKind(PropertyId $property, Stay $stay, string $kind): array
    {
        $decision = $this->fees->decision($property, $stay->id, $kind);
        $base = ['kind' => $kind, 'decision' => $decision, 'policy_id' => null, 'standard_time' => null, 'minutes' => 0, 'grace_minutes' => 0, 'percent_bp' => 0, 'night_base_minor' => 0, 'fee_base_minor' => 0, 'chargeable' => false];
        $on = $kind === 'early_checkin' ? $stay->checkedInDate->toString() : $this->businessDate->current($property)->toString();
        $policy = $this->fees->policyFor($property, $kind, $on);

        if ($policy === null) {
            return $base;
        }

        $zone = $this->zones->forProperty($property) ?? throw Refusal::notFound('Property not found.');
        $standard = $this->times->standardTimes($property);
        $reservation = $this->reservations->find($property, $stay->reservationId);
        $nights = $reservation?->bookedNights() ?? [];
        $night = $nights === [] ? 0 : (int) ($kind === 'early_checkin' ? $nights[0]['base_minor'] : $nights[array_key_last($nights)]['base_minor']);

        if ($kind === 'early_checkin') {
            $at = $zone->utcAt(CalendarDate::fromString($stay->checkedInDate->toString()), $standard['check_in']);
            $minutes = max(0, intdiv($at->getTimestamp() - $stay->checkedInAt->getTimestamp(), 60));
            $open = true;
        } else {
            $at = $zone->utcAt(CalendarDate::fromString($stay->expectedDeparture->toString()), $standard['check_out']);
            $minutes = $stay->isInHouse() ? max(0, intdiv($this->clock->nowUtc()->getTimestamp() - $at->getTimestamp(), 60)) : 0;
            $open = $stay->isInHouse();
        }

        $percent = 0;

        if ($minutes > $policy['grace_minutes']) {
            $percent = $policy['beyond_bp'];

            foreach ($policy['bands'] as $band) {
                if ($minutes <= $band['up_to_minutes']) {
                    $percent = $band['percent_bp'];

                    break;
                }
            }
        }

        $fee = intdiv($night * $percent + 5_000, 10_000);

        return [...$base, 'policy_id' => $policy['id'], 'standard_time' => $kind === 'early_checkin' ? $standard['check_in'] : $standard['check_out'], 'minutes' => $minutes, 'grace_minutes' => $policy['grace_minutes'],
            'percent_bp' => $percent, 'night_base_minor' => $night, 'fee_base_minor' => $fee, 'chargeable' => $decision === null && $open && $fee > 0];
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
