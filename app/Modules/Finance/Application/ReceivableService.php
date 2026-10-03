<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

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
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;

/**
 * What customers owe the property (FR-FIN-014). A receivable is made from a company folio billed at check-out, or by finance for an online channel or
 * anyone else; it never changes. What is left is its amount less the receipts, in full or in part, and it is overdue when something is left after the
 * due date. Collection is written down as notes (a reminder, a call, a promise to pay by a date, a dispute). A receipt against a company folio is
 * announced so the front office settles the folio. Aging (not yet due, 1-30, 31-60, 61-90, over 90 days past due) is read as of a business date.
 */
final readonly class ReceivableService
{
    public const BUCKETS = ['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'];

    public const METHODS = ['transfer', 'giro', 'online'];

    public const NOTE_KINDS = ['reminder', 'call', 'promise', 'dispute', 'note'];

    public const ADJUSTMENTS = ['credit_note', 'write_off'];

    private const MAX_MINOR = 9_000_000_000_000;

    public function __construct(
        private ReceivableStore $store,
        private FinanceAccess $access,
        private BusinessDateProvider $businessDate,
        private PropertyCurrencyReader $currencies,
        private DocumentNumbers $numbers,
        private StaffDirectory $staff,
        private TransactionRunner $transactions,
        private IdempotentExecutor $executor,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId, ?string $status, ?string $customerId): array
    {
        $this->access->requireReceivableView($property, $actorId);
        $status = $status === null || $status === '' ? 'open' : $status;

        if (! in_array($status, ['open', 'overdue', 'paid', 'all'], true)) {
            throw Refusal::invalid('Choose open, overdue, paid or all.', ['status']);
        }

        $today = $this->businessDate->current($property)->toString();
        $rows = [];
        $owed = 0;
        $overdue = 0;
        $dueSoon = 0;
        // A property with no documents yet still has a currency: the page formats zero in it.
        $currency = $this->currencies->currencyOf($property);

        foreach ($this->store->receivables($property, $customerId === '' ? null : $customerId) as $r) {
            $shape = $this->shape($r, $today);
            $currency = $shape['currency'];

            if ($shape['balance_minor'] > 0) {
                $owed += $shape['balance_minor'];
                $overdue += $shape['overdue'] ? $shape['balance_minor'] : 0;
                $dueSoon += ! $shape['overdue'] && $shape['days_to_due'] <= 7 ? $shape['balance_minor'] : 0;
            }

            if ($status === 'all' || ($status === 'open' && $shape['balance_minor'] > 0) || ($status === 'overdue' && $shape['overdue']) || ($status === 'paid' && $shape['balance_minor'] === 0)) {
                $rows[] = $shape;
            }
        }

        if ($status === 'overdue') {
            usort($rows, static fn (array $a, array $b): int => $b['days_overdue'] <=> $a['days_overdue']);
        }

        return [
            'today' => $today, 'currency' => $currency, 'receivables' => $rows, 'owed_minor' => $owed, 'overdue_minor' => $overdue, 'due_soon_minor' => $dueSoon,
            'customers' => array_map(static fn (array $c): array => ['id' => $c['id'], 'code' => $c['code'], 'name' => $c['name'], 'kind' => $c['kind'], 'terms_days' => (int) $c['terms_days'], 'active' => (bool) $c['is_active']], $this->store->customers($property)),
            'may' => ['manage' => $this->access->may($property, $actorId, FinanceAccess::RECEIVABLE_MANAGE)],
        ];
    }

    /** @return array<string, mixed> */
    public function show(PropertyId $property, string $actorId, string $id): array
    {
        $this->access->requireReceivableView($property, $actorId);
        $r = $this->store->receivable($property, strtolower($id)) ?? throw Refusal::notFound('Receivable not found.');
        $today = $this->businessDate->current($property)->toString();
        $shape = $this->shape($r, $today);
        $names = $this->staff->namesOf($property, array_values(array_unique(array_filter([$r['actor_id'], ...array_column($r['receipts'], 'created_by'), ...array_column($r['notes'], 'created_by')]))));

        return [
            ...$shape, 'source_type' => $r['source_type'], 'source_number' => $r['source_number'], 'description' => $r['description'], 'reference' => $r['reference'], 'issued_on' => substr((string) $r['issued_on'], 0, 10), 'business_date' => substr((string) $r['business_date'], 0, 10),
            'booked_by' => $names[$r['actor_id'] ?? ''] ?? null, 'customer_kind' => $r['customer_kind'],
            'receipts' => array_map(fn (array $x): array => [
                'id' => $x['id'], 'number' => $x['number'], 'kind' => $x['kind'], 'amount_minor' => (int) $x['amount_minor'], 'method' => $x['method'], 'received_on' => substr((string) $x['received_on'], 0, 10), 'reference' => $x['reference'], 'note' => $x['note'], 'by' => $names[$x['created_by']] ?? null,
                'reversal_number' => $x['reversal_number'] ?? null, 'reverses_number' => $x['reverses_number'] ?? null,
                'may_reverse' => $x['kind'] === 'receipt' && ($x['reversal_number'] ?? null) === null && $r['source_type'] === 'manual' && $x['created_by'] !== strtolower($actorId) && $this->access->may($property, $actorId, FinanceAccess::RECEIPT_REVERSE),
            ], $r['receipts']),
            'notes' => array_map(fn (array $n): array => ['id' => $n['id'], 'kind' => $n['kind'], 'note' => $n['note'], 'promised_on' => $n['promised_on'] === null ? null : substr((string) $n['promised_on'], 0, 10), 'promised_minor' => $n['promised_minor'] === null ? null : (int) $n['promised_minor'], 'by' => $names[$n['created_by']] ?? null, 'at' => $this->iso((string) $n['created_at'])], $r['notes']),
            'methods' => self::METHODS, 'note_kinds' => self::NOTE_KINDS,
            'may_receive' => $shape['balance_minor'] > 0 && $this->access->may($property, $actorId, FinanceAccess::RECEIPT_RECORD),
            'may_note' => $shape['balance_minor'] > 0 && $this->access->may($property, $actorId, FinanceAccess::RECEIVABLE_MANAGE),
            'may_adjust' => $shape['balance_minor'] > 0 && $r['source_type'] === 'manual' && ($r['actor_id'] ?? null) !== strtolower($actorId) && $this->access->may($property, $actorId, FinanceAccess::RECEIVABLE_ADJUST), 'adjust_kinds' => self::ADJUSTMENTS,
        ];
    }

    /** A receivable finance makes by hand, for an online channel's payout, a function or anything not billed through a folio. @return array<string, mixed> */
    public function create(PropertyId $property, string $actorId, string $customerId, string $description, ?string $reference, int $amountMinor, ?string $issuedOn, ?string $dueDate, ?IdempotencyKey $key = null): array
    {
        $this->access->require($property, $actorId, FinanceAccess::RECEIVABLE_MANAGE, 'This person may not make receivables.');
        $description = trim($description);
        $reference = $reference === null || trim($reference) === '' ? null : trim($reference);
        $today = $this->businessDate->current($property)->toString();
        $issuedOn = $issuedOn === null || $issuedOn === '' ? $today : $this->date($issuedOn, 'issued_on');

        if ($description === '' || mb_strlen($description) > 200) {
            throw Refusal::invalid('Describe what is owed, in at most 200 characters.', ['description']);
        }

        if ($reference !== null && mb_strlen($reference) > 60) {
            throw Refusal::invalid('The reference is at most 60 characters.', ['reference']);
        }

        if ($amountMinor < 1 || $amountMinor > self::MAX_MINOR) {
            throw Refusal::invalid('Give an amount above zero.', ['amount_minor']);
        }

        if ($issuedOn > $today) {
            throw Refusal::invalid('A receivable cannot be dated in the future.', ['issued_on']);
        }

        $actor = strtolower($actorId);
        $id = $this->ids->next();

        $operation = function () use ($property, $actor, $id, $customerId, $description, $reference, $amountMinor, $issuedOn, $dueDate, $today): void {
            $customer = $this->store->customer($property, strtolower($customerId)) ?? throw Refusal::invalid('Choose a customer from the list.', ['customer_id']);

            if (! $customer['is_active']) {
                throw Refusal::stateConflict('This customer is no longer active.');
            }

            $due = $dueDate === null || $dueDate === '' ? (new DateTimeImmutable($issuedOn, new DateTimeZone('UTC')))->modify('+'.(int) $customer['terms_days'].' days')->format('Y-m-d') : $this->date($dueDate, 'due_date');

            if ($due < $issuedOn) {
                throw Refusal::invalid('The due date is before the date of the receivable.', ['due_date']);
            }

            $number = $this->numbers->next($property, 'AR');
            $now = $this->clock->nowUtc();

            if (! $this->store->addReceivable($property, [
                'id' => $id, 'number' => $number, 'customer_id' => $customer['id'], 'source_type' => 'manual', 'source_id' => $id, 'source_number' => $number, 'description' => $description, 'reference' => $reference, 'issued_on' => $issuedOn,
                'due_date' => $due, 'business_date' => $today, 'amount_minor' => $amountMinor, 'currency' => $this->currencies->currencyOf($property), 'actor_id' => $actor, 'occurred_at' => $now->format('Y-m-d H:i:s.u'),
            ], $now)) {
                throw Refusal::stateConflict('A receivable with this number already exists. Try again.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'receivable.created', 'receivable', $id, null, ['number' => $number, 'customer' => $customer['code'], 'amount_minor' => $amountMinor, 'due_date' => $due, 'description' => $description]));
        };

        $id = $this->once($property, $actor, $key, 'finance.receivable.create', ['customer' => strtolower($customerId), 'amount' => $amountMinor, 'description' => $description, 'issued_on' => $issuedOn], $id, $operation);

        return $this->show($property, $actorId, $id);
    }

    /** @return array<string, mixed> */
    public function receive(PropertyId $property, string $actorId, string $id, int $amountMinor, string $method, ?string $receivedOn, string $reference, ?string $note, ?IdempotencyKey $key = null): array
    {
        $this->access->require($property, $actorId, FinanceAccess::RECEIPT_RECORD, 'This person may not record receipts.');
        $reference = trim($reference);
        $note = $note === null || trim($note) === '' ? null : trim($note);
        $today = $this->businessDate->current($property)->toString();
        $receivedOn = $receivedOn === null || $receivedOn === '' ? $today : $this->date($receivedOn, 'received_on');

        if (! in_array($method, self::METHODS, true)) {
            throw Refusal::invalid('Choose transfer, giro or online.', ['method']);
        }

        if ($amountMinor < 1 || $amountMinor > self::MAX_MINOR) {
            throw Refusal::invalid('Give an amount above zero.', ['amount_minor']);
        }

        if (preg_match('/^[A-Za-z0-9 ._\/#:-]{1,80}$/D', $reference) !== 1) {
            throw Refusal::invalid('Give the reference of the transfer or the giro: up to 80 letters, digits and simple punctuation.', ['reference']);
        }

        if ($note !== null && mb_strlen($note) > 200) {
            throw Refusal::invalid('The note is at most 200 characters.', ['note']);
        }

        if ($receivedOn > $today) {
            throw Refusal::invalid('A receipt cannot be dated in the future.', ['received_on']);
        }

        $actor = strtolower($actorId);
        $receiptId = $this->ids->next();

        $operation = function () use ($property, $actor, $receiptId, $id, $amountMinor, $method, $receivedOn, $reference, $note, $today): void {
            $this->store->lockReceivable($property, strtolower($id));
            $r = $this->store->receivable($property, strtolower($id)) ?? throw Refusal::notFound('Receivable not found.');
            $left = (int) $r['amount_minor'] - (int) $r['received_minor'];

            if ($amountMinor > $left) {
                throw Refusal::stateConflict('The receipt is more than the customer still owes ('.$left.').');
            }

            if ($receivedOn < substr((string) $r['issued_on'], 0, 10)) {
                throw Refusal::invalid('A receipt cannot be dated before the receivable.', ['received_on']);
            }

            $number = $this->numbers->next($property, 'RCP');

            if (! $this->store->addReceipt($property, ['id' => $receiptId, 'number' => $number, 'receivable_id' => $r['id'], 'amount_minor' => $amountMinor, 'method' => $method, 'received_on' => $receivedOn, 'reference' => $reference, 'note' => $note, 'business_date' => $today, 'created_by' => $actor], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('A receipt with this number already exists. Try again.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'receipt.recorded', 'receivable', $r['id'], null, ['number' => $number, 'receivable' => $r['number'], 'customer' => $r['customer_code'], 'amount_minor' => $amountMinor, 'method' => $method, 'left_minor' => $left - $amountMinor]));
            $this->outbox->publish(new OutboxEvent($property, 'finance.receivable.received', $receiptId, 1, [
                'receipt_id' => $receiptId, 'number' => $number, 'receivable_id' => $r['id'], 'receivable_number' => $r['number'], 'customer_code' => $r['customer_code'], 'source_type' => $r['source_type'], 'source_id' => $r['source_id'],
                'amount_minor' => $amountMinor, 'method' => $method, 'reference' => $reference, 'received_on' => $receivedOn, 'currency' => $r['currency'], 'business_date' => $today, 'actor_id' => $actor,
            ]));
        };

        $this->once($property, $actor, $key, 'finance.receivable.receive', ['receivable' => strtolower($id), 'amount' => $amountMinor, 'method' => $method, 'received_on' => $receivedOn, 'reference' => $reference], $receiptId, $operation);

        return $this->show($property, $actorId, $id);
    }

    /**
     * Takes a receipt back, in full (it was recorded in error or the money came back). The receipt is never changed: a reversal is a new row for the same amount that
     * points at it, dated today, and the receivable owes it again. Only a receivable made by hand: the receipt of a company folio was posted on the folio, which is
     * corrected in the front office. Someone other than the person who recorded the receipt reverses it, with the reason; a receipt is reversed once.
     *
     * @return array<string, mixed> the receivable
     */
    public function reverseReceipt(PropertyId $property, string $actorId, string $receiptId, string $reason): array
    {
        $this->access->require($property, $actorId, FinanceAccess::RECEIPT_REVERSE, 'This person may not reverse receipts.');
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Say why the receipt is reversed, in at most 200 characters.', ['reason']);
        }

        $actor = strtolower($actorId);
        $receivableId = '';

        $this->transactions->run(function () use ($property, $actor, $receiptId, $reason, &$receivableId): void {
            $first = $this->store->receipt($property, strtolower($receiptId)) ?? throw Refusal::notFound('Receipt not found.');
            $this->store->lockReceivable($property, $first['receivable_id']);
            $x = $this->store->receipt($property, $first['id']) ?? throw Refusal::notFound('Receipt not found.');
            $receivableId = $x['receivable_id'];

            if ($x['kind'] !== 'receipt') {
                throw Refusal::stateConflict('Only a receipt can be reversed.');
            }

            if ($x['source_type'] !== 'manual') {
                throw Refusal::stateConflict('This receipt settled a company folio; it is corrected in the front office, where the folio holds the payment.');
            }

            if (($x['reversal_number'] ?? null) !== null) {
                throw Refusal::stateConflict('This receipt was reversed already.');
            }

            if ($x['created_by'] === $actor) {
                throw Refusal::forbidden('A receipt is reversed by someone other than the person who recorded it.');
            }

            $today = $this->businessDate->current($property)->toString();
            $number = $this->numbers->next($property, 'RCP');
            $id = $this->ids->next();

            if (! $this->store->addReceipt($property, ['id' => $id, 'number' => $number, 'kind' => 'reversal', 'receivable_id' => $x['receivable_id'], 'reverses_id' => $x['id'], 'amount_minor' => (int) $x['amount_minor'], 'method' => $x['method'], 'received_on' => $today, 'reference' => null, 'note' => $reason, 'business_date' => $today, 'created_by' => $actor], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This receipt was reversed already.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'receipt.reversed', 'receivable', $x['receivable_id'], ['receipt' => $x['number'], 'amount_minor' => (int) $x['amount_minor']], ['reversal' => $number, 'receivable' => $x['receivable_number'], 'customer' => $x['customer_code'], 'method' => $x['method']], $reason));
            $this->outbox->publish(new OutboxEvent($property, 'finance.receivable.receipt_reversed', $id, 1, ['reversal_id' => $id, 'number' => $number, 'receipt_id' => $x['id'], 'receipt_number' => $x['number'], 'receivable_id' => $x['receivable_id'], 'amount_minor' => (int) $x['amount_minor'], 'method' => $x['method'], 'business_date' => $today, 'actor_id' => $actor]));
        });

        return $this->show($property, $actorId, $receivableId);
    }

    /**
     * Settles part or all of a receivable made by hand without money: a credit note (the amount was billed wrongly) or a write-off (it will not be collected). Someone
     * other than the person who made the receivable does it, with the reason, and never for more than is owed. A receivable billed from a company folio is not adjusted
     * here: its folio is the ledger and is corrected in the front office.
     *
     * @return array<string, mixed> the receivable
     */
    public function adjust(PropertyId $property, string $actorId, string $id, string $kind, int $amountMinor, string $reason): array
    {
        $this->access->require($property, $actorId, FinanceAccess::RECEIVABLE_ADJUST, 'This person may not give credit notes or write receivables off.');
        $reason = trim($reason);

        if (! in_array($kind, self::ADJUSTMENTS, true)) {
            throw Refusal::invalid('Choose a credit note or a write-off.', ['kind']);
        }

        if ($amountMinor < 1 || $amountMinor > self::MAX_MINOR) {
            throw Refusal::invalid('Give an amount above zero.', ['amount_minor']);
        }

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Say why, in at most 200 characters.', ['reason']);
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $kind, $amountMinor, $reason): void {
            $this->store->lockReceivable($property, strtolower($id));
            $r = $this->store->receivable($property, strtolower($id)) ?? throw Refusal::notFound('Receivable not found.');
            $left = (int) $r['amount_minor'] - (int) $r['received_minor'];

            if ($r['source_type'] !== 'manual') {
                throw Refusal::stateConflict('This receivable was billed from a company folio; it is corrected in the front office, where the folio is the ledger.');
            }

            if ($r['actor_id'] === $actor) {
                throw Refusal::forbidden('A receivable is adjusted by someone other than the person who made it.');
            }

            if ($amountMinor > $left) {
                throw Refusal::stateConflict('The adjustment is more than the customer still owes ('.$left.').');
            }

            $today = $this->businessDate->current($property)->toString();
            $number = $this->numbers->next($property, 'ADJ');

            if (! $this->store->addReceipt($property, ['id' => $this->ids->next(), 'number' => $number, 'kind' => $kind, 'receivable_id' => $r['id'], 'reverses_id' => null, 'amount_minor' => $amountMinor, 'method' => null, 'received_on' => $today, 'reference' => null, 'note' => $reason, 'business_date' => $today, 'created_by' => $actor], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('An adjustment with this number already exists. Try again.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'receivable.'.$kind, 'receivable', $r['id'], ['left_minor' => $left], ['number' => $number, 'receivable' => $r['number'], 'customer' => $r['customer_code'], 'amount_minor' => $amountMinor, 'left_minor' => $left - $amountMinor], $reason));
            $this->outbox->publish(new OutboxEvent($property, 'finance.receivable.adjusted', $r['id'], 1, ['receivable_id' => $r['id'], 'number' => $number, 'kind' => $kind, 'amount_minor' => $amountMinor, 'currency' => $r['currency'], 'business_date' => $today, 'actor_id' => $actor]));
        });

        return $this->show($property, $actorId, $id);
    }

    /** @return array<string, mixed> */
    public function note(PropertyId $property, string $actorId, string $id, string $kind, string $note, ?string $promisedOn, ?int $promisedMinor): array
    {
        $this->access->require($property, $actorId, FinanceAccess::RECEIVABLE_MANAGE, 'This person may not write collection notes.');
        $note = trim($note);

        if (! in_array($kind, self::NOTE_KINDS, true)) {
            throw Refusal::invalid('Choose a kind of note from the list.', ['kind']);
        }

        if ($note === '' || mb_strlen($note) > 300) {
            throw Refusal::invalid('Write the note, in at most 300 characters.', ['note']);
        }

        $today = $this->businessDate->current($property)->toString();
        $promisedOn = $promisedOn === null || $promisedOn === '' ? null : $this->date($promisedOn, 'promised_on');

        if ($kind === 'promise') {
            if ($promisedOn === null) {
                throw Refusal::invalid('Give the date the customer promised to pay by.', ['promised_on']);
            }

            if ($promisedOn < $today) {
                throw Refusal::invalid('The promised date is in the past.', ['promised_on']);
            }

            if ($promisedMinor !== null && ($promisedMinor < 1 || $promisedMinor > self::MAX_MINOR)) {
                throw Refusal::invalid('Give the promised amount above zero, or leave it empty.', ['promised_minor']);
            }
        } else {
            $promisedOn = null;
            $promisedMinor = null;
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $kind, $note, $promisedOn, $promisedMinor, $today): void {
            $r = $this->store->receivable($property, strtolower($id)) ?? throw Refusal::notFound('Receivable not found.');

            if ((int) $r['amount_minor'] - (int) $r['received_minor'] <= 0) {
                throw Refusal::stateConflict('This receivable is paid; there is nothing to collect.');
            }

            $this->store->addNote($property, ['id' => $this->ids->next(), 'receivable_id' => $r['id'], 'kind' => $kind, 'note' => $note, 'promised_on' => $promisedOn, 'promised_minor' => $promisedMinor, 'created_by' => $actor, 'business_date' => $today], $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'receivable.note_added', 'receivable', $r['id'], null, ['receivable' => $r['number'], 'kind' => $kind, 'promised_on' => $promisedOn, 'promised_minor' => $promisedMinor], $note));
        });

        return $this->show($property, $actorId, $id);
    }

    /** @return array<string, mixed> */
    public function aging(PropertyId $property, string $actorId, ?string $asOf): array
    {
        $this->access->requireReceivableView($property, $actorId);
        $date = $asOf === null || $asOf === '' ? $this->businessDate->current($property)->toString() : $this->date($asOf, 'as_of');
        $per = [];
        $totals = array_fill_keys(self::BUCKETS, 0);
        // A property with no documents yet still has a currency: the page formats zero in it.
        $currency = $this->currencies->currencyOf($property);

        foreach ($this->store->outstandingAsOf($property, $date) as $r) {
            $left = (int) $r['amount_minor'] - (int) $r['received_minor'];
            $bucket = $this->bucket(substr((string) $r['due_date'], 0, 10), $date);
            $currency = $r['currency'];
            $per[$r['customer_id']] ??= ['customer_id' => $r['customer_id'], 'customer_code' => $r['customer_code'], 'customer_name' => $r['customer_name'], 'customer_kind' => $r['customer_kind'], ...array_fill_keys(self::BUCKETS, 0), 'total_minor' => 0, 'documents' => 0];
            $per[$r['customer_id']][$bucket] += $left;
            $per[$r['customer_id']]['total_minor'] += $left;
            $per[$r['customer_id']]['documents']++;
            $totals[$bucket] += $left;
        }

        $rows = array_values($per);
        usort($rows, static fn (array $a, array $b): int => strcmp($a['customer_name'], $b['customer_name']));

        return ['as_of' => $date, 'currency' => $currency, 'rows' => $rows, 'totals' => [...$totals, 'total_minor' => array_sum($totals)], 'buckets' => self::BUCKETS];
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    private function shape(array $r, string $today): array
    {
        $received = (int) $r['received_minor'];
        $balance = (int) $r['amount_minor'] - $received;
        $due = substr((string) $r['due_date'], 0, 10);
        $days = (int) (new DateTimeImmutable($today))->diff(new DateTimeImmutable($due))->format('%r%a');

        return [
            'id' => $r['id'], 'number' => $r['number'], 'customer_id' => $r['customer_id'], 'customer_code' => $r['customer_code'], 'customer_name' => $r['customer_name'], 'source_type' => $r['source_type'], 'source_number' => $r['source_number'],
            'description' => $r['description'], 'issued_on' => substr((string) $r['issued_on'], 0, 10), 'due_date' => $due, 'amount_minor' => (int) $r['amount_minor'], 'received_minor' => $received, 'balance_minor' => $balance, 'currency' => $r['currency'],
            'status' => $balance === 0 ? 'paid' : ($received > 0 ? 'partial' : 'open'), 'overdue' => $balance > 0 && $due < $today, 'days_to_due' => $days, 'days_overdue' => $balance > 0 && $days < 0 ? -$days : 0,
            'promised_on' => ($r['promised_on'] ?? null) === null ? null : substr((string) $r['promised_on'], 0, 10), 'last_note_at' => ($r['last_note_at'] ?? null) === null ? null : $this->iso((string) $r['last_note_at']), 'note_count' => (int) ($r['note_count'] ?? 0),
        ];
    }

    private function bucket(string $due, string $asOf): string
    {
        $late = (int) (new DateTimeImmutable($due))->diff(new DateTimeImmutable($asOf))->format('%r%a');

        return match (true) {
            $late <= 0 => 'current',
            $late <= 30 => 'd1_30',
            $late <= 60 => 'd31_60',
            $late <= 90 => 'd61_90',
            default => 'd90_plus',
        };
    }

    /** @param array<string, mixed> $fingerprint */
    private function once(PropertyId $property, string $actor, ?IdempotencyKey $key, string $operationName, array $fingerprint, string $id, \Closure $operation): string
    {
        if ($key === null) {
            $this->transactions->run($operation);

            return $id;
        }

        $once = $this->executor->execute(new IdempotencyRequest($property, $key, $operationName, $fingerprint, $actor), function () use ($operation, $id): array {
            $operation();

            return ['id' => $id];
        });

        return (string) $once->payload['id'];
    }

    private function date(string $value, string $field): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw Refusal::invalid('Give the date as year-month-day.', [$field]);
        }

        return $value;
    }

    private function iso(string $utc): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
