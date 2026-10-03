<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Application;

use App\Modules\FrontOffice\Application\Charging\GuestCharging;
use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Approval\ApprovalGate;
use App\Shared\Application\Approval\ApprovalRequestInput;
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
 * The bills of the outlets and what is ordered on them (FR-FBS-001, -003, -005, -008, -011, -012).
 *
 * A bill is opened from a table, from a room that has a guest in it, or over the counter; a table has one open bill at a time. A line is priced when it is ordered
 * (the name, the variant's price, the choices and their extra price are copied, so a later change of the menu never changes a bill). An item that is sold out, not
 * of this outlet, without the variant it needs, or with choices that do not satisfy its groups cannot be ordered. A line that was not sent yet may be removed freely;
 * once it was sent to the station it is only voided, with a reason and an approval the property's policy asks for (the policy is mandatory: with none configured, a
 * void is refused, not allowed). Sending hands every pending line to the stations as one batch and publishes it as a fact the kitchen works from.
 *
 * Every change names the version of the bill the person saw. When another device changed the bill meanwhile, the change is refused with a conflict and nothing is lost
 * or overwritten (FR-FBS-012).
 */
final readonly class BillService
{
    public const VOID_SUBJECT = 'fnb.item.void';

    public const CANCEL_SUBJECT = 'fnb.bill.cancel';

    public const MAX_QUANTITY = 99;

    public function __construct(
        private BillStore $bills,
        private PaymentStore $payments,
        private SetupStore $setup,
        private BillPricing $pricing,
        private BillGuard $guard,
        private PriceRuleService $prices,
        private FnbAccess $access,
        private PropertyCurrencyReader $currencies,
        private BusinessDateProvider $businessDate,
        private RoomCatalogReader $rooms,
        private GuestCharging $guests,
        private ApprovalGate $approvals,
        private DocumentNumbers $numbers,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    // ---- the floor ----

    /** @return array<string, mixed> */
    public function floor(PropertyId $property, string $actorId, ?string $outletId): array
    {
        $this->access->requireView($property, $actorId);
        $outlets = array_values(array_filter($this->setup->outlets($property), static fn (array $o): bool => (bool) $o['is_active']));
        $selected = null;

        foreach ($outlets as $o) {
            if ($outletId === null || $o['id'] === strtolower($outletId)) {
                $selected = $o;

                break;
            }
        }

        if ($outletId !== null && $selected === null) {
            throw Refusal::notFound('Outlet not found.');
        }

        $tables = [];
        $bills = [];
        $roomNumber = [];
        $inHouse = [];

        if ($selected !== null) {
            $open = $this->bills->openBills($property, $selected['id']);
            $byTable = [];

            foreach ($open as $b) {
                if ($b['table_id'] !== null) {
                    $byTable[$b['table_id']] = $b;
                }

                if ($b['room_id'] !== null && ! isset($roomNumber[$b['room_id']])) {
                    $roomNumber[$b['room_id']] = $this->rooms->room($property, $b['room_id'])?->number;
                }
            }

            $tableCode = [];

            foreach ($this->setup->tables($property, $selected['id']) as $t) {
                $tableCode[$t['id']] = $t['code'];

                if ((bool) $t['is_active']) {
                    $b = $byTable[$t['id']] ?? null;
                    $tables[] = [
                        'id' => $t['id'], 'code' => $t['code'], 'area' => $t['area'], 'seats' => (int) $t['seats'],
                        'status' => $b === null ? 'free' : ((int) $b['sent_lines'] > 0 ? 'ordered' : 'occupied'), 'bill_id' => $b['id'] ?? null, 'bill_number' => $b['number'] ?? null,
                        'subtotal_minor' => (int) ($b['subtotal_minor'] ?? 0), 'ready_lines' => (int) ($b['ready_lines'] ?? 0), 'opened_at' => FnbTime::utc($b['opened_at'] ?? null),
                    ];
                }
            }

            foreach ($open as $b) {
                $bills[] = [
                    'id' => $b['id'], 'number' => $b['number'], 'table' => $b['table_id'] === null ? null : ($tableCode[$b['table_id']] ?? null), 'room' => $b['room_id'] === null ? null : ($roomNumber[$b['room_id']] ?? null),
                    'covers' => (int) $b['covers'], 'lines' => (int) $b['line_count'], 'sent' => (int) $b['sent_lines'] > 0, 'ready_lines' => (int) $b['ready_lines'], 'subtotal_minor' => (int) $b['subtotal_minor'], 'opened_at' => FnbTime::utc($b['opened_at']),
                ];
            }
        }

        $mayOperate = $this->access->may($property, $actorId, FnbAccess::POS_OPERATE);

        if ($mayOperate && $selected !== null) {
            $inHouse = $this->inHouseRooms($property);
        }

        return [
            'currency' => $this->currencies->currencyOf($property),
            'outlets' => array_map(static fn (array $o): array => ['id' => $o['id'], 'code' => $o['code'], 'name' => $o['name'], 'kind' => $o['kind']], $outlets),
            'outlet' => $selected === null ? null : ['id' => $selected['id'], 'code' => $selected['code'], 'name' => $selected['name']],
            'tables' => $tables, 'bills' => $bills, 'rooms' => $inHouse, 'may' => ['operate' => $mayOperate],
        ];
    }

    /** @return list<array{id: string, number: string}> the rooms that have a guest in them */
    private function inHouseRooms(PropertyId $property): array
    {
        $rooms = [];

        foreach ($this->rooms->activeRooms($property) as $room) {
            if ($this->guests->inHouseStayOfRoom($property, $room->id) !== null) {
                $rooms[] = ['id' => $room->id, 'number' => $room->number];
            }
        }

        usort($rooms, static fn (array $a, array $b): int => strnatcmp($a['number'], $b['number']));

        return $rooms;
    }

    // ---- opening ----

    /** @return array<string, mixed> */
    public function open(PropertyId $property, string $actorId, string $outletId, ?string $tableId, ?string $roomId, int $covers, ?string $note): array
    {
        $this->access->require($property, $actorId, FnbAccess::POS_OPERATE, 'This person may not take orders.');
        $outlet = $this->setup->outlet($property, strtolower($outletId)) ?? throw Refusal::invalid('Choose an outlet.', ['outlet_id']);

        if (! (bool) $outlet['is_active']) {
            throw Refusal::stateConflict('This outlet is not in use.');
        }

        $table = null;

        if ($tableId !== null) {
            $table = $this->setup->table($property, strtolower($tableId));

            if ($table === null || $table['outlet_id'] !== $outlet['id'] || ! (bool) $table['is_active']) {
                throw Refusal::invalid('Choose a table of this outlet that is in use.', ['table_id']);
            }
        }

        $stay = null;

        if ($roomId !== null) {
            $room = $this->rooms->room($property, strtolower($roomId));
            $stay = $room === null ? null : $this->guests->inHouseStayOfRoom($property, $room->id);

            if ($stay === null) {
                throw Refusal::invalid('Choose a room that has a guest in it.', ['room_id']);
            }
        }

        if ($covers < 1 || $covers > 500) {
            throw Refusal::invalid('Give the number of guests, from 1 to 500.', ['covers']);
        }

        $note = $this->text($note, 200, 'note');
        $actor = strtolower($actorId);
        $id = $this->ids->next();
        $date = $this->businessDate->current($property)->toString();

        $this->transactions->run(function () use ($property, $actor, $id, $outlet, $table, $roomId, $stay, $covers, $note, $date): void {
            $number = $this->numbers->next($property, 'BILL');
            $row = [
                'id' => $id, 'outlet_id' => $outlet['id'], 'number' => $number, 'table_id' => $table['id'] ?? null, 'room_id' => $roomId === null ? null : strtolower($roomId), 'stay_id' => $stay['stay_id'] ?? null, 'reservation_id' => $stay['reservation_id'] ?? null,
                'covers' => $covers, 'note' => $note, 'status' => 'open', 'business_date' => $date, 'opened_by' => $actor, 'opened_at' => $this->clock->nowUtc(),
            ];

            if (! $this->bills->addBill($property, $row, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This table has an open bill already. Open it instead.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'fnb_bill.opened', 'fnb_bill', $id, null, ['number' => $number, 'outlet' => $outlet['code'], 'table' => $table['code'] ?? null, 'room_id' => $row['room_id'], 'covers' => $covers]));
        });

        return $this->show($property, $actorId, $id);
    }

    // ---- reading ----

    /** @return array<string, mixed> */
    public function show(PropertyId $property, string $actorId, string $billId): array
    {
        $this->access->requireView($property, $actorId);
        $bill = $this->bills->bill($property, strtolower($billId)) ?? throw Refusal::notFound('Bill not found.');
        $outlet = $this->setup->outlet($property, $bill['outlet_id']) ?? throw Refusal::notFound('Outlet not found.');
        $table = $bill['table_id'] === null ? null : $this->setup->table($property, $bill['table_id']);
        $room = $bill['room_id'] === null ? null : $this->rooms->room($property, $bill['room_id']);
        $refs = [$bill['id'] => true];

        foreach ($bill['lines'] as $l) {
            $refs[$l['id']] = true;
        }

        $approvals = [];

        foreach ($this->approvals->requestedBy($property, strtolower($actorId), 200) as $view) {
            if (in_array($view->subjectType, [self::VOID_SUBJECT, self::CANCEL_SUBJECT, LineDiscountService::DISCOUNT_SUBJECT, LineDiscountService::COMP_SUBJECT, RefundService::SUBJECT], true) && isset($refs[$view->subjectRef])) {
                $approvals[] = ['id' => $view->id, 'subject_type' => $view->subjectType, 'subject_ref' => $view->subjectRef, 'status' => $view->status, 'consumed' => $view->consumed];
            }
        }

        $open = $bill['status'] === 'open';
        $payments = $this->payments->paymentsOf($property, $bill['id']);
        $paid = 0;
        $reserved = 0;

        foreach ($payments as $p) {
            if ($p['status'] === 'paid') {
                $paid += (int) $p['amount_minor'];
            } elseif (in_array($p['status'], ['initiated', 'pending', 'unknown'], true)) {
                $reserved += (int) $p['amount_minor'];
            }
        }

        $totals = in_array($bill['status'], ['settled', 'refunded'], true)
            ? ['subtotal_minor' => (int) $bill['subtotal_minor'], 'base_minor' => (int) $bill['base_minor'], 'service_charge_minor' => (int) $bill['service_charge_minor'], 'tax_minor' => (int) $bill['tax_minor'], 'total_minor' => (int) $bill['total_minor'], 'scheme_missing' => false, 'scheme' => $bill['scheme'] === null ? null : json_decode((string) $bill['scheme'], true)]
            : $this->pricing->totals($property, $outlet, (string) $bill['business_date'], $bill['lines']);
        $refund = $bill['status'] === 'refunded' ? $this->payments->refundOf($property, $bill['id']) : null;
        $discounts = 0;

        foreach ($bill['lines'] as $l) {
            if (in_array($l['status'], ['pending', 'sent'], true)) {
                $discounts += (int) $l['discount_minor'];
            }
        }

        $shift = $this->payments->openShiftOf($property, strtolower($actorId));
        $cashier = $this->access->may($property, $actorId, FnbAccess::CASHIER_OPERATE);
        $unsent = count(array_filter($bill['lines'], static fn (array $l): bool => $l['status'] === 'pending')) > 0;

        return [
            'currency' => $this->currencies->currencyOf($property),
            'bill' => [
                'id' => $bill['id'], 'number' => $bill['number'], 'status' => $bill['status'], 'covers' => (int) $bill['covers'], 'note' => $bill['note'], 'business_date' => $bill['business_date'], 'opened_at' => FnbTime::utc($bill['opened_at']), 'closed_at' => FnbTime::utc($bill['closed_at']),
                'table' => $table['code'] ?? null, 'room_id' => $bill['room_id'], 'room' => $room?->number, 'lock_version' => (int) $bill['lock_version'], 'cancel_reason' => $bill['cancel_reason'], 'reprint_count' => (int) $bill['reprint_count'],
                'refund' => $refund === null ? null : ['number' => $refund['number'], 'reason' => $refund['reason'], 'total_minor' => (int) $refund['total_minor'], 'business_date' => $refund['business_date'], 'at' => FnbTime::utc($refund['created_at']), 'payments' => array_map(static fn (array $p): array => ['method' => $p['method'], 'amount_minor' => (int) $p['amount_minor'], 'reference' => $p['reference']], $refund['payments'])],
                'lines' => array_map($this->shapeLine(...), $bill['lines']),
            ],
            'outlet' => ['id' => $outlet['id'], 'code' => $outlet['code'], 'name' => $outlet['name'], 'prices_include_charges' => (bool) $outlet['prices_include_charges']],
            'totals' => [...$totals, 'discount_minor' => $discounts],
            'payments' => array_map(static fn (array $p): array => [
                'id' => $p['id'], 'method' => $p['method'], 'status' => $p['status'], 'amount_minor' => (int) $p['amount_minor'], 'tendered_minor' => $p['tendered_minor'] === null ? null : (int) $p['tendered_minor'], 'change_minor' => (int) $p['change_minor'],
                'reference' => $p['reference'], 'guest_name' => $p['guest_name'], 'status_reason' => $p['status_reason'], 'created_at' => FnbTime::utc($p['created_at']),
            ], $payments),
            'paid_minor' => $paid, 'reserved_minor' => $reserved, 'left_minor' => $open ? max(0, $totals['total_minor'] - $paid - $reserved) : 0,
            'shift' => $shift === null ? null : ['id' => $shift['id'], 'number' => $shift['number']],
            'rooms' => $cashier && $open && ! $unsent && $totals['total_minor'] > 0 ? $this->inHouseRooms($property) : [],
            'menu' => $open ? $this->orderMenu($property, $outlet['id'], PriceBook::channelOf($bill['table_id'], $bill['room_id'])) : [],
            'approvals' => $approvals,
            'may' => ['operate' => $this->access->may($property, $actorId, FnbAccess::POS_OPERATE) && $open, 'pay' => $cashier && $open && ! $unsent && $totals['total_minor'] > 0 && ! $totals['scheme_missing'], 'cashier' => $cashier, 'discount' => $this->access->may($property, $actorId, FnbAccess::DISCOUNT_APPLY) && $open && $payments === [], 'refund' => $this->access->may($property, $actorId, FnbAccess::REFUND_APPLY) && $bill['status'] === 'settled', 'reprint' => $this->access->may($property, $actorId, FnbAccess::RECEIPT_REPRINT) && in_array($bill['status'], ['settled', 'refunded'], true)],
        ];
    }

    // ---- ordering ----

    /**
     * @param  list<string>  $modifierIds
     * @return array<string, mixed>
     */
    public function addLine(PropertyId $property, string $actorId, string $billId, int $lock, string $itemId, ?string $variantId, array $modifierIds, int $quantity, ?string $note): array
    {
        $this->access->require($property, $actorId, FnbAccess::POS_OPERATE, 'This person may not take orders.');
        $note = $this->text($note, 120, 'note');

        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw Refusal::invalid('Give the portions, from 1 to '.self::MAX_QUANTITY.'.', ['quantity']);
        }

        $item = $this->setup->item($property, strtolower($itemId)) ?? throw Refusal::invalid('Choose an item of the menu.', ['item_id']);
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $billId, $lock, $item, $variantId, $modifierIds, $quantity, $note): void {
            $bill = $this->guard->open($property, strtolower($billId), $lock);

            if ($item['outlet_id'] !== $bill['outlet_id'] || ! (bool) $item['is_active']) {
                throw Refusal::invalid('Choose an item of the menu of this outlet.', ['item_id']);
            }

            $category = $this->setup->category($property, $item['category_id']);

            if ($category === null || ! (bool) $category['is_active']) {
                throw Refusal::invalid('Choose an item of the menu of this outlet.', ['item_id']);
            }

            if (! (bool) $item['is_available']) {
                throw Refusal::stateConflict('This item is sold out.');
            }

            $variants = array_values(array_filter($item['variants'], static fn (array $v): bool => (bool) $v['is_active']));
            $variant = null;

            if ($variants !== []) {
                foreach ($variants as $v) {
                    if ($variantId !== null && $v['id'] === strtolower($variantId)) {
                        $variant = $v;
                    }
                }

                if ($variant === null) {
                    throw Refusal::invalid('Choose which variant of this item is ordered.', ['variant_id']);
                }
            } elseif ($variantId !== null) {
                throw Refusal::invalid('This item has no variants.', ['variant_id']);
            }

            [$chosen, $extra] = $this->choices($property, $item, $modifierIds);
            $list = (int) ($variant['price_minor'] ?? $item['price_minor']);
            [$unit, $ruleId] = $this->prices->bookOf($property, $bill['outlet_id'])->price($item['id'], $variant['id'] ?? null, PriceBook::channelOf($bill['table_id'], $bill['room_id']), $list);

            $this->bills->addLine($property, $bill['id'], [
                'id' => $this->ids->next(), 'item_id' => $item['id'], 'variant_id' => $variant['id'] ?? null, 'item_code' => $item['code'], 'item_name' => $item['name'], 'variant_name' => $variant['name'] ?? null,
                'station' => $item['station'] ?? $category['station'], 'unit_price_minor' => $unit, 'price_rule_id' => $ruleId, 'list_price_minor' => $list, 'modifiers' => $chosen, 'modifiers_minor' => $extra, 'quantity' => $quantity, 'note' => $note,
                'gross_minor' => ($unit + $extra) * $quantity, 'line_total_minor' => ($unit + $extra) * $quantity, 'status' => 'pending', 'created_by' => $actor,
            ], $this->clock->nowUtc());
            $this->guard->touch($property, $bill['id'], $lock);
        });

        return $this->show($property, $actorId, $billId);
    }

    /** A line that was not sent is taken off; one that was sent is voided with an approval. @return array<string, mixed> */
    public function removeLine(PropertyId $property, string $actorId, string $billId, string $lineId, int $lock): array
    {
        $this->access->require($property, $actorId, FnbAccess::POS_OPERATE, 'This person may not take orders.');

        $this->transactions->run(function () use ($property, $billId, $lineId, $lock): void {
            $bill = $this->guard->open($property, strtolower($billId), $lock);
            $line = $this->line($bill, $lineId);

            if ($line['status'] !== 'pending') {
                throw Refusal::stateConflict('Only a line that was not sent is taken off. A line that was sent is voided, with an approval.');
            }

            $this->bills->updateLine($property, $bill['id'], $line['id'], ['status' => 'removed'], $this->clock->nowUtc());
            $this->guard->touch($property, $bill['id'], $lock);
        });

        return $this->show($property, $actorId, $billId);
    }

    /** Hands every pending line to the stations. @return array<string, mixed> */
    public function send(PropertyId $property, string $actorId, string $billId, int $lock): array
    {
        $this->access->require($property, $actorId, FnbAccess::POS_OPERATE, 'This person may not take orders.');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $billId, $lock): void {
            $bill = $this->guard->open($property, strtolower($billId), $lock);
            $pending = array_values(array_filter($bill['lines'], static fn (array $l): bool => $l['status'] === 'pending'));

            if ($pending === []) {
                throw Refusal::invalid('There is nothing to send: every line was sent already.', ['bill']);
            }

            $outlet = $this->setup->outlet($property, $bill['outlet_id']) ?? throw Refusal::notFound('Outlet not found.');
            $batchId = $this->ids->next();
            $number = $this->bills->nextBatchNumber($property, $bill['id']);
            $this->bills->sendPending($property, $bill['id'], $batchId, $actor, $this->clock->nowUtc());
            $this->guard->touch($property, $bill['id'], $lock);
            $table = $bill['table_id'] === null ? null : $this->setup->table($property, $bill['table_id']);

            $lines = array_map(static fn (array $l): array => [
                'line_id' => $l['id'], 'item_id' => $l['item_id'], 'name' => $l['item_name'], 'variant' => $l['variant_name'], 'modifiers' => array_column($l['modifiers'], 'name'), 'quantity' => (int) $l['quantity'], 'note' => $l['note'], 'station' => $l['station'],
            ], $pending);

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'fnb_bill.sent', 'fnb_bill', $bill['id'], null, ['number' => $bill['number'], 'batch' => $number, 'lines' => count($pending)]));
            $this->outbox->publish(new OutboxEvent($property, 'fnb.order.sent', $batchId, 1, [
                'batch_id' => $batchId, 'batch_number' => $number, 'bill_id' => $bill['id'], 'bill_number' => $bill['number'], 'outlet_id' => $outlet['id'], 'outlet_code' => $outlet['code'], 'table' => $table['code'] ?? null,
                'room_id' => $bill['room_id'], 'room' => $bill['room_id'] === null ? null : $this->rooms->room($property, $bill['room_id'])?->number, 'outlet_name' => $outlet['name'], 'business_date' => $bill['business_date'], 'actor_id' => $actor, 'lines' => $lines,
            ]));
        });

        return $this->show($property, $actorId, $billId);
    }

    // ---- void and cancel ----

    /** Opens the approval a void of a line that was sent needs. @return array<string, mixed> */
    public function requestVoid(PropertyId $property, string $actorId, string $billId, string $lineId, string $reason, IdempotencyKey $key): array
    {
        $this->access->require($property, $actorId, FnbAccess::POS_OPERATE, 'This person may not take orders.');
        $reason = $this->reason($reason);
        $bill = $this->bills->bill($property, strtolower($billId)) ?? throw Refusal::notFound('Bill not found.');
        $line = $this->line($bill, $lineId);

        if ($bill['status'] !== 'open' || $line['status'] !== 'sent') {
            throw Refusal::stateConflict('Only a line that was sent, on an open bill, is voided.');
        }

        $view = $this->approvals->request(new ApprovalRequestInput(
            $property, self::VOID_SUBJECT, $line['id'], strtolower($actorId), $reason, $this->voidPayload($bill, $line),
            ['bill' => $bill['number'], 'item' => $line['item_name'], 'quantity' => (int) $line['quantity']], (int) $line['line_total_minor'], $this->currencies->currencyOf($property),
        ), $key);

        return ['approval' => $view->toArray()];
    }

    /** @return array<string, mixed> */
    public function voidLine(PropertyId $property, string $actorId, string $billId, string $lineId, string $reason, ?string $approvalId, int $lock): array
    {
        $this->access->require($property, $actorId, FnbAccess::POS_OPERATE, 'This person may not take orders.');
        $reason = $this->reason($reason);
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $billId, $lineId, $reason, $approvalId, $lock): void {
            $bill = $this->guard->open($property, strtolower($billId), $lock);
            $line = $this->line($bill, $lineId);

            if ($line['status'] !== 'sent') {
                throw Refusal::stateConflict('Only a line that was sent is voided.');
            }

            $this->assertNoPayments($property, $bill['id']);

            $approval = $this->consume($property, $actor, self::VOID_SUBJECT, $line['id'], $this->voidPayload($bill, $line), (int) $line['line_total_minor'], $approvalId);
            $now = $this->clock->nowUtc();
            $this->bills->updateLine($property, $bill['id'], $line['id'], ['status' => 'voided', 'voided_by' => $actor, 'voided_at' => $now, 'void_reason' => $reason, 'void_approval_id' => $approval === '' ? null : $approval], $now);
            $this->guard->touch($property, $bill['id'], $lock);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'fnb_line.voided', 'fnb_bill', $bill['id'], ['line' => $line['line_no'], 'item' => $line['item_name'], 'quantity' => (int) $line['quantity'], 'line_total_minor' => (int) $line['line_total_minor'], 'status' => 'sent'], ['status' => 'voided'], $reason, $approval === '' ? null : $approval));
            $this->outbox->publish(new OutboxEvent($property, 'fnb.line.voided', $line['id'], 1, ['line_id' => $line['id'], 'bill_id' => $bill['id'], 'bill_number' => $bill['number'], 'item_id' => $line['item_id'], 'quantity' => (int) $line['quantity'], 'station' => $line['station'], 'batch_id' => $line['batch_id'], 'amount_minor' => (int) $line['line_total_minor'], 'actor_id' => $actor]));
        });

        return $this->show($property, $actorId, $billId);
    }

    /** Opens the approval a cancellation of a bill that has sent lines needs. @return array<string, mixed> */
    public function requestCancel(PropertyId $property, string $actorId, string $billId, string $reason, IdempotencyKey $key): array
    {
        $this->access->require($property, $actorId, FnbAccess::POS_OPERATE, 'This person may not take orders.');
        $reason = $this->reason($reason);
        $bill = $this->bills->bill($property, strtolower($billId)) ?? throw Refusal::notFound('Bill not found.');

        if ($bill['status'] !== 'open') {
            throw Refusal::stateConflict('Only an open bill is cancelled.');
        }

        $view = $this->approvals->request(new ApprovalRequestInput(
            $property, self::CANCEL_SUBJECT, $bill['id'], strtolower($actorId), $reason, $this->cancelPayload($bill), ['bill' => $bill['number']], $this->activeTotal($bill), $this->currencies->currencyOf($property),
        ), $key);

        return ['approval' => $view->toArray()];
    }

    /** @return array<string, mixed> */
    public function cancel(PropertyId $property, string $actorId, string $billId, string $reason, ?string $approvalId, int $lock): array
    {
        $this->access->require($property, $actorId, FnbAccess::POS_OPERATE, 'This person may not take orders.');
        $reason = $this->reason($reason);
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $billId, $reason, $approvalId, $lock): void {
            $bill = $this->guard->open($property, strtolower($billId), $lock);
            $this->assertNoPayments($property, $bill['id']);
            $sent = array_values(array_filter($bill['lines'], static fn (array $l): bool => $l['status'] === 'sent'));
            $approval = $sent === [] ? '' : $this->consume($property, $actor, self::CANCEL_SUBJECT, $bill['id'], $this->cancelPayload($bill), $this->activeTotal($bill), $approvalId);
            $now = $this->clock->nowUtc();

            foreach ($bill['lines'] as $l) {
                if ($l['status'] === 'sent') {
                    $this->bills->updateLine($property, $bill['id'], $l['id'], ['status' => 'voided', 'voided_by' => $actor, 'voided_at' => $now, 'void_reason' => $reason, 'void_approval_id' => $approval === '' ? null : $approval], $now);
                } elseif ($l['status'] === 'pending') {
                    $this->bills->updateLine($property, $bill['id'], $l['id'], ['status' => 'removed'], $now);
                }
            }

            $this->bills->updateBill($property, $bill['id'], ['status' => 'cancelled', 'closed_by' => $actor, 'closed_at' => $now, 'cancel_reason' => $reason, 'cancel_approval_id' => $approval === '' ? null : $approval, 'lock_version' => $lock + 1], $now);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'fnb_bill.cancelled', 'fnb_bill', $bill['id'], ['number' => $bill['number'], 'status' => 'open', 'sent_lines' => count($sent), 'amount_minor' => $this->activeTotal($bill)], ['status' => 'cancelled'], $reason, $approval === '' ? null : $approval));

            if ($sent !== []) {
                $this->outbox->publish(new OutboxEvent($property, 'fnb.bill.cancelled', $bill['id'], 1, [
                    'bill_id' => $bill['id'], 'bill_number' => $bill['number'], 'actor_id' => $actor, 'lines' => array_map(static fn (array $l): array => ['line_id' => $l['id'], 'item_id' => $l['item_id'], 'quantity' => (int) $l['quantity'], 'station' => $l['station'], 'batch_id' => $l['batch_id']], $sent),
                ]));
            }
        });

        return $this->show($property, $actorId, $billId);
    }

    // ---- helpers ----

    /**
     * @param  array<string, mixed>  $bill
     * @return array<string, mixed>
     */
    private function line(array $bill, string $lineId): array
    {
        foreach ($bill['lines'] as $l) {
            if ($l['id'] === strtolower($lineId)) {
                return $l;
            }
        }

        throw Refusal::notFound('Line not found.');
    }

    /**
     * Checks the choices against the groups the item takes and prices them.
     *
     * @param  array<string, mixed>  $item
     * @param  list<string>  $modifierIds
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function choices(PropertyId $property, array $item, array $modifierIds): array
    {
        $ids = array_values(array_unique(array_map('strtolower', $modifierIds)));
        $chosen = [];
        $extra = 0;
        $remaining = array_flip($ids);

        foreach ($item['group_ids'] as $groupId) {
            $group = $this->setup->group($property, $groupId);

            if ($group === null || ! (bool) $group['is_active']) {
                continue;
            }

            $count = 0;

            foreach ($group['modifiers'] as $m) {
                if ((bool) $m['is_active'] && isset($remaining[$m['id']])) {
                    $count++;
                    $chosen[] = ['id' => $m['id'], 'group' => $group['name'], 'name' => $m['name'], 'price_delta_minor' => (int) $m['price_delta_minor']];
                    $extra += (int) $m['price_delta_minor'];
                    unset($remaining[$m['id']]);
                }
            }

            if ($count < (int) $group['min_select'] || $count > (int) $group['max_select']) {
                throw Refusal::invalid("Choose from {$group['name']}: at least {$group['min_select']} and at most {$group['max_select']}.", ['modifier_ids']);
            }
        }

        if ($remaining !== []) {
            throw Refusal::invalid('A choice does not belong to this item.', ['modifier_ids']);
        }

        return [$chosen, $extra];
    }

    /** @return list<array<string, mixed>> the categories in use with the items in use, for taking an order */
    private function orderMenu(PropertyId $property, string $outletId, string $channel): array
    {
        $book = $this->prices->bookOf($property, $outletId);
        $items = $this->setup->items($property, $outletId);
        $groups = [];

        foreach ($this->setup->groups($property) as $g) {
            if ((bool) $g['is_active']) {
                $groups[$g['id']] = ['id' => $g['id'], 'name' => $g['name'], 'min_select' => (int) $g['min_select'], 'max_select' => (int) $g['max_select'], 'modifiers' => array_values(array_map(static fn (array $m): array => ['id' => $m['id'], 'name' => $m['name'], 'price_delta_minor' => (int) $m['price_delta_minor']], array_filter($g['modifiers'], static fn (array $m): bool => (bool) $m['is_active'])))];
            }
        }

        $menu = [];

        foreach ($this->setup->categories($property, $outletId) as $c) {
            if (! (bool) $c['is_active']) {
                continue;
            }

            $list = [];

            foreach ($items as $i) {
                if ($i['category_id'] === $c['id'] && (bool) $i['is_active']) {
                    $list[] = [
                        'id' => $i['id'], 'code' => $i['code'], 'name' => $i['name'], 'description' => $i['description'], 'price_minor' => $book->price($i['id'], null, $channel, (int) $i['price_minor'])[0], 'list_price_minor' => (int) $i['price_minor'], 'is_available' => (bool) $i['is_available'],
                        'variants' => array_values(array_map(static fn (array $v): array => ['id' => $v['id'], 'name' => $v['name'], 'price_minor' => $book->price($i['id'], $v['id'], $channel, (int) $v['price_minor'])[0], 'list_price_minor' => (int) $v['price_minor']], array_filter($i['variants'], static fn (array $v): bool => (bool) $v['is_active']))),
                        'groups' => array_values(array_filter(array_map(static fn (string $g): ?array => $groups[$g] ?? null, $i['group_ids']))),
                    ];
                }
            }

            $menu[] = ['id' => $c['id'], 'name' => $c['name'], 'station' => $c['station'], 'items' => $list];
        }

        return $menu;
    }

    /**
     * @param  array<string, mixed>  $bill
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function voidPayload(array $bill, array $line): array
    {
        return ['bill_id' => $bill['id'], 'line_id' => $line['id'], 'amount_minor' => (int) $line['line_total_minor']];
    }

    /**
     * @param  array<string, mixed>  $bill
     * @return array<string, mixed>
     */
    private function cancelPayload(array $bill): array
    {
        return ['bill_id' => $bill['id'], 'amount_minor' => $this->activeTotal($bill)];
    }

    /** @param array<string, mixed> $bill */
    private function activeTotal(array $bill): int
    {
        $sum = 0;

        foreach ($bill['lines'] as $l) {
            if (in_array($l['status'], ['pending', 'sent'], true)) {
                $sum += (int) $l['line_total_minor'];
            }
        }

        return $sum;
    }

    /**
     * Fails closed when the subject is mandatory and the property has no policy; returns the approval used, or '' when none is needed.
     *
     * @param  array<string, mixed>  $payload
     */
    private function consume(PropertyId $property, string $actor, string $subject, string $ref, array $payload, int $amount, ?string $approvalId): string
    {
        $requirement = $this->approvals->requirementFor($property, $subject, $amount);

        if (! $requirement->required) {
            return '';
        }

        if ($approvalId === null || $approvalId === '') {
            throw new ApprovalRequired;
        }

        $this->approvals->consume($property, strtolower($approvalId), $subject, $ref, $payload, $actor);

        return strtolower($approvalId);
    }

    /** A bill that has taken money is not voided or cancelled: the payment is refunded first. */
    private function assertNoPayments(PropertyId $property, string $billId): void
    {
        if ($this->bills->paymentCount($property, $billId) > 0) {
            throw Refusal::stateConflict('This bill has payments. Refund them before a line is voided or the bill is cancelled.');
        }
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Say why, in at most 200 characters.', ['reason']);
        }

        return $reason;
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

    /**
     * @param  array<string, mixed>  $l
     * @return array<string, mixed>
     */
    private function shapeLine(array $l): array
    {
        return [
            'id' => $l['id'], 'line_no' => (int) $l['line_no'], 'item_name' => $l['item_name'], 'variant_name' => $l['variant_name'], 'modifiers' => array_map(static fn (array $m): array => ['name' => $m['name'], 'price_delta_minor' => (int) $m['price_delta_minor']], $l['modifiers']),
            'quantity' => (int) $l['quantity'], 'note' => $l['note'], 'unit_price_minor' => (int) $l['unit_price_minor'], 'modifiers_minor' => (int) $l['modifiers_minor'], 'line_total_minor' => (int) $l['line_total_minor'],
            'gross_minor' => (int) ($l['gross_minor'] ?? $l['line_total_minor']), 'discount_kind' => $l['discount_kind'], 'discount_value' => $l['discount_value'] === null ? null : (int) $l['discount_value'], 'discount_minor' => (int) $l['discount_minor'], 'discount_reason' => $l['discount_reason'],
            'list_price_minor' => $l['list_price_minor'] === null ? null : (int) $l['list_price_minor'], 'price_rule_id' => $l['price_rule_id'] ?? null,
            'status' => $l['status'], 'prep_status' => $l['prep_status'], 'station' => $l['station'], 'sent_at' => FnbTime::utc($l['sent_at']), 'void_reason' => $l['void_reason'],
        ];
    }
}
