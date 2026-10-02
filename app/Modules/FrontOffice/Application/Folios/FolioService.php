<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Folios;

use App\Modules\FrontOffice\Application\Cashier\ShiftAttribution;
use App\Modules\FrontOffice\Application\Reservations\ReservationRepository;
use App\Modules\FrontOffice\Domain\Folios\EntryType;
use App\Modules\FrontOffice\Domain\Folios\Folio;
use App\Modules\FrontOffice\Domain\Folios\FolioRuleViolation;
use App\Modules\FrontOffice\Domain\Folios\PaymentMethod;
use App\Modules\FrontOffice\Domain\Folios\Posting;
use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Modules\Property\Application\Ports\PropertyProfileReader;
use App\Modules\Property\Application\Rates\ChargeCalculator;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Approval\ApprovalGate;
use App\Shared\Application\Approval\ApprovalRequestInput;
use App\Shared\Application\Approval\ApprovalView;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Documents\DocumentNumbers;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
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

/**
 * The folio, with permissions, audit and approval (FR-FO-020, FR-FO-024, FR-FO-025, FR-FO-029). Money enters as a charge or
 * a payment, leaves as a refund, and is corrected only by a reversal that mirrors the original; nothing is edited or deleted
 * (BR-003). Refunds, reversals of payments, and any correction of a settled folio need an approved request (BR-004): the person
 * who asked for it is the one who performs it, once, for exactly the amount that was approved.
 */
final readonly class FolioService
{
    public const VIEW_PERMISSION = 'front-office.folio.view';

    public const MANAGE_PERMISSION = 'front-office.folio.manage';

    public const CORRECT_PERMISSION = 'front-office.folio.correct';

    public const REFUND_PERMISSION = 'front-office.folio.refund';

    public const REVERSAL_SUBJECT = 'front-office.folio.reversal';

    public const REFUND_SUBJECT = 'front-office.folio.refund';

    public const REVENUE_SCOPE = 'rooms';

    public function __construct(
        private FolioRepository $folios,
        private FolioLedger $ledger,
        private ReservationRepository $reservations,
        private ChargeCalculator $charges,
        private BusinessDateProvider $businessDate,
        private DocumentNumbers $numbers,
        private ApprovalGate $approvals,
        private PermissionChecker $permissions,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private Clock $clock,
        private PropertyContext $property,
        private RoomCatalogReader $rooms,
        private PropertyProfileReader $profile,
        private ShiftAttribution $shifts,
    ) {}

    // ---- reads ----

    /** @return array<string, mixed> */
    public function view(PropertyId $property, string $actorId, string $folioId): array
    {
        $this->authorize($property, $actorId, self::VIEW_PERMISSION);
        $folio = $this->folios->find($property, strtolower($folioId)) ?? throw Refusal::notFound('Folio not found.');

        return $this->describe($property, $folio);
    }

    /**
     * The guest's bill as printed (FR-FO-021): every posting by outlet and by date, then the payments, then what is still owed.
     * Reversals appear as their own negative lines, so a printed bill never hides a correction.
     *
     * @return array<string, mixed>
     */
    public function bill(PropertyId $property, string $actorId, string $folioId): array
    {
        $this->authorize($property, $actorId, self::VIEW_PERMISSION);
        $folio = $this->folios->find($property, strtolower($folioId)) ?? throw Refusal::notFound('Folio not found.');
        $reservation = $this->reservations->find($property, $folio->reservationId) ?? throw Refusal::notFound('Reservation not found.');
        $outlets = [];
        $payments = [];
        $totals = ['base' => 0, 'service_charge' => 0, 'tax' => 0, 'total' => 0, 'paid' => 0];

        foreach ($this->folios->postings($property, $folio->id) as $p) {
            $isMoney = $p->base->amountMinor === 0 && $p->serviceCharge->amountMinor === 0 && $p->tax->amountMinor === 0 && $p->method !== null;

            if ($isMoney) {
                $payments[] = ['date' => $p->businessDate->toString(), 'type' => $p->type->value, 'method' => $p->method->value, 'reference' => $p->methodReference, 'purpose' => $p->purpose, 'amount_minor' => -$p->total->amountMinor];
                $totals['paid'] += -$p->total->amountMinor;

                continue;
            }

            $outlet = match ($p->source) {
                'night_audit' => 'rooms',
                'laundry' => 'laundry',
                'policy' => 'fees',
                default => 'other',
            };
            $outlets[$outlet] ??= ['outlet' => $outlet, 'lines' => [], 'total_minor' => 0];
            $outlets[$outlet]['lines'][] = [
                'date' => $p->businessDate->toString(), 'description' => $p->description, 'reversal' => $p->reversesId !== null,
                'base_minor' => $p->base->amountMinor, 'service_charge_minor' => $p->serviceCharge->amountMinor, 'tax_minor' => $p->tax->amountMinor, 'total_minor' => $p->total->amountMinor,
            ];
            $outlets[$outlet]['total_minor'] += $p->total->amountMinor;
            $totals['base'] += $p->base->amountMinor;
            $totals['service_charge'] += $p->serviceCharge->amountMinor;
            $totals['tax'] += $p->tax->amountMinor;
            $totals['total'] += $p->total->amountMinor;
        }

        $order = ['rooms', 'laundry', 'fees', 'other'];
        usort($outlets, static fn (array $a, array $b): int => array_search($a['outlet'], $order, true) <=> array_search($b['outlet'], $order, true));
        $room = $reservation->roomId === null ? null : $this->rooms->room($property, $reservation->roomId)?->number;

        return [
            'hotel' => $this->profile->nameOf($property),
            'currency' => $folio->currency,
            'folio' => ['number' => $folio->number, 'label' => $folio->label, 'window' => $folio->window],
            'reservation' => ['number' => $reservation->number, 'guest_name' => $reservation->guestName, 'arrival' => $reservation->stay->arrival->toString(), 'departure' => $reservation->stay->departure->toString(), 'room' => $room],
            'outlets' => $outlets,
            'payments' => $payments,
            'totals' => [...$totals, 'balance_minor' => $totals['total'] - $totals['paid']],
            'printed_at' => $this->clock->nowUtc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function forReservation(PropertyId $property, string $actorId, string $reservationId): array
    {
        $this->authorize($property, $actorId, self::VIEW_PERMISSION);

        return array_map(fn (Folio $f): array => $this->describe($property, $f), $this->folios->byReservation($property, strtolower($reservationId)));
    }

    /**
     * The person's approval requests that concern this folio or its postings, with their state, so the screen can offer an
     * approved, unused one for use.
     *
     * @return list<array<string, mixed>>
     */
    public function approvalsFor(PropertyId $property, string $actorId, string $folioId): array
    {
        $this->authorize($property, $actorId, self::VIEW_PERMISSION);
        $folio = $this->folios->find($property, strtolower($folioId)) ?? throw Refusal::notFound('Folio not found.');
        $refs = [$folio->id => true];

        foreach ($this->folios->postings($property, $folio->id) as $posting) {
            $refs[$posting->id] = true;
        }

        $result = [];

        foreach ($this->approvals->requestedBy($property, strtolower($actorId), 200) as $view) {
            if (in_array($view->subjectType, [self::REVERSAL_SUBJECT, self::REFUND_SUBJECT], true) && isset($refs[$view->subjectRef])) {
                $result[] = ['id' => $view->id, 'subject_type' => $view->subjectType, 'subject_ref' => $view->subjectRef, 'status' => $view->status, 'consumed' => $view->consumed, 'amount_minor' => $view->amountMinor, 'payload' => $view->payload];
            }
        }

        return $result;
    }

    // ---- opening and closing ----

    /** @return array<string, mixed> */
    public function open(PropertyId $property, string $actorId, string $reservationId, string $label = 'Guest', int $window = 1): array
    {
        $this->authorize($property, $actorId, self::MANAGE_PERMISSION);
        $reservation = $this->reservations->find($property, strtolower($reservationId)) ?? throw Refusal::notFound('Reservation not found.');

        if (! $reservation->status->holdsInventory() && $reservation->status->value !== 'completed') {
            throw Refusal::stateConflict('A cancelled or no-show reservation has no folio.');
        }

        if ($window < 1 || $window > 20 || trim($label) === '' || mb_strlen($label) > 60) {
            throw Refusal::invalid('A window is 1 to 20 and has a label of at most 60 characters.', ['window', 'label']);
        }

        return $this->transactions->run(function () use ($property, $actorId, $reservation, $window, $label): array {
            // The number is taken inside the transaction: a refused attempt does not consume one.
            $folio = new Folio($this->ledger->newId(), $this->numbers->next($property, 'FOL'), $reservation->id, $window, trim($label), $reservation->total->currency, false, Money::zero($reservation->total->currency), 0, 0);

            if (! $this->folios->create($property, $folio, strtolower($actorId), $this->clock->nowUtc())) {
                throw Refusal::invalid('This reservation already has a folio in that window.', ['window']);
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'folio.opened', 'folio', $folio->id, null, ['number' => $folio->number, 'reservation_id' => $folio->reservationId, 'window' => $folio->window, 'label' => $folio->label]));

            return $this->describe($property, $folio);
        });
    }

    /**
     * Closes a folio once its balance is zero (FR-FO-020). Pending items from outlets and laundry block closing once those
     * contexts exist; today the only gate is the balance.
     *
     * @return array<string, mixed>
     */
    public function close(PropertyId $property, string $actorId, string $folioId, int $expectedLockVersion): array
    {
        $this->authorize($property, $actorId, self::MANAGE_PERMISSION);

        return $this->transactions->run(function () use ($property, $actorId, $folioId, $expectedLockVersion): array {
            $folio = $this->folios->lock($property, strtolower($folioId)) ?? throw Refusal::notFound('Folio not found.');

            if ($folio->lockVersion !== $expectedLockVersion) {
                throw Refusal::stateConflict('This folio changed after you opened it.');
            }

            try {
                $folio->assertOpen();

                if ($folio->balance->amountMinor !== 0) {
                    throw FolioRuleViolation::balanceNotZero();
                }
            } catch (FolioRuleViolation $e) {
                throw Refusal::stateConflict($e->getMessage());
            }

            if (! $this->folios->close($property, $folio, strtolower($actorId), $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This folio changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'folio.closed', 'folio', $folio->id, ['status' => 'open', 'balance_minor' => 0], ['status' => 'closed']));
            $this->announce($property, 'frontoffice.folio.closed', $folio->id, ['folio_id' => $folio->id, 'reservation_id' => $folio->reservationId, 'actor_id' => strtolower($actorId)]);

            return $this->describe($property, $this->folios->find($property, $folio->id) ?? $folio);
        });
    }

    // ---- charges and payments ----

    /**
     * A charge priced with the service charge and tax in force today. `$sourceRef` makes a retry safe: the same reference
     * is posted once (BR-005).
     *
     * @return array<string, mixed>
     */
    public function charge(PropertyId $property, string $actorId, string $folioId, string $code, string $description, int $quotedMinor, bool $pricesIncludeCharges, ?string $sourceRef = null): array
    {
        $this->authorize($property, $actorId, self::MANAGE_PERMISSION);
        $today = $this->businessDate->current($property);
        $split = $this->charges->breakdown($property, self::REVENUE_SCOPE, $today, $quotedMinor, $pricesIncludeCharges);
        $folio = $this->folios->find($property, strtolower($folioId)) ?? throw Refusal::notFound('Folio not found.');
        $currency = $folio->currency;

        return $this->postAndRecord($property, $actorId, $folio->id, 'folio.charge.posted', fn ($date, $now): Posting => Posting::charge(
            $this->ledger->newId(), $code, $description, Money::ofMinor($split['base_minor'], $currency), Money::ofMinor($split['service_charge_minor'], $currency), Money::ofMinor($split['tax_minor'], $currency),
            $date, $now, strtolower($actorId), 'front_office', $sourceRef, $split['scheme'],
        ), null);
    }

    /** @return array<string, mixed> */
    public function pay(PropertyId $property, string $actorId, string $folioId, string $method, int $amountMinor, ?string $reference, string $purpose, ?string $sourceRef = null): array
    {
        $this->authorize($property, $actorId, self::MANAGE_PERMISSION);
        $paymentMethod = PaymentMethod::tryFrom($method) ?? throw Refusal::invalid('Choose cash, QRIS, card, bank transfer or online.', ['payment_method']);
        $folio = $this->folios->find($property, strtolower($folioId)) ?? throw Refusal::notFound('Folio not found.');
        $this->shifts->assertMayHandleMoney($property, $actorId);

        return $this->postAndRecord($property, $actorId, $folio->id, 'folio.payment.posted', fn ($date, $now): Posting => Posting::payment(
            $this->ledger->newId(), strtoupper($method), 'Payment by '.str_replace('_', ' ', $method), Money::ofMinor($amountMinor, $folio->currency), $paymentMethod, $reference, $purpose,
            $date, $now, strtolower($actorId), 'front_office', $sourceRef,
        ), null);
    }

    /**
     * A charge raised by another context for a guest (laundry, restaurant): to the first open folio of the reservation, priced
     * with the service charge and tax of `$scope` in force today, posted once per source reference. It checks no permission:
     * the calling context authorizes its own use, as `FolioLedger` documents.
     *
     * @return array<string, mixed>
     */
    public function postGuestCharge(PropertyId $property, string $actorId, string $reservationId, string $scope, string $code, string $description, int $quotedMinor, string $source, string $sourceRef): array
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }

        $folio = null;

        foreach ($this->folios->byReservation($property, strtolower($reservationId)) as $candidate) {
            if (! $candidate->isClosed) {
                $folio = $candidate;

                break;
            }
        }

        if ($folio === null) {
            throw Refusal::stateConflict('This guest has no open folio to charge.');
        }

        $split = $this->charges->breakdown($property, $scope, $this->businessDate->current($property), $quotedMinor, false);

        return $this->postAndRecord($property, $actorId, $folio->id, 'folio.charge.posted', fn ($date, $now): Posting => Posting::charge(
            $this->ledger->newId(), strtoupper($code), $description, Money::ofMinor($split['base_minor'], $folio->currency), Money::ofMinor($split['service_charge_minor'], $folio->currency), Money::ofMinor($split['tax_minor'], $folio->currency),
            $date, $now, strtolower($actorId), $source, $sourceRef, $split['scheme'],
        ), null);
    }

    // ---- corrections ----

    /** Opens the approval a reversal of a payment, or of anything on a settled folio, needs. */
    public function requestReversalApproval(PropertyId $property, string $actorId, string $postingId, string $reason, IdempotencyKey $key): ApprovalView
    {
        $this->authorize($property, $actorId, self::CORRECT_PERMISSION);
        [$folioId, $posting] = $this->folios->findPosting($property, strtolower($postingId)) ?? throw Refusal::notFound('Posting not found.');
        $folio = $this->folios->find($property, $folioId) ?? throw Refusal::notFound('Folio not found.');
        $amount = abs($posting->total->amountMinor);

        return $this->approvals->request(new ApprovalRequestInput(
            $property, self::REVERSAL_SUBJECT, $posting->id, strtolower($actorId), $reason,
            ['folio_id' => $folioId, 'posting_id' => $posting->id, 'amount_minor' => $amount],
            ['folio' => $folio->number, 'posting' => $posting->description, 'type' => $posting->type->value, 'total_minor' => $posting->total->amountMinor],
            $amount, $folio->currency,
        ), $key);
    }

    /** @return array<string, mixed> */
    public function reverse(PropertyId $property, string $actorId, string $postingId, string $reason, ?string $approvalId = null): array
    {
        $this->authorize($property, $actorId, self::CORRECT_PERMISSION);
        [$folioId, $original] = $this->folios->findPosting($property, strtolower($postingId)) ?? throw Refusal::notFound('Posting not found.');

        if ($original->isReversal()) {
            throw Refusal::stateConflict('A reversal cannot be reversed; post a new charge or payment instead.');
        }

        $folio = $this->folios->find($property, $folioId) ?? throw Refusal::notFound('Folio not found.');
        $settled = $folio->statusFor($this->folios->hasPayments($property, $folio->id)) === 'settled';
        $sensitive = $original->movesMoney() || $settled;

        if ($original->movesMoney()) {
            $this->shifts->assertMayHandleMoney($property, $actorId);
        }

        return $this->transactions->run(function () use ($property, $actorId, $original, $folio, $reason, $approvalId, $sensitive): array {
            $approval = null;

            if ($sensitive) {
                $approval = $this->consumeApproval($property, $actorId, self::REVERSAL_SUBJECT, $original->id, ['folio_id' => $folio->id, 'posting_id' => $original->id, 'amount_minor' => abs($original->total->amountMinor)], abs($original->total->amountMinor), $approvalId);
            }

            if ($this->folios->isReversed($property, $original->id)) {
                throw Refusal::stateConflict('This posting was already reversed.');
            }

            return $this->postAndRecord($property, $actorId, $folio->id, 'folio.posting.reversed', fn ($date, $now): Posting => Posting::reversalOf($original, $this->ledger->newId(), $reason, $date, $now, strtolower($actorId), $approval === '' ? null : $approval), $approval, $original);
        });
    }

    /**
     * Where a charge can be moved (FR-FO-023): the other open folios of the same reservation (the company's and the guest's own, for a
     * split bill) and, for someone who may correct folios, the open folios of the other guests in the house.
     *
     * @return array{same: list<array<string, mixed>>, others: list<array<string, mixed>>}
     */
    public function transferTargets(PropertyId $property, string $actorId, string $folioId): array
    {
        $this->authorize($property, $actorId, self::MANAGE_PERMISSION);
        $source = $this->folios->find($property, strtolower($folioId)) ?? throw Refusal::notFound('Folio not found.');
        $same = [];

        foreach ($this->folios->byReservation($property, $source->reservationId) as $f) {
            if ($f->id !== $source->id && ! $f->isClosed && $f->currency === $source->currency) {
                $same[] = ['folio_id' => $f->id, 'number' => $f->number, 'window' => $f->window, 'label' => $f->label];
            }
        }

        $others = [];

        if ($this->permissions->allowsInProperty($actorId, self::CORRECT_PERMISSION, $property)) {
            foreach ($this->folios->openInHouseFolios($property) as $f) {
                if ($f['reservation_id'] !== $source->reservationId) {
                    $others[] = $f;
                }
            }
        }

        return ['same' => $same, 'others' => $others];
    }

    /**
     * Moves a charge to another open folio (FR-FO-022, FR-FO-023): the folio it leaves gets the reversal of the charge and the folio
     * it goes to gets the same charge, with the reason, dated today. The ledger only grows. Within a reservation (splitting a bill
     * between the company and the guest) the right to manage folios is enough; to another guest's folio (another room) the right
     * to correct folios is needed as well, since it changes what another guest owes.
     *
     * @return array<string, mixed> the folio it left and the one it went to
     */
    public function transfer(PropertyId $property, string $actorId, string $postingId, string $targetFolioId, string $reason): array
    {
        $this->authorize($property, $actorId, self::MANAGE_PERMISSION);
        [$sourceId, $original] = $this->folios->findPosting($property, strtolower($postingId)) ?? throw Refusal::notFound('Posting not found.');
        $source = $this->folios->find($property, $sourceId) ?? throw Refusal::notFound('Folio not found.');
        $target = $this->folios->find($property, strtolower($targetFolioId)) ?? throw Refusal::invalid('Choose a folio to move it to.', ['target_folio_id']);

        if (trim($reason) === '' || mb_strlen($reason) > 400) {
            throw Refusal::invalid('A reason of at most 400 characters is required.', ['reason']);
        }

        if ($target->id === $source->id) {
            throw Refusal::invalid('Choose another folio.', ['target_folio_id']);
        }

        if ($target->reservationId !== $source->reservationId) {
            $this->authorize($property, $actorId, self::CORRECT_PERMISSION);
        }

        if ($original->type !== EntryType::Charge) {
            throw Refusal::stateConflict('Only a charge can be moved: payments and refunds stay on the folio they were made on.');
        }

        if ($source->isClosed || $target->isClosed) {
            throw Refusal::stateConflict('A closed folio cannot give or take a charge.');
        }

        if ($target->currency !== $source->currency) {
            throw Refusal::invalid('The folios are in different currencies.', ['target_folio_id']);
        }

        return $this->transactions->run(function () use ($property, $actorId, $original, $source, $target, $reason): array {
            if ($this->folios->isReversed($property, $original->id)) {
                throw Refusal::stateConflict('This charge was already reversed or moved.');
            }

            $left = $this->postAndRecord($property, $actorId, $source->id, 'folio.posting.moved_out', fn ($date, $now): Posting => Posting::reversalOf($original, $this->ledger->newId(), 'Moved to '.$target->number.': '.trim($reason), $date, $now, strtolower($actorId), null), null, $original);
            $moved = $this->postAndRecord($property, $actorId, $target->id, 'folio.posting.moved_in', fn ($date, $now): Posting => Posting::movedFrom($original, $this->ledger->newId(), $source->number, $reason, $date, $now, strtolower($actorId)), null);

            return ['from' => $left['folio'], 'to' => $moved['folio'], 'posting' => $moved['posting']];
        });
    }

    /** Money paid back to the guest, for example the unused part of a deposit (FR-FO-025). Always needs an approved request. */
    public function requestRefundApproval(PropertyId $property, string $actorId, string $folioId, string $method, int $amountMinor, string $reason, IdempotencyKey $key): ApprovalView
    {
        $this->authorize($property, $actorId, self::REFUND_PERMISSION);
        $folio = $this->folios->find($property, strtolower($folioId)) ?? throw Refusal::notFound('Folio not found.');

        if (PaymentMethod::tryFrom($method) === null || $amountMinor <= 0) {
            throw Refusal::invalid('Choose a payment method and an amount above zero.', ['payment_method', 'amount']);
        }

        return $this->approvals->request(new ApprovalRequestInput(
            $property, self::REFUND_SUBJECT, $folio->id, strtolower($actorId), $reason,
            ['folio_id' => $folio->id, 'method' => $method, 'amount_minor' => $amountMinor],
            ['folio' => $folio->number, 'balance_minor' => $folio->balance->amountMinor],
            $amountMinor, $folio->currency,
        ), $key);
    }

    /** @return array<string, mixed> */
    public function refund(PropertyId $property, string $actorId, string $folioId, string $method, int $amountMinor, ?string $reference, string $reason, ?string $approvalId): array
    {
        $this->authorize($property, $actorId, self::REFUND_PERMISSION);
        $paymentMethod = PaymentMethod::tryFrom($method) ?? throw Refusal::invalid('Choose a payment method.', ['payment_method']);
        $folio = $this->folios->find($property, strtolower($folioId)) ?? throw Refusal::notFound('Folio not found.');
        $this->shifts->assertMayHandleMoney($property, $actorId);

        return $this->transactions->run(function () use ($property, $actorId, $folio, $paymentMethod, $method, $amountMinor, $reference, $reason, $approvalId): array {
            $approval = $this->consumeApproval($property, $actorId, self::REFUND_SUBJECT, $folio->id, ['folio_id' => $folio->id, 'method' => $method, 'amount_minor' => $amountMinor], $amountMinor, $approvalId);

            return $this->postAndRecord($property, $actorId, $folio->id, 'folio.refund.posted', fn ($date, $now): Posting => Posting::refund(
                $this->ledger->newId(), 'REFUND', 'Refund by '.str_replace('_', ' ', $method), Money::ofMinor($amountMinor, $folio->currency), $paymentMethod, $reference, $reason, $date, $now, strtolower($actorId), $approval === '' ? null : $approval,
            ), $approval);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function consumeApproval(PropertyId $property, string $actorId, string $subject, string $ref, array $payload, int $amountMinor, ?string $approvalId): string
    {
        // Throws MissingApprovalPolicy when the subject is mandatory and the property has no policy: fail closed.
        $requirement = $this->approvals->requirementFor($property, $subject, $amountMinor);

        if (! $requirement->required) {
            return '';
        }

        if ($approvalId === null || $approvalId === '') {
            throw new ApprovalRequired;
        }

        $this->approvals->consume($property, strtolower($approvalId), $subject, $ref, $payload, strtolower($actorId));

        return strtolower($approvalId);
    }

    /**
     * @param  \Closure(BusinessDate, \DateTimeImmutable): Posting  $build
     * @return array<string, mixed>
     */
    private function postAndRecord(PropertyId $property, string $actorId, string $folioId, string $action, \Closure $build, ?string $approval, ?Posting $original = null): array
    {
        return $this->transactions->run(function () use ($property, $actorId, $folioId, $action, $build, $approval, $original): array {
            $result = $this->ledger->post($property, $folioId, $build);
            $posting = $result['posting'];

            if (! $result['replayed']) {
                if ($posting->movesMoney()) {
                    $this->shifts->attribute($property, $actorId, $posting->id);
                }

                $this->audit->record(new AuditEntry(
                    $property->toString(), strtolower($actorId), $action, 'folio', $folioId,
                    $original === null ? null : ['posting' => $original->toArray()],
                    ['posting' => ['type' => $posting->type->value, 'code' => $posting->code, 'total_minor' => $posting->total->amountMinor, 'currency' => $posting->total->currency, 'business_date' => $posting->businessDate->toString(), 'method' => $posting->method?->value]],
                    $posting->reason,
                    $approval === null || $approval === '' ? null : $approval,
                ));
                $this->announce($property, 'frontoffice.'.$action, $posting->id, ['folio_id' => $folioId, 'posting_id' => $posting->id, 'type' => $posting->type->value, 'total_minor' => $posting->total->amountMinor, 'actor_id' => strtolower($actorId)]);
            }

            $folio = $this->folios->find($property, $folioId) ?? throw Refusal::notFound('Folio not found.');

            return ['posting' => $posting->toArray(), 'replayed' => $result['replayed'], 'folio' => $this->describe($property, $folio)];
        });
    }

    /** @return array<string, mixed> */
    private function describe(PropertyId $property, Folio $folio): array
    {
        $postings = $this->folios->postings($property, $folio->id);
        $charges = 0;
        $payments = 0;
        $reversed = [];

        foreach ($postings as $p) {
            if ($p->reversesId !== null) {
                $reversed[$p->reversesId] = true;
            }

            $p->total->amountMinor > 0 ? $charges += $p->total->amountMinor : $payments += -$p->total->amountMinor;
        }

        return [
            'id' => $folio->id,
            'number' => $folio->number,
            'reservation_id' => $folio->reservationId,
            'window' => $folio->window,
            'label' => $folio->label,
            'currency' => $folio->currency,
            'status' => $folio->statusFor($this->folios->hasPayments($property, $folio->id)),
            'balance_minor' => $folio->balance->amountMinor,
            'charges_minor' => $charges,
            'payments_minor' => $payments,
            'lock_version' => $folio->lockVersion,
            'origin_folio_id' => $folio->originFolioId,
            'origin_number' => $folio->originFolioId === null ? null : $this->folios->find($property, $folio->originFolioId)?->number,
            'late_folios' => array_map(static fn (Folio $f): array => ['id' => $f->id, 'number' => $f->number, 'balance_minor' => $f->balance->amountMinor, 'closed' => $f->isClosed], $this->folios->lateFoliosOf($property, $folio->id)),
            'postings' => array_map(static fn (Posting $p): array => $p->toArray() + ['is_reversed' => isset($reversed[$p->id])], $postings),
        ];
    }

    /** @param array<string, mixed> $data */
    private function announce(PropertyId $property, string $type, string $aggregateId, array $data): void
    {
        $this->outbox->publish(new OutboxEvent($property, $type, $aggregateId, 1, $data));
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
            throw Refusal::forbidden('This person may not use folios.');
        }
    }
}
