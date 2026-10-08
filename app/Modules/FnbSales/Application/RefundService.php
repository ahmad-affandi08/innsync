<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Application;

use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Approval\ApprovalGate;
use App\Shared\Application\Approval\ApprovalRequestInput;
use App\Shared\Application\Approval\ApprovalRequired;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Documents\DocumentNumbers;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Giving a paid bill back, and printing another copy of its receipt (FR-FBS-014). A refund is for a whole settled bill (a void after payment is a refund): it needs the privilege,
 * a reason and the approval of a supervisor, which is mandatory (with no policy it is refused, never made). The money goes back by the methods it came by, from the shift of the
 * person who gives it back, so that shift's cash counts it; a bill charged to a room is not refunded here, since the charge is on the guest's folio and is reversed there. The
 * payments and the bill are never changed: the refund is a fact of its own that points at the bill and at each payment it reverses, with the number of the bill, and it is
 * told to finance, which takes it off the revenue of the day it was made on. What the guest ate is not given back to the pantry: the food was prepared.
 *
 * A copy of a receipt is counted and audited with the reason, so a receipt is never reprinted in silence.
 */
final readonly class RefundService
{
    public const SUBJECT = 'fnb.bill.refund';

    public function __construct(
        private PaymentStore $payments,
        private BillStore $bills,
        private SetupStore $setup,
        private FnbAccess $access,
        private ApprovalGate $approvals,
        private DocumentNumbers $numbers,
        private BusinessDateProvider $businessDate,
        private PropertyCurrencyReader $currencies,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** Opens the approval a refund needs. @return array<string, mixed> */
    public function request(PropertyId $property, string $actorId, string $billId, string $reason, IdempotencyKey $key): array
    {
        $this->access->require($property, $actorId, FnbAccess::REFUND_APPLY, 'This person may not give bills back.');
        $reason = $this->reason($reason);
        $bill = $this->settled($property, strtolower($billId));
        $view = $this->approvals->request(new ApprovalRequestInput(
            $property, self::SUBJECT, $bill['id'], strtolower($actorId), $reason, $this->payload($property, $bill), ['bill' => $bill['number']], (int) $bill['total_minor'], $this->currencies->currencyOf($property),
        ), $key);

        return ['approval' => $view->toArray()];
    }

    public function refund(PropertyId $property, string $actorId, string $billId, string $reason, ?string $approvalId, int $lock): void
    {
        $this->access->require($property, $actorId, FnbAccess::REFUND_APPLY, 'This person may not give bills back.');
        $reason = $this->reason($reason);
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $billId, $reason, $approvalId, $lock): void {
            $this->bills->lockBill($property, strtolower($billId));
            $bill = $this->settled($property, strtolower($billId));

            if ((int) $bill['lock_version'] !== $lock) {
                throw Refusal::stateConflict('This bill changed after you opened it. Reload it.');
            }

            $paid = array_values(array_filter($this->payments->paymentsOf($property, $bill['id']), static fn (array $p): bool => $p['status'] === 'paid'));

            foreach ($paid as $p) {
                if ($p['method'] === 'room') {
                    throw Refusal::stateConflict('This bill was charged to a room. The charge is reversed on the guest folio, not here.');
                }
            }

            $shift = $this->payments->openShiftOf($property, $actor) ?? throw Refusal::stateConflict('Open your cashier shift before giving a bill back: the money goes out of it.');
            $outlet = $this->setup->outlet($property, $bill['outlet_id']) ?? throw Refusal::notFound('Outlet not found.');
            $this->requireApproval($property, $actor, $bill, $approvalId);
            $now = $this->clock->nowUtc();
            $date = $this->businessDate->current($property)->toString();
            $id = $this->ids->next();
            $number = $this->numbers->next($property, 'FRF');
            $rows = [];
            $by = [];

            foreach ($paid as $p) {
                $rows[] = ['id' => $this->ids->next(), 'payment_id' => $p['id'], 'method' => $p['method'], 'amount_minor' => (int) $p['amount_minor'], 'reference' => $p['reference']];
                $by[$p['method']] = ($by[$p['method']] ?? 0) + (int) $p['amount_minor'];
            }

            $this->payments->addRefund($property, [
                'id' => $id, 'bill_id' => $bill['id'], 'number' => $number, 'shift_id' => $shift['id'], 'business_date' => $date, 'base_minor' => (int) $bill['base_minor'], 'service_charge_minor' => (int) $bill['service_charge_minor'], 'tax_minor' => (int) $bill['tax_minor'],
                'total_minor' => (int) $bill['total_minor'], 'reason' => $reason, 'approval_id' => $approvalId === null || $approvalId === '' ? null : strtolower($approvalId), 'refunded_by' => $actor,
            ], $rows, $now);
            $this->bills->updateBill($property, $bill['id'], ['status' => 'refunded', 'lock_version' => $lock + 1], $now);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'fnb_bill.refunded', 'fnb_bill', $bill['id'], ['status' => 'settled', 'number' => $bill['number'], 'total_minor' => (int) $bill['total_minor']],
                ['status' => 'refunded', 'refund' => $number, 'original_bill' => $bill['number'], 'business_date' => $date, 'shift' => $shift['number'], 'payments' => $by], $reason, $approvalId === null || $approvalId === '' ? null : strtolower($approvalId)));
            $this->outbox->publish(new OutboxEvent($property, 'fnb.bill.refunded', $id, 1, [
                'bill_id' => $bill['id'], 'bill_number' => $bill['number'], 'refund_number' => $number, 'outlet_id' => $outlet['id'], 'outlet_code' => $outlet['code'], 'source' => 'pos_'.strtolower((string) $outlet['code']), 'business_date' => $date,
                'currency' => $this->currencies->currencyOf($property), 'actor_id' => $actor, 'base_minor' => (int) $bill['base_minor'], 'service_charge_minor' => (int) $bill['service_charge_minor'], 'tax_minor' => (int) $bill['tax_minor'], 'total_minor' => (int) $bill['total_minor'],
                'payments' => array_map(static fn (string $m, int $a): array => ['method' => $m, 'amount_minor' => $a], array_keys($by), array_values($by)),
            ]));
        });
    }

    /** Counts a copy of the receipt and says which copy it is. @return array{copy: int, number: string} */
    public function reprint(PropertyId $property, string $actorId, string $billId, string $reason): array
    {
        $this->access->require($property, $actorId, FnbAccess::RECEIPT_REPRINT, 'This person may not print copies of receipts.');
        $reason = $this->reason($reason);
        $actor = strtolower($actorId);
        $copy = 0;
        $number = '';

        $this->transactions->run(function () use ($property, $actor, $billId, $reason, &$copy, &$number): void {
            $this->bills->lockBill($property, strtolower($billId));
            $bill = $this->bills->bill($property, strtolower($billId)) ?? throw Refusal::notFound('Bill not found.');

            if (! in_array($bill['status'], ['settled', 'refunded'], true)) {
                throw Refusal::stateConflict('Only the receipt of a bill that was paid is printed again.');
            }

            $copy = (int) $bill['reprint_count'] + 1;
            $number = (string) $bill['number'];
            $this->bills->updateBill($property, $bill['id'], ['reprint_count' => $copy], $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'fnb_bill.receipt_reprinted', 'fnb_bill', $bill['id'], ['copies' => $copy - 1], ['copies' => $copy, 'bill' => $number, 'status' => $bill['status']], $reason));
        });

        return ['copy' => $copy, 'number' => $number];
    }

    /** @return array<string, mixed> */
    private function settled(PropertyId $property, string $billId): array
    {
        $bill = $this->bills->bill($property, $billId) ?? throw Refusal::notFound('Bill not found.');

        if ($bill['status'] === 'refunded') {
            throw Refusal::stateConflict('This bill was given back already.');
        }

        if ($bill['status'] !== 'settled') {
            throw Refusal::stateConflict('Only a bill that was paid is given back.');
        }

        return $bill;
    }

    /**
     * @param  array<string, mixed>  $bill
     * @return array<string, mixed>
     */
    private function payload(PropertyId $property, array $bill): array
    {
        $paid = [];

        foreach ($this->payments->paymentsOf($property, $bill['id']) as $p) {
            if ($p['status'] === 'paid') {
                $paid[] = $p['id'].':'.(int) $p['amount_minor'];
            }
        }

        return ['bill_id' => $bill['id'], 'total_minor' => (int) $bill['total_minor'], 'payments' => $paid];
    }

    /** @param array<string, mixed> $bill */
    private function requireApproval(PropertyId $property, string $actor, array $bill, ?string $approvalId): void
    {
        if (! $this->approvals->requirementFor($property, self::SUBJECT, (int) $bill['total_minor'])->required) {
            return;
        }

        if ($approvalId === null || $approvalId === '') {
            throw new ApprovalRequired;
        }

        $this->approvals->consume($property, strtolower($approvalId), self::SUBJECT, $bill['id'], $this->payload($property, $bill), $actor);
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Say why, in at most 200 characters.', ['reason']);
        }

        return $reason;
    }
}
