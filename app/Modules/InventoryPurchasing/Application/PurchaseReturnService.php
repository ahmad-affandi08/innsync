<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Modules\InventoryPurchasing\Domain\StockQuantity;
use App\Modules\InventoryPurchasing\Domain\StockValue;
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
 * Goods returned to a supplier (FR-INV-011). A return is made against the receipt the goods came on and cannot exceed what that receipt line accepted
 * less what was already returned. The stock goes out of the location the goods were received into, at what they cost on the receipt (not at the
 * average of the day), and only if the location still has them. The same value comes off what is owed to the supplier; a credit note the supplier sent
 * (its number and the tax it credits) is recorded with the return. The return is never changed; a mistake is corrected by receiving the goods again.
 */
final readonly class PurchaseReturnService
{
    public const REASONS = ['damaged', 'expired', 'wrong_item', 'quality', 'overstock', 'other'];

    public function __construct(
        private PurchasingStore $store,
        private InventoryStore $inventory,
        private StockPoster $poster,
        private PurchasingAccess $access,
        private BusinessDateProvider $businessDate,
        private DocumentNumbers $numbers,
        private StaffDirectory $staff,
        private TransactionRunner $transactions,
        private IdempotentExecutor $executor,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyCurrencyReader $currency,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId, ?string $supplierId): array
    {
        $this->access->requireView($property, $actorId);
        $rows = $this->store->returns($property, $supplierId === '' ? null : $supplierId, 200);
        $suppliers = array_column($this->store->suppliers($property), null, 'id');
        $receipts = array_column($this->store->receipts($property, null, null, 500), null, 'id');
        $names = $this->staff->namesOf($property, array_values(array_unique(array_column($rows, 'returned_by'))));

        return [
            'currency' => $this->currency->currencyOf($property),
            'returns' => array_map(fn (array $r): array => $this->head($r, $suppliers[$r['supplier_id']] ?? null, $receipts[$r['receipt_id']] ?? null, $names), $rows),
            'returnable' => $this->returnable($property), 'reasons' => self::REASONS,
            'may' => ['post' => $this->access->may($property, $actorId, PurchasingAccess::RETURN_POST)],
        ];
    }

    /** @return array<string, mixed> */
    public function show(PropertyId $property, string $actorId, string $id): array
    {
        $this->access->requireView($property, $actorId);
        $return = $this->store->purchaseReturn($property, strtolower($id)) ?? throw Refusal::notFound('Purchase return not found.');
        $supplier = $this->store->supplier($property, $return['supplier_id']);
        $receipt = $this->store->receipt($property, $return['receipt_id']);
        $items = array_column($this->inventory->items($property), null, 'id');
        $names = $this->staff->namesOf($property, [$return['returned_by']]);

        return [
            ...$this->head($return, $supplier, $receipt, $names),
            'currency' => $this->currency->currencyOf($property), 'note' => $return['note'], 'credit_tax_minor' => (int) $return['credit_tax_minor'], 'credit_note_number' => $return['credit_note_number'],
            'lines' => array_map(static fn (array $l): array => [
                'id' => $l['id'], 'item_code' => $items[$l['item_id']]['code'] ?? '', 'item_name' => $items[$l['item_id']]['name'] ?? '', 'unit' => $l['unit'], 'qty_milli' => (int) $l['qty_milli'], 'unit_price_minor' => (int) $l['unit_price_minor'], 'value_minor' => (int) $l['value_minor'],
            ], $return['lines']),
        ];
    }

    /**
     * @param  list<array{receipt_line_id: string, quantity: string}>  $lines
     * @return array<string, mixed>
     */
    public function create(PropertyId $property, string $actorId, string $receiptId, string $reason, ?string $note, ?string $creditNoteNumber, int $creditTaxMinor, array $lines, ?IdempotencyKey $key = null): array
    {
        $this->access->require($property, $actorId, PurchasingAccess::RETURN_POST, 'This person may not return goods to a supplier.');
        $receipt = $this->store->receipt($property, strtolower($receiptId)) ?? throw Refusal::invalid('Choose the goods receipt the goods came on.', ['receipt_id']);

        if (! in_array($reason, self::REASONS, true)) {
            throw Refusal::invalid('Choose why the goods go back.', ['reason']);
        }

        $note = $note === null || trim($note) === '' ? null : trim($note);
        $creditNoteNumber = $creditNoteNumber === null || trim($creditNoteNumber) === '' ? null : trim($creditNoteNumber);

        if (($note !== null && mb_strlen($note) > 200) || ($reason === 'other' && $note === null)) {
            throw Refusal::invalid($reason === 'other' ? 'Say why the goods go back when the reason is "other".' : 'The note is at most 200 characters.', ['note']);
        }

        if (($creditNoteNumber !== null && mb_strlen($creditNoteNumber) > 40) || $creditTaxMinor < 0 || $creditTaxMinor > 9_000_000_000_000 || ($creditTaxMinor > 0 && $creditNoteNumber === null)) {
            throw Refusal::invalid('A credit of tax needs the number of the supplier\'s credit note, at most 40 characters.', ['credit_note_number']);
        }

        if ($lines === [] || count($lines) > PurchaseOrderService::MAX_LINES) {
            throw Refusal::invalid('Return at least one line.', ['lines']);
        }

        $actor = strtolower($actorId);
        $id = $this->ids->next();

        $operation = function () use ($property, $actor, $id, $receipt, $reason, $note, $creditNoteNumber, $creditTaxMinor, $lines): void {
            $this->store->lockOrder($property, $receipt['order_id']);
            $order = $this->store->order($property, $receipt['order_id']) ?? throw Refusal::notFound('Purchase order not found.');
            $location = $this->inventory->location($property, $receipt['location_id']) ?? throw Refusal::notFound('Location not found.');
            $byLine = array_column($receipt['lines'], null, 'id');
            $orderLines = array_column($order['lines'], null, 'id');
            $returned = $this->store->returnedQuantities($property, $receipt['id']);
            $itemIds = array_values(array_unique(array_map(static fn (array $l): string => $l['item_id'], $receipt['lines'])));
            sort($itemIds);

            foreach ($itemIds as $itemId) {
                $this->inventory->lockItem($property, $itemId);
            }

            $items = array_column($this->inventory->items($property), null, 'id');
            $prepared = [];
            $seen = [];
            $total = 0;

            foreach ($lines as $line) {
                $lineId = strtolower((string) ($line['receipt_line_id'] ?? ''));
                $rl = $byLine[$lineId] ?? throw Refusal::invalid('A line does not belong to this receipt.', ['lines']);

                if (isset($seen[$lineId])) {
                    throw Refusal::invalid('A receipt line appears once in a return.', ['lines']);
                }

                $seen[$lineId] = true;
                $qty = StockQuantity::parse((string) ($line['quantity'] ?? ''));

                if ($qty === null || $qty < 1 || $qty > StockQuantity::MAX_MILLI) {
                    throw Refusal::invalid('Give every quantity as a number above zero, with at most three decimals.', ['lines']);
                }

                $item = $items[$rl['item_id']] ?? throw Refusal::notFound('Item not found.');
                $room = (int) $rl['accepted_qty_milli'] - ($returned[$lineId] ?? 0);

                if ($qty > $room) {
                    throw Refusal::stateConflict('More of '.$item['code'].' cannot be returned than the receipt accepted and has not been returned already ('.$room.' thousandths left).');
                }

                $value = StockValue::ofQuantity($qty, (int) $rl['unit_price_minor']);
                $total += $value;
                $prepared[] = ['id' => $this->ids->next(), 'receipt_line_id' => $lineId, 'order_line_id' => $rl['order_line_id'], 'item_id' => $rl['item_id'], 'unit' => $rl['unit'], 'qty_milli' => $qty, 'unit_price_minor' => (int) $rl['unit_price_minor'], 'value_minor' => $value, 'movement_id' => '', '_item' => $item];
            }

            $number = $this->numbers->next($property, 'RTV');
            $at = $this->clock->nowUtc();
            $date = $this->businessDate->current($property)->toString();

            foreach ($prepared as &$p) {
                $moved = $this->poster->post($property, $actor, $p['_item'], $location, 'return_out', $p['unit'], $p['qty_milli'], null, $number, $note, 'purchase_return', $p['id'], null, null, false, null, null, $p['value_minor']);
                $p['movement_id'] = $moved['movement']['id'];
            }

            unset($p);

            $header = ['id' => $id, 'number' => $number, 'receipt_id' => $receipt['id'], 'order_id' => $order['id'], 'supplier_id' => $order['supplier_id'], 'location_id' => $location['id'], 'reason' => $reason, 'note' => $note, 'value_minor' => $total,
                'credit_tax_minor' => $creditTaxMinor, 'credit_note_number' => $creditNoteNumber, 'returned_by' => $actor, 'business_date' => $date];

            if (! $this->store->addReturn($property, $header, array_map(static fn (array $p): array => array_diff_key($p, ['_item' => 0]), $prepared), $at)) {
                throw Refusal::stateConflict('A return with this number already exists. Try again.');
            }

            foreach ($prepared as $p) {
                $this->store->updateOrderLine($p['order_line_id'], ['returned_qty_milli' => (int) $orderLines[$p['order_line_id']]['returned_qty_milli'] + $p['qty_milli']]);
                $orderLines[$p['order_line_id']]['returned_qty_milli'] = (int) $orderLines[$p['order_line_id']]['returned_qty_milli'] + $p['qty_milli'];
            }

            $this->store->addLedgerEntry($property, ['id' => $this->ids->next(), 'supplier_id' => $order['supplier_id'], 'kind' => 'goods_returned', 'amount_minor' => -$total, 'ref_type' => 'purchase_return', 'ref_id' => $id, 'ref_number' => $number, 'business_date' => $date, 'created_by' => $actor], $at);

            if ($creditTaxMinor > 0) {
                $this->store->addLedgerEntry($property, ['id' => $this->ids->next(), 'supplier_id' => $order['supplier_id'], 'kind' => 'credit_note', 'amount_minor' => -$creditTaxMinor, 'ref_type' => 'purchase_return', 'ref_id' => $id, 'ref_number' => (string) $creditNoteNumber, 'business_date' => $date, 'created_by' => $actor], $at);
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'purchase_return.posted', 'purchase_return', $id, null, [
                'number' => $number, 'receipt' => $receipt['number'], 'reason' => $reason, 'value_minor' => $total, 'credit_tax_minor' => $creditTaxMinor,
                'lines' => array_map(static fn (array $p): array => ['item' => $p['_item']['code'], 'qty_milli' => $p['qty_milli']], $prepared),
            ], $note));
            $this->outbox->publish(new OutboxEvent($property, 'purchasing.return.posted', $id, 1, ['return_id' => $id, 'number' => $number, 'supplier_id' => $order['supplier_id'], 'value_minor' => $total, 'credit_tax_minor' => $creditTaxMinor, 'currency' => $this->currency->currencyOf($property), 'actor_id' => $actor]));
        };

        if ($key === null) {
            $this->transactions->run($operation);
        } else {
            $once = $this->executor->execute(
                new IdempotencyRequest($property, $key, 'purchasing.return.post', ['receipt' => $receipt['id'], 'reason' => $reason, 'note' => $note, 'credit' => [$creditNoteNumber, $creditTaxMinor], 'lines' => $lines], $actor),
                function () use ($operation, $id): array {
                    $operation();

                    return ['id' => $id];
                },
            );
            $id = (string) $once->payload['id'];
        }

        return $this->show($property, $actorId, $id);
    }

    /** @return list<array<string, mixed>> receipts with goods that can still go back */
    private function returnable(PropertyId $property): array
    {
        $items = array_column($this->inventory->items($property), null, 'id');
        $suppliers = array_column($this->store->suppliers($property), null, 'id');
        $locations = array_column($this->inventory->locations($property), null, 'id');
        $out = [];

        foreach ($this->store->receipts($property, null, null, 200) as $head) {
            $receipt = $this->store->receipt($property, $head['id']);
            $returned = $this->store->returnedQuantities($property, $receipt['id']);
            $lines = [];

            foreach ($receipt['lines'] as $l) {
                $room = (int) $l['accepted_qty_milli'] - ($returned[$l['id']] ?? 0);

                if ($room > 0) {
                    $lines[] = ['id' => $l['id'], 'item_code' => $items[$l['item_id']]['code'] ?? '', 'item_name' => $items[$l['item_id']]['name'] ?? '', 'unit' => $l['unit'], 'accepted_milli' => (int) $l['accepted_qty_milli'], 'returnable_milli' => $room, 'unit_price_minor' => (int) $l['unit_price_minor']];
                }
            }

            if ($lines !== []) {
                $out[] = ['id' => $receipt['id'], 'number' => $receipt['number'], 'supplier_name' => $suppliers[$receipt['supplier_id']]['name'] ?? '', 'location_name' => $locations[$receipt['location_id']]['name'] ?? '', 'received_on' => substr((string) $receipt['received_on'], 0, 10), 'lines' => $lines];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $r
     * @param  array<string, mixed>|null  $supplier
     * @param  array<string, mixed>|null  $receipt
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    private function head(array $r, ?array $supplier, ?array $receipt, array $names): array
    {
        $utc = static fn (mixed $v): string => (new DateTimeImmutable((string) $v, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        return [
            'id' => $r['id'], 'number' => $r['number'], 'reason' => $r['reason'], 'receipt' => ['id' => $r['receipt_id'], 'number' => $receipt['number'] ?? ''], 'supplier' => ['id' => $r['supplier_id'], 'code' => $supplier['code'] ?? '', 'name' => $supplier['name'] ?? ''],
            'value_minor' => (int) $r['value_minor'], 'returned_by_name' => $names[$r['returned_by']] ?? null, 'business_date' => substr((string) $r['business_date'], 0, 10), 'created_at' => $utc($r['created_at']),
        ];
    }
}
