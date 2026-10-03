<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Application;

use App\Modules\FrontOffice\Application\Charging\GuestCharging;
use App\Modules\GuestExperience\Application\GuestRoomCharges;
use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * What is taken for a bill (FR-FBS-007, FR-FBS-013, FR-FBS-008). A payment is recorded by a cashier with an open shift, into that shift, against a bill that has nothing waiting to be
 * sent and whose scheme of service charge and tax is configured. Several payments may add up to a bill; the bill is settled when what was paid reaches its total, and it keeps the
 * figures it came to.
 *
 * Cash gives change; the amount counted is what the bill takes. A card payment needs the approval code of the terminal. A charge to a room is for the whole bill: the room must
 * have a guest in it, the name the guest gives must match the name on the reservation, and the charge is posted to the guest's folio by the front office, once per bill. A QRIS
 * payment starts initiated and goes pending, paid, failed, expired or unknown; it counts towards the bill only when it is paid, and an unknown one becomes paid only with the
 * transaction reference and a reason, after it is reconciled (there is no payment provider connected; the cashier confirms from the merchant app).
 */
final readonly class PaymentService
{
    public const METHODS = ['cash', 'card', 'qris', 'room'];

    /** Where a QRIS payment may go from each state. */
    private const QRIS_NEXT = [
        'initiated' => ['pending', 'paid', 'failed', 'expired', 'unknown'],
        'pending' => ['paid', 'failed', 'expired', 'unknown'],
        'unknown' => ['paid', 'failed', 'expired'],
    ];

    public function __construct(
        private PaymentStore $store,
        private BillStore $bills,
        private SetupStore $setup,
        private BillPricing $pricing,
        private BillGuard $guard,
        private BillService $billService,
        private FnbAccess $access,
        private PropertyCurrencyReader $currencies,
        private BusinessDateProvider $businessDate,
        private RoomCatalogReader $rooms,
        private GuestCharging $guests,
        private GuestRoomCharges $guestCharges,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> the bill as it stands */
    public function pay(PropertyId $property, string $actorId, string $billId, int $lock, string $method, int $amountMinor, ?int $tenderedMinor, ?string $reference, ?string $roomId, ?string $guestName): array
    {
        $this->access->require($property, $actorId, FnbAccess::CASHIER_OPERATE, 'This person may not take payments.');

        if (! in_array($method, self::METHODS, true)) {
            throw Refusal::invalid('Choose how it is paid: cash, card, QRIS or a room.', ['method']);
        }

        if ($amountMinor < 1 || $amountMinor > 9_000_000_000_000) {
            throw Refusal::invalid('Give the amount as more than zero.', ['amount_minor']);
        }

        $reference = $this->text($reference, 60, 'reference');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $billId, $lock, $method, $amountMinor, $tenderedMinor, $reference, $roomId, $guestName): void {
            $bill = $this->guard->open($property, strtolower($billId), $lock);
            $shift = $this->store->openShiftOf($property, $actor) ?? throw Refusal::stateConflict('Open your cashier shift before taking a payment.');
            [$outlet, $totals] = $this->ready($property, $bill);
            $payments = $this->store->paymentsOf($property, $bill['id']);
            $paid = $this->sum($payments, ['paid']);
            $reserved = $this->sum($payments, ['initiated', 'pending', 'unknown']);
            $left = $totals['total_minor'] - $paid - $reserved;

            if ($amountMinor > $left) {
                throw Refusal::invalid($left <= 0 ? 'Nothing is left to pay on this bill.' : 'This is more than what is left to pay on this bill.', ['amount_minor']);
            }

            $row = ['id' => $this->ids->next(), 'bill_id' => $bill['id'], 'shift_id' => $shift['id'], 'method' => $method, 'status' => 'paid', 'amount_minor' => $amountMinor, 'tendered_minor' => null, 'change_minor' => 0, 'reference' => $reference,
                'room_id' => null, 'guest_name' => null, 'folio_posting_id' => null, 'business_date' => $this->businessDate->current($property)->toString(), 'created_by' => $actor, 'status_changed_at' => $this->clock->nowUtc()];

            if ($method === 'cash') {
                if ($tenderedMinor === null || $tenderedMinor < $amountMinor) {
                    throw Refusal::invalid('The cash handed over is less than the amount.', ['tendered_minor']);
                }

                $row['tendered_minor'] = $tenderedMinor;
                $row['change_minor'] = $tenderedMinor - $amountMinor;
            } elseif ($method === 'card') {
                if ($reference === null || mb_strlen($reference) < 4) {
                    throw Refusal::invalid('Give the approval code of the card terminal.', ['reference']);
                }
            } elseif ($method === 'qris') {
                $row['status'] = 'initiated';
                $row['status_changed_at'] = null;
            } else {
                if ($paid > 0 || $reserved > 0 || $amountMinor !== $totals['total_minor']) {
                    throw Refusal::invalid('A bill is charged to a room in full.', ['amount_minor']);
                }

                $verdict = $this->guestCharges->verdict($property, $bill['id']);

                if ($verdict === 'pending') {
                    throw Refusal::stateConflict('A guest asked to charge this bill to the room and a person has not verified the guest yet. Verify the guest first.');
                }

                if ($verdict === 'rejected') {
                    throw Refusal::stateConflict('The charge to the room of this guest order was refused. Take another way of payment.');
                }

                $stay = $this->roomStay($property, $roomId ?? $bill['room_id'], $guestName);
                $result = $this->guests->charge($property, $actor, $stay['reservation_id'], (string) $outlet['charge_scope'], 'FNB', "{$outlet['name']} {$bill['number']}", (bool) $outlet['prices_include_charges'] ? $totals['base_minor'] : $totals['subtotal_minor'], 'pos_'.strtolower((string) $outlet['code']), $bill['id']);

                if ((int) $result['total_minor'] !== $totals['total_minor']) {
                    throw Refusal::stateConflict('The amount the folio takes is not the amount of the bill. Ask finance.');
                }

                $row['room_id'] = $stay['room_id'];
                $row['guest_name'] = trim((string) $guestName);
                $row['folio_posting_id'] = $result['posting_id'];
            }

            $this->store->addPayment($property, $row, $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'fnb_payment.recorded', 'fnb_bill', $bill['id'], null, ['bill' => $bill['number'], 'method' => $method, 'status' => $row['status'], 'amount_minor' => $amountMinor, 'reference' => $reference, 'room_id' => $row['room_id']]));
            $this->guard->touch($property, $bill['id'], $lock);

            if ($row['status'] === 'paid') {
                $this->settleIfCovered($property, $actor, $bill, $outlet, $totals, $paid + $amountMinor);
            }
        });

        return $this->billService->show($property, $actorId, $billId);
    }

    /** Moves a QRIS payment on: pending, paid, failed, expired or unknown. @return array<string, mixed> */
    public function updateQris(PropertyId $property, string $actorId, string $billId, string $paymentId, int $lock, string $status, ?string $reference, ?string $reason): array
    {
        $this->access->require($property, $actorId, FnbAccess::CASHIER_OPERATE, 'This person may not take payments.');
        $reference = $this->text($reference, 60, 'reference');
        $reason = $this->text($reason, 200, 'reason');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $billId, $paymentId, $lock, $status, $reference, $reason): void {
            $bill = $this->guard->open($property, strtolower($billId), $lock);
            $payment = $this->store->payment($property, strtolower($paymentId));

            if ($payment === null || $payment['bill_id'] !== $bill['id'] || $payment['method'] !== 'qris') {
                throw Refusal::notFound('Payment not found.');
            }

            if (! in_array($status, self::QRIS_NEXT[$payment['status']] ?? [], true)) {
                throw Refusal::stateConflict('A QRIS payment that is '.$payment['status'].' cannot become '.$status.'.');
            }

            if ($status === 'paid' && ($reference === null || mb_strlen($reference) < 4)) {
                throw Refusal::invalid('Give the transaction reference of the QRIS payment.', ['reference']);
            }

            if ($status === 'paid' && $payment['status'] === 'unknown' && $reason === null) {
                throw Refusal::invalid('Say how it was reconciled.', ['reason']);
            }

            if (in_array($status, ['unknown', 'failed', 'expired'], true) && $reason === null) {
                throw Refusal::invalid('Say why.', ['reason']);
            }

            $now = $this->clock->nowUtc();
            $this->store->updatePayment($property, $payment['id'], ['status' => $status, 'reference' => $reference ?? $payment['reference'], 'status_reason' => $reason, 'status_changed_at' => $now], $now);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'fnb_payment.'.$status, 'fnb_bill', $bill['id'], ['status' => $payment['status'], 'amount_minor' => (int) $payment['amount_minor']], ['status' => $status, 'reference' => $reference ?? $payment['reference']], $reason));
            $this->guard->touch($property, $bill['id'], $lock);

            if ($status === 'paid') {
                [$outlet, $totals] = $this->ready($property, $bill);
                $paid = $this->sum($this->store->paymentsOf($property, $bill['id']), ['paid']);
                $this->settleIfCovered($property, $actor, $bill, $outlet, $totals, $paid);
            }
        });

        return $this->billService->show($property, $actorId, $billId);
    }

    /**
     * The outlet and what the bill comes to, once it may be paid: something is ordered, nothing waits to be sent and the scheme is configured.
     *
     * @param  array<string, mixed>  $bill
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function ready(PropertyId $property, array $bill): array
    {
        foreach ($bill['lines'] as $l) {
            if ($l['status'] === 'pending') {
                throw Refusal::stateConflict('Send the lines that wait, or take them off, before the bill is paid.');
            }
        }

        $outlet = $this->setup->outlet($property, $bill['outlet_id']) ?? throw Refusal::notFound('Outlet not found.');
        $totals = $this->pricing->totals($property, $outlet, (string) $bill['business_date'], $bill['lines']);

        if ($totals['total_minor'] < 1) {
            throw Refusal::stateConflict('Nothing is ordered on this bill.');
        }

        if ($totals['scheme_missing']) {
            throw Refusal::stateConflict('The service charge and tax of this outlet are not configured, so the bill cannot be paid yet.');
        }

        return [$outlet, $totals];
    }

    /**
     * @param  array<string, mixed>  $bill
     * @param  array<string, mixed>  $outlet
     * @param  array<string, mixed>  $totals
     */
    private function settleIfCovered(PropertyId $property, string $actor, array $bill, array $outlet, array $totals, int $paid): void
    {
        if ($paid < $totals['total_minor']) {
            return;
        }

        $now = $this->clock->nowUtc();
        $this->store->settleBill($property, $bill['id'], [
            'subtotal_minor' => $totals['subtotal_minor'], 'base_minor' => $totals['base_minor'], 'service_charge_minor' => $totals['service_charge_minor'], 'tax_minor' => $totals['tax_minor'], 'total_minor' => $totals['total_minor'], 'scheme' => $totals['scheme'],
            'closed_by' => $actor, 'closed_at' => $now,
        ], $now);
        $payments = [];

        foreach ($this->store->paymentsOf($property, $bill['id']) as $p) {
            if ($p['status'] === 'paid') {
                $payments[$p['method']] = ($payments[$p['method']] ?? 0) + (int) $p['amount_minor'];
            }
        }

        $this->audit->record(new AuditEntry($property->toString(), $actor, 'fnb_bill.settled', 'fnb_bill', $bill['id'], ['status' => 'open'], ['status' => 'settled', 'number' => $bill['number'], 'total_minor' => $totals['total_minor'], 'payments' => $payments]));
        $this->outbox->publish(new OutboxEvent($property, 'fnb.bill.settled', $bill['id'], 1, [
            'bill_id' => $bill['id'], 'bill_number' => $bill['number'], 'outlet_id' => $outlet['id'], 'outlet_code' => $outlet['code'], 'source' => 'pos_'.strtolower((string) $outlet['code']), 'business_date' => $bill['business_date'],
            'settled_business_date' => $this->businessDate->current($property)->toString(), 'currency' => $this->currencies->currencyOf($property), 'actor_id' => $actor, 'room_id' => $bill['room_id'],
            'base_minor' => $totals['base_minor'], 'service_charge_minor' => $totals['service_charge_minor'], 'tax_minor' => $totals['tax_minor'], 'total_minor' => $totals['total_minor'],
            'payments' => array_map(static fn (string $m, int $a): array => ['method' => $m, 'amount_minor' => $a], array_keys($payments), array_values($payments)),
            'lines' => array_values(array_map(static fn (array $l): array => ['line_id' => $l['id'], 'item_id' => $l['item_id'], 'variant_id' => $l['variant_id'], 'name' => $l['item_name'], 'quantity' => (int) $l['quantity'], 'line_total_minor' => (int) $l['line_total_minor'], 'station' => $l['station']], array_filter($bill['lines'], static fn (array $l): bool => $l['status'] === 'sent'))),
        ]));
    }

    /**
     * The stay in a room, if the name given is the guest's.
     *
     * @return array{stay_id: string, reservation_id: string, room_id: string}
     */
    private function roomStay(PropertyId $property, ?string $roomId, ?string $guestName): array
    {
        if ($roomId === null) {
            throw Refusal::invalid('Choose the room it is charged to.', ['room_id']);
        }

        $room = $this->rooms->room($property, strtolower($roomId));
        $stay = $room === null ? null : $this->guests->inHouseStayOfRoom($property, $room->id);

        if ($room === null || $stay === null) {
            throw Refusal::invalid('Choose a room that has a guest in it.', ['room_id']);
        }

        if (! self::nameMatches((string) $guestName, (string) ($stay['guest_name'] ?? ''))) {
            throw Refusal::invalid('The name does not match the guest in this room.', ['guest_name']);
        }

        return ['stay_id' => $stay['stay_id'], 'reservation_id' => $stay['reservation_id'], 'room_id' => $room->id];
    }

    /** Whether what the guest says is the name on the reservation: at least three letters, and every word of it is a word, or the start of one, of the name. */
    public static function nameMatches(string $given, string $reserved): bool
    {
        $words = static fn (string $s): array => array_values(array_filter(explode(' ', trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($s))))));
        $given = $words($given);
        $reserved = $words($reserved);

        if ($reserved === [] || mb_strlen(implode('', $given)) < 3) {
            return false;
        }

        foreach ($given as $word) {
            $found = false;

            foreach ($reserved as $name) {
                if ($name === $word || (mb_strlen($word) >= 3 && str_starts_with($name, $word))) {
                    $found = true;

                    break;
                }
            }

            if (! $found) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     * @param  list<string>  $statuses
     */
    private function sum(array $payments, array $statuses): int
    {
        $sum = 0;

        foreach ($payments as $p) {
            if (in_array($p['status'], $statuses, true)) {
                $sum += (int) $p['amount_minor'];
            }
        }

        return $sum;
    }

    private function text(?string $text, int $max, string $field): ?string
    {
        $text = $text === null ? null : trim($text);
        $text = $text === '' ? null : $text;

        if ($text !== null && mb_strlen($text) > $max) {
            throw Refusal::invalid("This is at most {$max} characters.", [$field]);
        }

        return $text;
    }
}
