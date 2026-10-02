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
use App\Shared\Application\Files\DownloadFile;
use App\Shared\Application\Files\FileAccessDenied;
use App\Shared\Application\Files\FileAccessPolicy;
use App\Shared\Application\Files\FileContent;
use App\Shared\Application\Files\FilePolicy;
use App\Shared\Application\Files\FileRejected;
use App\Shared\Application\Files\FileSensitivity;
use App\Shared\Application\Files\FileUpload;
use App\Shared\Application\Files\StoredFile;
use App\Shared\Application\Files\StoredFileNotFound;
use App\Shared\Application\Files\StoreFile;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Idempotency\IdempotencyRequest;
use App\Shared\Application\Idempotency\IdempotentExecutor;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Receiving goods against a purchase order (FR-PUR-006, FR-PUR-008). The person at the dock records, per order line, what was accepted and what was
 * refused and why; a partial delivery is normal and the order stays open for the rest. The accepted quantity goes into the stock ledger at the price
 * of the order line (so stock is valued at what it cost, before tax) and the same value is recorded as a fact that the property owes the supplier, for
 * Finance to consume (the supplier ledger). More than ordered is accepted only within the over-receipt tolerance. A receipt is never changed; a mistake
 * is corrected by a return or an adjustment that points back to it. Photos are added to a line afterwards.
 */
final readonly class GoodsReceiptService
{
    public const CONDITIONS = ['good', 'minor_damage'];

    public const REJECTION_REASONS = ['damaged', 'expired', 'wrong_item', 'quality', 'short', 'other'];

    public const PHOTO_PURPOSE = 'purchasing.goods-receipt';

    public const PHOTO_MAX_BYTES = 5_242_880;

    public const MAX_PHOTOS_PER_LINE = 5;

    public function __construct(
        private PurchasingStore $store,
        private InventoryStore $inventory,
        private StockPoster $poster,
        private PurchasingAccess $access,
        private PurchasingSettingsService $settings,
        private BusinessDateProvider $businessDate,
        private DocumentNumbers $numbers,
        private StaffDirectory $staff,
        private TransactionRunner $transactions,
        private IdempotentExecutor $executor,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private StoreFile $storeFile,
        private DownloadFile $downloadFile,
        private PermissionChecker $permissions,
        private PropertyCurrencyReader $currency,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId, ?string $orderId, ?string $supplierId): array
    {
        $this->access->requireView($property, $actorId);
        $receipts = $this->store->receipts($property, $orderId === '' ? null : $orderId, $supplierId === '' ? null : $supplierId, 200);
        $orders = array_column($this->store->orders($property, null, null, 500), null, 'id');
        $suppliers = array_column($this->store->suppliers($property), null, 'id');
        $locations = array_column($this->inventory->locations($property), null, 'id');
        $names = $this->staff->namesOf($property, array_values(array_unique(array_column($receipts, 'received_by'))));

        return [
            'currency' => $this->currency->currencyOf($property),
            'receipts' => array_map(fn (array $r): array => $this->head($r, $orders[$r['order_id']] ?? null, $suppliers[$r['supplier_id']] ?? null, $locations[$r['location_id']] ?? null, $names), $receipts),
            'receivable' => $this->receivable($property),
            'locations' => array_values(array_map(static fn (array $l): array => ['id' => $l['id'], 'code' => $l['code'], 'name' => $l['name']], array_filter($locations, static fn (array $l): bool => (bool) $l['is_active']))),
            'conditions' => self::CONDITIONS, 'rejection_reasons' => self::REJECTION_REASONS, 'over_receipt_bp' => $this->settings->settings($property)['over_receipt_bp'],
            'may' => ['post' => $this->access->may($property, $actorId, PurchasingAccess::RECEIPT_POST)],
        ];
    }

    /** @return array<string, mixed> */
    public function show(PropertyId $property, string $actorId, string $id): array
    {
        $this->access->requireView($property, $actorId);
        $receipt = $this->store->receipt($property, strtolower($id)) ?? throw Refusal::notFound('Goods receipt not found.');
        $order = $this->store->order($property, $receipt['order_id']);
        $supplier = $this->store->supplier($property, $receipt['supplier_id']);
        $location = $this->inventory->location($property, $receipt['location_id']);
        $items = array_column($this->inventory->items($property), null, 'id');
        $names = $this->staff->namesOf($property, [$receipt['received_by']]);
        $mayPhoto = $this->access->may($property, $actorId, PurchasingAccess::RECEIPT_POST);

        return [
            ...$this->head($receipt, $order, $supplier, $location, $names),
            'currency' => $this->currency->currencyOf($property), 'note' => $receipt['note'], 'order_revision' => (int) $receipt['order_revision'], 'may_photo' => $mayPhoto, 'max_photos' => self::MAX_PHOTOS_PER_LINE,
            'lines' => array_map(static fn (array $l): array => [
                'id' => $l['id'], 'item_code' => $items[$l['item_id']]['code'] ?? '', 'item_name' => $items[$l['item_id']]['name'] ?? '', 'unit' => $l['unit'], 'accepted_qty_milli' => (int) $l['accepted_qty_milli'], 'rejected_qty_milli' => (int) $l['rejected_qty_milli'],
                'condition' => $l['goods_condition'], 'rejection_reason' => $l['rejection_reason'], 'note' => $l['note'], 'unit_price_minor' => (int) $l['unit_price_minor'], 'value_minor' => (int) $l['value_minor'], 'expires_on' => $l['expires_on'] === null ? null : substr((string) $l['expires_on'], 0, 10),
                'photos' => array_map(static fn (array $p): array => ['id' => $p['id']], $l['photos']),
            ], $receipt['lines']),
        ];
    }

    /**
     * @param  list<array{order_line_id: string, accepted?: string|null, rejected?: string|null, condition?: string|null, rejection_reason?: string|null, note?: string|null, expires_on?: string|null}>  $lines
     * @return array<string, mixed>
     */
    public function receive(PropertyId $property, string $actorId, string $orderId, ?string $locationId, ?string $deliveryNote, ?string $note, array $lines, ?IdempotencyKey $key = null): array
    {
        $this->access->require($property, $actorId, PurchasingAccess::RECEIPT_POST, 'This person may not receive goods.');
        $order = $this->store->order($property, strtolower($orderId)) ?? throw Refusal::notFound('Purchase order not found.');
        $location = $this->inventory->location($property, strtolower($locationId === null || $locationId === '' ? $order['location_id'] : $locationId)) ?? throw Refusal::invalid('Choose the location the goods go to.', ['location_id']);

        if (! (bool) $location['is_active']) {
            throw Refusal::stateConflict('Goods cannot be received into an inactive location.');
        }

        $deliveryNote = $deliveryNote === null || trim($deliveryNote) === '' ? null : trim($deliveryNote);
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if (($deliveryNote !== null && mb_strlen($deliveryNote) > 40) || ($note !== null && mb_strlen($note) > 200)) {
            throw Refusal::invalid('The delivery note number is at most 40 characters and the note at most 200.', ['delivery_note']);
        }

        if ($lines === [] || count($lines) > PurchaseOrderService::MAX_LINES) {
            throw Refusal::invalid('Receive at least one line.', ['lines']);
        }

        $actor = strtolower($actorId);
        $id = $this->ids->next();

        $operation = function () use ($property, $actor, $id, $order, $location, $deliveryNote, $note, $lines): void {
            $this->store->lockOrder($property, $order['id']);
            $order = $this->store->order($property, $order['id']) ?? throw Refusal::notFound('Purchase order not found.');

            if (! in_array($order['status'], ['issued', 'partially_received'], true)) {
                throw Refusal::stateConflict('Goods can be received only against an issued order that is not complete.');
            }

            $byId = array_column(array_filter($order['lines'], static fn (array $l): bool => (bool) $l['is_active']), null, 'id');
            $itemIds = array_values(array_unique(array_map(static fn (array $l): string => $l['item_id'], $byId)));
            sort($itemIds);

            foreach ($itemIds as $itemId) {
                $this->inventory->lockItem($property, $itemId);
            }

            $over = $this->settings->settings($property)['over_receipt_bp'];
            $items = array_column($this->inventory->items($property), null, 'id');
            $prepared = [];
            $seen = [];
            $total = 0;

            foreach ($lines as $line) {
                $lineId = strtolower((string) ($line['order_line_id'] ?? ''));
                $ol = $byId[$lineId] ?? throw Refusal::invalid('A line does not belong to this order.', ['lines']);

                if (isset($seen[$lineId])) {
                    throw Refusal::invalid('An order line appears once in a receipt.', ['lines']);
                }

                $seen[$lineId] = true;
                $accepted = $this->quantity($line['accepted'] ?? null);
                $rejected = $this->quantity($line['rejected'] ?? null);

                if ($accepted === 0 && $rejected === 0) {
                    continue;
                }

                $condition = (string) ($line['condition'] ?? 'good') === '' ? 'good' : (string) ($line['condition'] ?? 'good');
                $reason = isset($line['rejection_reason']) && $line['rejection_reason'] !== '' ? (string) $line['rejection_reason'] : null;

                if (! in_array($condition, self::CONDITIONS, true)) {
                    throw Refusal::invalid('Choose the condition of the goods.', ['lines']);
                }

                if ($rejected > 0 && ($reason === null || ! in_array($reason, self::REJECTION_REASONS, true))) {
                    throw Refusal::invalid('Say why the goods were refused.', ['lines']);
                }

                $lineNote = isset($line['note']) && trim((string) $line['note']) !== '' ? trim((string) $line['note']) : null;

                if (($lineNote !== null && mb_strlen($lineNote) > 200) || ($reason === 'other' && $lineNote === null)) {
                    throw Refusal::invalid($reason === 'other' ? 'Say what was wrong when the reason is "other".' : 'A note is at most 200 characters.', ['lines']);
                }

                $expires = isset($line['expires_on']) && $line['expires_on'] !== '' ? (string) $line['expires_on'] : null;

                if ($expires !== null && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) !== 1 || ! checkdate((int) substr($expires, 5, 2), (int) substr($expires, 8, 2), (int) substr($expires, 0, 4)))) {
                    throw Refusal::invalid('Give the expiry date as year-month-day.', ['lines']);
                }

                $limit = StockValue::mulDiv((int) $ol['qty_milli'], 10000 + $over, 10000);
                $item = $items[$ol['item_id']] ?? throw Refusal::notFound('Item not found.');

                if ((int) $ol['received_qty_milli'] + $accepted > $limit) {
                    throw Refusal::stateConflict('More of '.$item['code'].' than ordered (and the tolerance) cannot be accepted. Refuse the excess instead.');
                }

                $value = StockValue::ofQuantity($accepted, (int) $ol['unit_price_minor']);
                $total += $value;
                $prepared[] = ['id' => $this->ids->next(), 'order_line_id' => $lineId, 'item_id' => $ol['item_id'], 'unit' => $ol['unit'], 'accepted_qty_milli' => $accepted, 'rejected_qty_milli' => $rejected, 'goods_condition' => $condition, 'rejection_reason' => $rejected > 0 ? $reason : null,
                    'note' => $lineNote, 'unit_price_minor' => (int) $ol['unit_price_minor'], 'value_minor' => $value, 'movement_id' => null, 'expires_on' => $expires, '_ol' => $ol, '_item' => $item];
            }

            if ($prepared === []) {
                throw Refusal::invalid('Enter an accepted or a refused quantity on at least one line.', ['lines']);
            }

            $number = $this->numbers->next($property, 'GR');
            $date = $this->businessDate->current($property)->toString();
            $at = $this->clock->nowUtc();

            foreach ($prepared as &$p) {
                if ($p['accepted_qty_milli'] > 0) {
                    $moved = $this->poster->post($property, $actor, $p['_item'], $location, 'receipt', $p['unit'], $p['accepted_qty_milli'], null, $number, null, 'goods_receipt', $p['id'], null, null, false, null, $p['unit_price_minor']);
                    $p['movement_id'] = $moved['movement']['id'];
                }
            }

            unset($p);

            $header = ['id' => $id, 'number' => $number, 'order_id' => $order['id'], 'supplier_id' => $order['supplier_id'], 'location_id' => $location['id'], 'order_revision' => (int) $order['revision'], 'delivery_note' => $deliveryNote, 'note' => $note,
                'received_on' => $date, 'value_minor' => $total, 'received_by' => $actor];

            if (! $this->store->addReceipt($property, $header, array_map(static fn (array $p): array => array_diff_key($p, ['_ol' => 0, '_item' => 0]), $prepared), $at)) {
                throw Refusal::stateConflict('A receipt with this number already exists. Try again.');
            }

            foreach ($prepared as $p) {
                $this->store->updateOrderLine($p['order_line_id'], ['received_qty_milli' => (int) $p['_ol']['received_qty_milli'] + $p['accepted_qty_milli'], 'rejected_qty_milli' => (int) $p['_ol']['rejected_qty_milli'] + $p['rejected_qty_milli']]);
            }

            $fresh = $this->store->order($property, $order['id']);
            $complete = array_reduce(array_filter($fresh['lines'], static fn (array $l): bool => (bool) $l['is_active']), static fn (bool $carry, array $l): bool => $carry && (int) $l['received_qty_milli'] >= (int) $l['qty_milli'], true);
            $status = $complete ? 'received' : 'partially_received';

            if ($status !== $fresh['status'] && ! $this->store->updateOrder($property, $order['id'], (int) $fresh['lock_version'], ['status' => $status], $at)) {
                throw Refusal::stateConflict('This order changed meanwhile. Try again.');
            }

            if ($total > 0) {
                $this->store->addLedgerEntry($property, ['id' => $this->ids->next(), 'supplier_id' => $order['supplier_id'], 'kind' => 'goods_received', 'amount_minor' => $total, 'ref_type' => 'goods_receipt', 'ref_id' => $id, 'ref_number' => $number, 'business_date' => $date, 'created_by' => $actor], $at);
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'goods_receipt.posted', 'goods_receipt', $id, null, [
                'number' => $number, 'order' => $order['number'], 'location' => $location['code'], 'value_minor' => $total, 'order_status' => $status,
                'lines' => array_map(static fn (array $p): array => ['item' => $p['_item']['code'], 'accepted_qty_milli' => $p['accepted_qty_milli'], 'rejected_qty_milli' => $p['rejected_qty_milli'], 'rejection_reason' => $p['rejection_reason']], $prepared),
            ]));
            $this->outbox->publish(new OutboxEvent($property, 'purchasing.goods.received', $id, 1, [
                'receipt_id' => $id, 'number' => $number, 'order_id' => $order['id'], 'supplier_id' => $order['supplier_id'], 'value_minor' => $total, 'currency' => $this->currency->currencyOf($property), 'payment_terms_days' => (int) $order['payment_terms_days'], 'actor_id' => $actor,
            ]));
        };

        if ($key === null) {
            $this->transactions->run($operation);
        } else {
            $once = $this->executor->execute(
                new IdempotencyRequest($property, $key, 'purchasing.goods.receive', ['order' => $order['id'], 'location' => $location['id'], 'delivery_note' => $deliveryNote, 'lines' => $lines], $actor),
                function () use ($operation, $id): array {
                    $operation();

                    return ['id' => $id];
                },
            );
            $id = (string) $once->payload['id'];
        }

        return $this->show($property, $actorId, $id);
    }

    /** Adds a photo of the delivery to a line of a posted receipt. @return array<string, mixed> */
    public function addPhoto(PropertyId $property, string $actorId, string $receiptId, string $lineId, string $contents, ?string $name): array
    {
        $this->access->require($property, $actorId, PurchasingAccess::RECEIPT_POST, 'This person may not add photos to a receipt.');
        $receipt = $this->store->receipt($property, strtolower($receiptId)) ?? throw Refusal::notFound('Goods receipt not found.');
        $line = null;

        foreach ($receipt['lines'] as $l) {
            if ($l['id'] === strtolower($lineId)) {
                $line = $l;
            }
        }

        $line ?? throw Refusal::notFound('Receipt line not found.');

        if (count($line['photos']) >= self::MAX_PHOTOS_PER_LINE) {
            throw Refusal::stateConflict('A line keeps at most '.self::MAX_PHOTOS_PER_LINE.' photos.');
        }

        $actor = strtolower($actorId);

        try {
            $file = $this->storeFile->execute(new FileUpload($property, $actor, self::PHOTO_PURPOSE, 'goods-receipt', $receipt['id'], $contents, new FilePolicy(['image/jpeg', 'image/png'], self::PHOTO_MAX_BYTES, FileSensitivity::Standard, false), $name));
        } catch (FileRejected $e) {
            throw Refusal::invalid($e->getMessage(), ['photo']);
        }

        $this->transactions->run(function () use ($property, $actor, $receipt, $line, $file): void {
            $this->store->addReceiptPhoto($property, ['id' => $this->ids->next(), 'receipt_line_id' => $line['id'], 'file_id' => $file->id, 'created_by' => $actor], $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'goods_receipt.photo_added', 'goods_receipt', $receipt['id'], null, ['number' => $receipt['number'], 'line' => $line['id']]));
        });

        return $this->show($property, $actorId, $receipt['id']);
    }

    public function photo(PropertyId $property, string $actorId, string $receiptId, string $photoId): FileContent
    {
        $this->access->requireView($property, $actorId);
        $receipt = $this->store->receipt($property, strtolower($receiptId)) ?? throw Refusal::notFound('Goods receipt not found.');
        $fileId = null;

        foreach ($receipt['lines'] as $l) {
            foreach ($l['photos'] as $p) {
                if ($p['id'] === strtolower($photoId)) {
                    $fileId = $p['file_id'];
                }
            }
        }

        $fileId ?? throw Refusal::notFound('Photo not found.');
        $policy = new class($this->access, $property) implements FileAccessPolicy
        {
            public function __construct(private PurchasingAccess $access, private PropertyId $property) {}

            public function allows(string $actorId, StoredFile $file): bool
            {
                return $this->access->mayView($this->property, $actorId);
            }
        };

        try {
            return $this->downloadFile->execute($property, $fileId, strtolower($actorId), $policy);
        } catch (StoredFileNotFound) {
            throw Refusal::notFound('The photo is no longer kept.');
        } catch (FileAccessDenied) {
            throw Refusal::forbidden('This person may not see purchasing documents.');
        }
    }

    /** @return list<array<string, mixed>> issued and partly received orders with what is still to come on each line */
    private function receivable(PropertyId $property): array
    {
        $items = array_column($this->inventory->items($property), null, 'id');
        $suppliers = array_column($this->store->suppliers($property), null, 'id');
        $out = [];

        foreach (['issued', 'partially_received'] as $status) {
            foreach ($this->store->orders($property, $status, null, 200) as $head) {
                $order = $this->store->order($property, $head['id']);
                $out[] = [
                    'id' => $order['id'], 'number' => $order['number'], 'revision' => (int) $order['revision'], 'supplier_name' => $suppliers[$order['supplier_id']]['name'] ?? '', 'location_id' => $order['location_id'],
                    'expected_date' => $order['expected_date'] === null ? null : substr((string) $order['expected_date'], 0, 10),
                    'lines' => array_values(array_map(static fn (array $l): array => [
                        'id' => $l['id'], 'item_code' => $items[$l['item_id']]['code'] ?? '', 'item_name' => $items[$l['item_id']]['name'] ?? '', 'unit' => $l['unit'], 'qty_milli' => (int) $l['qty_milli'], 'received_qty_milli' => (int) $l['received_qty_milli'],
                        'rejected_qty_milli' => (int) $l['rejected_qty_milli'], 'remaining_milli' => max(0, (int) $l['qty_milli'] - (int) $l['received_qty_milli']), 'unit_price_minor' => (int) $l['unit_price_minor'],
                    ], array_filter($order['lines'], static fn (array $l): bool => (bool) $l['is_active']))),
                ];
            }
        }

        return $out;
    }

    private function quantity(mixed $value): int
    {
        if ($value === null || trim((string) $value) === '') {
            return 0;
        }

        $qty = StockQuantity::parse((string) $value);

        if ($qty === null || $qty > StockQuantity::MAX_MILLI) {
            throw Refusal::invalid('Give every quantity as a number with at most three decimals.', ['lines']);
        }

        return $qty;
    }

    /**
     * @param  array<string, mixed>  $r
     * @param  array<string, mixed>|null  $order
     * @param  array<string, mixed>|null  $supplier
     * @param  array<string, mixed>|null  $location
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    private function head(array $r, ?array $order, ?array $supplier, ?array $location, array $names): array
    {
        $utc = static fn (mixed $v): string => (new DateTimeImmutable((string) $v, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        return [
            'id' => $r['id'], 'number' => $r['number'], 'order' => ['id' => $r['order_id'], 'number' => $order['number'] ?? '', 'status' => $order['status'] ?? ''], 'supplier' => ['id' => $r['supplier_id'], 'code' => $supplier['code'] ?? '', 'name' => $supplier['name'] ?? ''],
            'location' => ['id' => $r['location_id'], 'code' => $location['code'] ?? '', 'name' => $location['name'] ?? ''], 'delivery_note' => $r['delivery_note'], 'received_on' => substr((string) $r['received_on'], 0, 10), 'value_minor' => (int) $r['value_minor'],
            'received_by_name' => $names[$r['received_by']] ?? null, 'created_at' => $utc($r['created_at']),
        ];
    }
}
