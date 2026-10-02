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
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Supplier invoices and the three-way match (FR-PUR-012, FR-PUR-007). An invoice is entered against one purchase order with the supplier's own number,
 * date, tax and total. The same invoice cannot be entered twice: the number is compared without spacing, punctuation or case, per supplier. Each line is
 * matched to the order (the price) and to the goods received and not yet invoiced (the quantity); the tax is matched to the tax rate of the order, and tax
 * with no tax invoice number is flagged. A clean invoice is recognised at once: the goods-received accrual it replaces comes off what is owed and the
 * invoice amount goes on. One with differences waits; someone other than the person who entered it approves it with a note (and it is recognised) or
 * rejects it (nothing is recognised). The tolerances come from the purchasing settings.
 */
final readonly class SupplierInvoiceService
{
    public const DOCUMENT_PURPOSE = 'purchasing.supplier-invoice';

    public const MAX_DOCUMENTS = 5;

    public function __construct(
        private PurchasingStore $store,
        private InventoryStore $inventory,
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
        private PropertyCurrencyReader $currency,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId, ?string $status, ?string $supplierId): array
    {
        $this->access->requireView($property, $actorId);

        if ($status !== null && $status !== '' && ! in_array($status, ['matched', 'variance', 'approved', 'rejected'], true)) {
            throw Refusal::invalid('Choose a status from the list.', ['status']);
        }

        $rows = $this->store->invoices($property, $status === '' ? null : $status, $supplierId === '' ? null : $supplierId, 200);
        $orders = array_column($this->store->orders($property, null, null, 500), null, 'id');
        $suppliers = array_column($this->store->suppliers($property), null, 'id');
        $names = $this->staff->namesOf($property, array_values(array_unique(array_column($rows, 'entered_by'))));

        return [
            'currency' => $this->currency->currencyOf($property),
            'invoices' => array_map(fn (array $i): array => $this->head($i, $orders[$i['order_id']] ?? null, $suppliers[$i['supplier_id']] ?? null, $names), $rows),
            'invoiceable' => $this->invoiceable($property),
            'tolerances' => ['price_bp' => $this->settings->settings($property)['invoice_price_tolerance_bp'], 'qty_bp' => $this->settings->settings($property)['invoice_qty_tolerance_bp']],
            'may' => ['record' => $this->access->may($property, $actorId, PurchasingAccess::INVOICE_MANAGE), 'resolve' => $this->access->may($property, $actorId, PurchasingAccess::INVOICE_RESOLVE)],
        ];
    }

    /** @return array<string, mixed> */
    public function show(PropertyId $property, string $actorId, string $id): array
    {
        $this->access->requireView($property, $actorId);
        $invoice = $this->store->invoice($property, strtolower($id)) ?? throw Refusal::notFound('Supplier invoice not found.');
        $order = $this->store->order($property, $invoice['order_id']);
        $supplier = $this->store->supplier($property, $invoice['supplier_id']);
        $items = array_column($this->inventory->items($property), null, 'id');
        $names = $this->staff->namesOf($property, array_values(array_filter([$invoice['entered_by'], $invoice['decided_by']])));
        $utc = static fn (mixed $v): ?string => $v === null ? null : (new DateTimeImmutable((string) $v, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $actor = strtolower($actorId);

        return [
            ...$this->head($invoice, $order, $supplier, $names),
            'currency' => $this->currency->currencyOf($property), 'tax_number' => $invoice['tax_number'], 'subtotal_minor' => (int) $invoice['subtotal_minor'], 'tax_minor' => (int) $invoice['tax_minor'], 'accrual_minor' => (int) $invoice['accrual_minor'],
            'note' => $invoice['note'], 'decision_note' => $invoice['decision_note'], 'decided_by_name' => $invoice['decided_by'] === null ? null : ($names[$invoice['decided_by']] ?? null), 'decided_at' => $utc($invoice['decided_at']), 'variances' => $invoice['variances'],
            'lines' => array_map(static fn (array $l): array => [
                'id' => $l['id'], 'item_code' => $items[$l['item_id']]['code'] ?? '', 'item_name' => $items[$l['item_id']]['name'] ?? '', 'unit' => $l['unit'], 'qty_milli' => (int) $l['qty_milli'], 'unit_price_minor' => (int) $l['unit_price_minor'],
                'line_total_minor' => (int) $l['line_total_minor'], 'order_price_minor' => (int) $l['order_price_minor'], 'open_received_milli' => (int) $l['open_received_milli'],
            ], $invoice['lines']),
            'documents' => array_map(static fn (array $d): array => ['id' => $d['id'], 'name' => $d['display_name']], $invoice['documents']), 'max_documents' => self::MAX_DOCUMENTS,
            'may_resolve' => $invoice['status'] === 'variance' && $this->access->may($property, $actorId, PurchasingAccess::INVOICE_RESOLVE) && $invoice['entered_by'] !== $actor,
            'resolve_blocked_self' => $invoice['status'] === 'variance' && $this->access->may($property, $actorId, PurchasingAccess::INVOICE_RESOLVE) && $invoice['entered_by'] === $actor,
            'may_document' => $this->access->may($property, $actorId, PurchasingAccess::INVOICE_MANAGE),
        ];
    }

    /**
     * @param  list<array{order_line_id: string, quantity: string, unit_price_minor: int}>  $lines
     * @return array<string, mixed>
     */
    public function record(PropertyId $property, string $actorId, string $orderId, string $invoiceNumber, string $invoiceDate, ?string $taxNumber, int $taxMinor, int $totalMinor, ?string $note, array $lines, ?IdempotencyKey $key = null): array
    {
        $this->access->require($property, $actorId, PurchasingAccess::INVOICE_MANAGE, 'This person may not enter supplier invoices.');
        $order = $this->store->order($property, strtolower($orderId)) ?? throw Refusal::invalid('Choose the purchase order the invoice is for.', ['order_id']);
        $invoiceNumber = trim($invoiceNumber);
        $invoiceKey = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $invoiceNumber));

        if ($invoiceKey === '' || mb_strlen($invoiceNumber) > 40) {
            throw Refusal::invalid('Give the supplier\'s invoice number, at most 40 characters.', ['invoice_number']);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoiceDate) !== 1 || ! checkdate((int) substr($invoiceDate, 5, 2), (int) substr($invoiceDate, 8, 2), (int) substr($invoiceDate, 0, 4))) {
            throw Refusal::invalid('Give the invoice date as year-month-day.', ['invoice_date']);
        }

        if ($invoiceDate > $this->businessDate->current($property)->toString()) {
            throw Refusal::invalid('The invoice date cannot be in the future.', ['invoice_date']);
        }

        $taxNumber = $taxNumber === null || trim($taxNumber) === '' ? null : (string) preg_replace('/[^0-9]/', '', $taxNumber);

        if ($taxNumber !== null && strlen($taxNumber) !== 16) {
            throw Refusal::invalid('The tax invoice number (nomor faktur pajak) has 16 digits.', ['tax_number']);
        }

        $note = $note === null || trim($note) === '' ? null : trim($note);

        if ($note !== null && mb_strlen($note) > 200) {
            throw Refusal::invalid('The note is at most 200 characters.', ['note']);
        }

        if ($taxMinor < 0 || $totalMinor < 0 || $lines === [] || count($lines) > PurchaseOrderService::MAX_LINES) {
            throw Refusal::invalid('Give the lines, the tax and the total of the invoice.', ['lines']);
        }

        $actor = strtolower($actorId);
        $id = $this->ids->next();

        $operation = function () use ($property, $actor, $id, $order, $invoiceNumber, $invoiceKey, $invoiceDate, $taxNumber, $taxMinor, $totalMinor, $note, $lines): void {
            $this->store->lockOrder($property, $order['id']);
            $order = $this->store->order($property, $order['id']) ?? throw Refusal::notFound('Purchase order not found.');

            if (in_array($order['status'], ['draft', 'pending_approval', 'cancelled'], true)) {
                throw Refusal::stateConflict('An invoice can be entered only against an order that was issued.');
            }

            $tolerance = $this->settings->settings($property);
            $byId = array_column($order['lines'], null, 'id');
            $invoiced = $this->store->invoicedQuantities($property, $order['id']);
            $items = array_column($this->inventory->items($property), null, 'id');
            $prepared = [];
            $variances = [];
            $seen = [];
            $subtotal = 0;
            $accrual = 0;

            foreach ($lines as $line) {
                $lineId = strtolower((string) ($line['order_line_id'] ?? ''));
                $ol = $byId[$lineId] ?? throw Refusal::invalid('A line does not belong to this order.', ['lines']);

                if (isset($seen[$lineId])) {
                    throw Refusal::invalid('An order line appears once in an invoice.', ['lines']);
                }

                $seen[$lineId] = true;
                $qty = StockQuantity::parse((string) ($line['quantity'] ?? ''));
                $price = (int) ($line['unit_price_minor'] ?? -1);

                if ($qty === null || $qty < 1 || $qty > StockQuantity::MAX_MILLI || $price < 0 || $price > StockValue::MAX_UNIT_COST_MINOR) {
                    throw Refusal::invalid('Give every line a quantity above zero and a price.', ['lines']);
                }

                $code = $items[$ol['item_id']]['code'] ?? '';
                $open = max(0, (int) $ol['received_qty_milli'] - (int) $ol['returned_qty_milli'] - ($invoiced[$lineId] ?? 0));
                $qtyLimit = $open + StockValue::mulDiv($open, $tolerance['invoice_qty_tolerance_bp'], 10000);

                if ($qty > $qtyLimit) {
                    $variances[] = ['type' => $open === 0 ? 'not_received' : 'quantity', 'item' => $code, 'expected_milli' => $open, 'actual_milli' => $qty];
                }

                $orderPrice = (int) $ol['unit_price_minor'];

                if ($orderPrice === 0 ? $price > 0 : intdiv(abs($price - $orderPrice) * 10000, $orderPrice) > $tolerance['invoice_price_tolerance_bp']) {
                    $variances[] = ['type' => 'price', 'item' => $code, 'expected_minor' => $orderPrice, 'actual_minor' => $price];
                }

                $total = StockValue::ofQuantity($qty, $price);
                $subtotal += $total;
                $accrual += StockValue::ofQuantity(min($qty, $open), $orderPrice);
                $prepared[] = ['id' => $this->ids->next(), 'order_line_id' => $lineId, 'item_id' => $ol['item_id'], 'unit' => $ol['unit'], 'qty_milli' => $qty, 'unit_price_minor' => $price, 'line_total_minor' => $total, 'order_price_minor' => $orderPrice, 'open_received_milli' => $open];
            }

            if ($subtotal + $taxMinor !== $totalMinor) {
                throw Refusal::invalid('The lines and the tax do not add up to the total of the invoice.', ['total_minor']);
            }

            $expectedTax = StockValue::mulDiv($subtotal, (int) $order['tax_bp'], 10000);
            $taxSlack = max(100, StockValue::mulDiv($subtotal, $tolerance['invoice_price_tolerance_bp'], 10000));

            if (abs($taxMinor - $expectedTax) > $taxSlack) {
                $variances[] = ['type' => 'tax', 'expected_minor' => $expectedTax, 'actual_minor' => $taxMinor];
            }

            if ($taxMinor > 0 && $taxNumber === null) {
                $variances[] = ['type' => 'tax_document'];
            }

            $number = $this->numbers->next($property, 'SI');
            $date = $this->businessDate->current($property)->toString();
            $due = (new DateTimeImmutable($invoiceDate))->modify('+'.(int) $order['payment_terms_days'].' days')->format('Y-m-d');
            $status = $variances === [] ? 'matched' : 'variance';
            $at = $this->clock->nowUtc();
            $row = [
                'id' => $id, 'number' => $number, 'supplier_id' => $order['supplier_id'], 'order_id' => $order['id'], 'invoice_number' => $invoiceNumber, 'invoice_key' => mb_substr($invoiceKey, 0, 40), 'invoice_date' => $invoiceDate, 'due_date' => $due, 'tax_number' => $taxNumber,
                'subtotal_minor' => $subtotal, 'tax_minor' => $taxMinor, 'total_minor' => $totalMinor, 'accrual_minor' => $accrual, 'status' => $status, 'variances' => $variances, 'entered_by' => $actor, 'note' => $note, 'business_date' => $date,
            ];

            if (! $this->store->addInvoice($property, $row, $prepared, $at)) {
                throw Refusal::stateConflict('This supplier already has an invoice with the number '.$invoiceNumber.'.');
            }

            if ($status === 'matched') {
                $this->recognise($property, $actor, $row, $at);
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'supplier_invoice.entered', 'supplier_invoice', $id, null, ['number' => $number, 'invoice_number' => $invoiceNumber, 'order' => $order['number'], 'total_minor' => $totalMinor, 'status' => $status, 'variances' => count($variances)]));
        };

        if ($key === null) {
            $this->transactions->run($operation);
        } else {
            $once = $this->executor->execute(
                new IdempotencyRequest($property, $key, 'purchasing.invoice.record', ['order' => $order['id'], 'invoice' => $invoiceKey, 'date' => $invoiceDate, 'tax' => $taxMinor, 'total' => $totalMinor, 'lines' => $lines], $actor),
                function () use ($operation, $id): array {
                    $operation();

                    return ['id' => $id];
                },
            );
            $id = (string) $once->payload['id'];
        }

        return $this->show($property, $actorId, $id);
    }

    /** Accepts an invoice with differences, with a note; it is recognised. @return array<string, mixed> */
    public function approve(PropertyId $property, string $actorId, string $id, string $note, int $lock): array
    {
        return $this->resolve($property, $actorId, $id, 'approved', $note, $lock);
    }

    /** Refuses an invoice with differences, with a note; nothing is recognised and its quantities are free to be invoiced again. @return array<string, mixed> */
    public function reject(PropertyId $property, string $actorId, string $id, string $note, int $lock): array
    {
        return $this->resolve($property, $actorId, $id, 'rejected', $note, $lock);
    }

    /** Adds the supporting document (the invoice itself, a tax invoice) to an entered invoice. @return array<string, mixed> */
    public function addDocument(PropertyId $property, string $actorId, string $invoiceId, string $contents, ?string $name): array
    {
        $this->access->require($property, $actorId, PurchasingAccess::INVOICE_MANAGE, 'This person may not add documents to supplier invoices.');
        $invoice = $this->store->invoice($property, strtolower($invoiceId)) ?? throw Refusal::notFound('Supplier invoice not found.');

        if (count($invoice['documents']) >= self::MAX_DOCUMENTS) {
            throw Refusal::stateConflict('An invoice keeps at most '.self::MAX_DOCUMENTS.' documents.');
        }

        $actor = strtolower($actorId);

        try {
            $file = $this->storeFile->execute(new FileUpload($property, $actor, self::DOCUMENT_PURPOSE, 'supplier-invoice', $invoice['id'], $contents, new FilePolicy(['application/pdf', 'image/jpeg', 'image/png'], 5_242_880, FileSensitivity::Sensitive, false), $name));
        } catch (FileRejected $e) {
            throw Refusal::invalid($e->getMessage(), ['document']);
        }

        $this->transactions->run(function () use ($property, $actor, $invoice, $file, $name): void {
            $this->store->addInvoiceDocument($property, ['id' => $this->ids->next(), 'invoice_id' => $invoice['id'], 'file_id' => $file->id, 'display_name' => $name === null ? null : mb_substr($name, 0, 120), 'created_by' => $actor], $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'supplier_invoice.document_added', 'supplier_invoice', $invoice['id'], null, ['number' => $invoice['number']]));
        });

        return $this->show($property, $actorId, $invoice['id']);
    }

    public function document(PropertyId $property, string $actorId, string $invoiceId, string $documentId): FileContent
    {
        $this->access->requireView($property, $actorId);
        $invoice = $this->store->invoice($property, strtolower($invoiceId)) ?? throw Refusal::notFound('Supplier invoice not found.');
        $fileId = null;

        foreach ($invoice['documents'] as $d) {
            if ($d['id'] === strtolower($documentId)) {
                $fileId = $d['file_id'];
            }
        }

        $fileId ?? throw Refusal::notFound('Document not found.');
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
            throw Refusal::notFound('The document is no longer kept.');
        } catch (FileAccessDenied) {
            throw Refusal::forbidden('This person may not see purchasing documents.');
        }
    }

    /** @return array<string, mixed> */
    private function resolve(PropertyId $property, string $actorId, string $id, string $to, string $note, int $lock): array
    {
        $this->access->require($property, $actorId, PurchasingAccess::INVOICE_RESOLVE, 'This person may not decide on invoices with differences.');
        $invoice = $this->store->invoice($property, strtolower($id)) ?? throw Refusal::notFound('Supplier invoice not found.');
        $actor = strtolower($actorId);

        if ($invoice['status'] !== 'variance') {
            throw Refusal::stateConflict('Only an invoice with differences waits for a decision.');
        }

        if ($invoice['entered_by'] === $actor) {
            throw Refusal::forbidden('The person who entered an invoice cannot decide on it.');
        }

        if (trim($note) === '' || mb_strlen($note) > 200) {
            throw Refusal::invalid('A note of at most 200 characters is required.', ['note']);
        }

        $this->transactions->run(function () use ($property, $actor, $invoice, $to, $note, $lock): void {
            $at = $this->clock->nowUtc();

            if (! $this->store->updateInvoice($property, $invoice['id'], $lock, ['status' => $to, 'decided_by' => $actor, 'decided_at' => $at, 'decision_note' => trim($note)], $at)) {
                throw Refusal::stateConflict('This invoice changed after you opened it.');
            }

            if ($to === 'approved') {
                $this->recognise($property, $actor, $invoice, $at);
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'supplier_invoice.'.$to, 'supplier_invoice', $invoice['id'], ['status' => 'variance'], ['status' => $to, 'number' => $invoice['number']], trim($note)));
        });

        return $this->show($property, $actorId, $invoice['id']);
    }

    /**
     * An invoice becomes a payable: the accrual that goods received had made for the quantity it covers comes off, and the invoice goes on.
     *
     * @param  array<string, mixed>  $invoice
     */
    private function recognise(PropertyId $property, string $actor, array $invoice, DateTimeImmutable $at): void
    {
        $date = $this->businessDate->current($property)->toString();

        if ((int) $invoice['accrual_minor'] > 0) {
            $this->store->addLedgerEntry($property, ['id' => $this->ids->next(), 'supplier_id' => $invoice['supplier_id'], 'kind' => 'invoice_accrual', 'amount_minor' => -(int) $invoice['accrual_minor'], 'ref_type' => 'supplier_invoice', 'ref_id' => $invoice['id'], 'ref_number' => $invoice['number'], 'business_date' => $date, 'created_by' => $actor], $at);
        }

        $this->store->addLedgerEntry($property, ['id' => $this->ids->next(), 'supplier_id' => $invoice['supplier_id'], 'kind' => 'invoice', 'amount_minor' => (int) $invoice['total_minor'], 'ref_type' => 'supplier_invoice', 'ref_id' => $invoice['id'], 'ref_number' => $invoice['number'], 'business_date' => $date, 'created_by' => $actor], $at);
        $this->outbox->publish(new OutboxEvent($property, 'purchasing.invoice.recognised', $invoice['id'], 1, [
            'invoice_id' => $invoice['id'], 'number' => $invoice['number'], 'supplier_id' => $invoice['supplier_id'], 'order_id' => $invoice['order_id'], 'total_minor' => (int) $invoice['total_minor'], 'tax_minor' => (int) $invoice['tax_minor'],
            'due_date' => substr((string) $invoice['due_date'], 0, 10), 'currency' => $this->currency->currencyOf($property), 'actor_id' => $actor,
        ]));
    }

    /** @return list<array<string, mixed>> orders that had goods received, with what is received and not yet invoiced on each line */
    private function invoiceable(PropertyId $property): array
    {
        $items = array_column($this->inventory->items($property), null, 'id');
        $suppliers = array_column($this->store->suppliers($property), null, 'id');
        $out = [];

        foreach (['partially_received', 'received', 'closed', 'issued'] as $status) {
            foreach ($this->store->orders($property, $status, null, 200) as $head) {
                $order = $this->store->order($property, $head['id']);
                $invoiced = $this->store->invoicedQuantities($property, $order['id']);
                $lines = [];

                foreach ($order['lines'] as $l) {
                    $open = max(0, (int) $l['received_qty_milli'] - (int) $l['returned_qty_milli'] - ($invoiced[$l['id']] ?? 0));

                    if ((bool) $l['is_active'] && $open > 0) {
                        $lines[] = ['id' => $l['id'], 'item_code' => $items[$l['item_id']]['code'] ?? '', 'item_name' => $items[$l['item_id']]['name'] ?? '', 'unit' => $l['unit'], 'open_milli' => $open, 'unit_price_minor' => (int) $l['unit_price_minor']];
                    }
                }

                if ($lines !== []) {
                    $out[] = ['id' => $order['id'], 'number' => $order['number'], 'supplier_name' => $suppliers[$order['supplier_id']]['name'] ?? '', 'tax_bp' => (int) $order['tax_bp'], 'payment_terms_days' => (int) $order['payment_terms_days'], 'lines' => $lines];
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $i
     * @param  array<string, mixed>|null  $order
     * @param  array<string, mixed>|null  $supplier
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    private function head(array $i, ?array $order, ?array $supplier, array $names): array
    {
        $variances = is_array($i['variances'] ?? null) ? $i['variances'] : (isset($i['variances']) && $i['variances'] !== null ? json_decode((string) $i['variances'], true) : []);

        return [
            'id' => $i['id'], 'number' => $i['number'], 'invoice_number' => $i['invoice_number'], 'status' => $i['status'], 'invoice_date' => substr((string) $i['invoice_date'], 0, 10), 'due_date' => substr((string) $i['due_date'], 0, 10),
            'order' => ['id' => $i['order_id'], 'number' => $order['number'] ?? ''], 'supplier' => ['id' => $i['supplier_id'], 'code' => $supplier['code'] ?? '', 'name' => $supplier['name'] ?? ''], 'total_minor' => (int) $i['total_minor'],
            'variance_count' => count($variances ?? []), 'entered_by_name' => $names[$i['entered_by']] ?? null, 'lock_version' => (int) $i['lock_version'],
        ];
    }
}
