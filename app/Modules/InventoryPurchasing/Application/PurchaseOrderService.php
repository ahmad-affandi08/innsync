<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Modules\InventoryPurchasing\Domain\StockQuantity;
use App\Modules\InventoryPurchasing\Domain\StockValue;
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
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Purchase orders (FR-PUR-003, FR-PUR-011). Purchasing builds an order to one supplier from approved requests, or types the lines itself. Prices come
 * from the supplier's price list unless Purchasing gives one. The order is approved by the chain the owner configured for its value (subject
 * `inventory.purchase-order`) and then issued to the supplier. After it is approved, any change is a numbered revision with its reason and a snapshot of
 * what was agreed. A change that moves the value or a quantity beyond the tolerance, or changes the supplier or the lines, goes through approval again
 * and takes effect only when it is approved; a smaller change takes effect at once. Prices are before tax; the tax rate is kept on the order.
 */
final readonly class PurchaseOrderService
{
    public const SUBJECT = 'inventory.purchase-order';

    public const MAX_LINES = 60;

    public function __construct(
        private PurchasingStore $store,
        private InventoryStore $inventory,
        private PurchasingAccess $access,
        private PurchasingSettingsService $settings,
        private ApprovalGate $approvals,
        private BusinessDateProvider $businessDate,
        private DocumentNumbers $numbers,
        private StaffDirectory $staff,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyCurrencyReader $currency,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId, ?string $status, ?string $supplierId): array
    {
        $this->access->requireView($property, $actorId);

        if ($status !== null && $status !== '' && ! in_array($status, ['draft', 'pending_approval', 'approved', 'issued', 'partially_received', 'received', 'closed', 'cancelled'], true)) {
            throw Refusal::invalid('Choose a status from the list.', ['status']);
        }

        $orders = $this->store->orders($property, $status === '' ? null : $status, $supplierId === '' ? null : $supplierId, 200);
        $suppliers = array_column($this->store->suppliers($property), null, 'id');
        $locations = array_column($this->inventory->locations($property), null, 'id');

        return [
            'currency' => $this->currency->currencyOf($property),
            'orders' => array_map(fn (array $o): array => $this->head($o, $suppliers[$o['supplier_id']] ?? null, $locations[$o['location_id']] ?? null), $orders),
            'suppliers' => array_values(array_map(static fn (array $s): array => ['id' => $s['id'], 'code' => $s['code'], 'name' => $s['name'], 'payment_terms_days' => (int) $s['payment_terms_days']], array_filter($suppliers, static fn (array $s): bool => (bool) $s['is_active']))),
            'locations' => array_values(array_map(static fn (array $l): array => ['id' => $l['id'], 'code' => $l['code'], 'name' => $l['name']], array_filter($locations, static fn (array $l): bool => (bool) $l['is_active']))),
            'departments' => InventoryCatalogService::DEPARTMENTS, 'items' => $this->itemChoices($property), 'request_lines' => $this->openRequestLines($property),
            'tax_bp' => $this->settings->settings($property)['budget_policy'] === null ? 0 : $this->settings->settings($property)['tax_bp'],
            'may' => ['manage' => $this->access->may($property, $actorId, PurchasingAccess::ORDER_MANAGE)],
        ];
    }

    /** @return array<string, mixed> */
    public function show(PropertyId $property, string $actorId, string $id): array
    {
        $this->access->requireView($property, $actorId);
        $order = $this->store->order($property, strtolower($id)) ?? throw Refusal::notFound('Purchase order not found.');
        $actor = strtolower($actorId);
        $supplier = $this->store->supplier($property, $order['supplier_id']);
        $location = $this->inventory->location($property, $order['location_id']);
        $items = array_column($this->inventory->items($property), null, 'id');
        $approval = $order['approval_id'] === null ? null : $this->approvals->find($property, $order['approval_id']);
        $mine = $order['created_by'] === $actor;
        $manage = $this->access->may($property, $actorId, PurchasingAccess::ORDER_MANAGE);
        $names = $this->staff->namesOf($property, array_values(array_unique(array_merge([$order['created_by']], array_column($this->store->orderRevisions($order['id']), 'created_by')))));
        $utc = static fn (mixed $v): ?string => $v === null ? null : (new DateTimeImmutable((string) $v, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $period = substr((string) $order['order_date'], 0, 7);
        $perDepartment = [];

        foreach ($order['lines'] as $l) {
            if ((bool) $l['is_active'] && $l['department'] !== null) {
                $perDepartment[$l['department']] = ($perDepartment[$l['department']] ?? 0) + (int) $l['line_total_minor'];
            }
        }

        $state = $order['status'];
        $receivable = in_array($state, ['issued', 'partially_received'], true);

        return [
            ...$this->head($order, $supplier, $location),
            'currency' => $this->currency->currencyOf($property), 'created_by_name' => $names[$order['created_by']] ?? null, 'issued_at' => $utc($order['issued_at']), 'close_reason' => $order['close_reason'],
            'departments' => InventoryCatalogService::DEPARTMENTS, 'items' => $this->itemChoices($property), 'locations' => $this->overview($property, $actorId, null, null)['locations'], 'suppliers' => $this->overview($property, $actorId, null, null)['suppliers'],
            'lines' => array_map(static fn (array $l): array => [
                'id' => $l['id'], 'line_no' => (int) $l['line_no'], 'item_id' => $l['item_id'], 'item_code' => $items[$l['item_id']]['code'] ?? '', 'item_name' => $items[$l['item_id']]['name'] ?? '', 'unit' => $l['unit'], 'qty_milli' => (int) $l['qty_milli'],
                'unit_price_minor' => (int) $l['unit_price_minor'], 'line_total_minor' => (int) $l['line_total_minor'], 'department' => $l['department'], 'request_line_id' => $l['request_line_id'], 'received_qty_milli' => (int) $l['received_qty_milli'],
                'rejected_qty_milli' => (int) $l['rejected_qty_milli'], 'is_active' => (bool) $l['is_active'],
            ], $order['lines']),
            'revisions' => array_map(static fn (array $r): array => ['revision' => (int) $r['revision'], 'reason' => $r['reason'], 'needed_approval' => (bool) $r['needed_approval'], 'created_by_name' => $names[$r['created_by']] ?? null, 'created_at' => $utc($r['created_at']), 'total_minor' => (int) ($r['snapshot']['total_minor'] ?? 0)], $this->store->orderRevisions($order['id'])),
            'pending_revision' => $order['pending_revision'] === null ? null : ['reason' => json_decode((string) $order['pending_revision'], true)['reason'] ?? '', 'total_minor' => (int) (json_decode((string) $order['pending_revision'], true)['snapshot']['total_minor'] ?? 0)],
            'approval' => $approval === null ? null : ['id' => $approval->id, 'status' => $approval->status, 'consumed' => $approval->consumed, 'steps' => $approval->steps, 'decisions' => $approval->decisions],
            'budgets' => array_values(array_map(fn (string $d, int $amount): array => $this->settings->standing($property, $d, $period, 0, $order['id']) + ['this_order_minor' => $amount], array_keys($perDepartment), array_values($perDepartment))),
            'may_edit' => $manage && $state === 'draft', 'may_submit' => $manage && $state === 'draft', 'may_release' => $manage && $mine && $state === 'pending_approval',
            'may_issue' => $manage && $state === 'approved', 'may_revise' => $manage && in_array($state, ['approved', 'issued', 'partially_received'], true),
            'may_cancel' => $manage && in_array($state, ['draft', 'pending_approval', 'approved', 'issued'], true) && ! $this->anyReceived($order), 'may_close' => $manage && $state === 'partially_received', 'may_receive' => $receivable,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines  each either `request_line_id` (an approved request line) or `item_id`, `unit`, `quantity` and optionally `department`; `unit_price_minor` is optional
     * @return array<string, mixed>
     */
    public function create(PropertyId $property, string $actorId, string $supplierId, string $locationId, ?string $expectedDate, ?int $taxBp, ?string $note, array $lines): array
    {
        $this->access->require($property, $actorId, PurchasingAccess::ORDER_MANAGE, 'This person may not make purchase orders.');
        $actor = strtolower($actorId);
        $id = $this->ids->next();
        $made = $this->clean($property, $supplierId, $locationId, $expectedDate, $taxBp, $note, $lines, null);

        $this->transactions->run(function () use ($property, $actor, $id, $made): void {
            $number = $this->numbers->next($property, 'PO');
            $row = [
                'id' => $id, 'number' => $number, 'revision' => 0, 'supplier_id' => $made['supplier']['id'], 'location_id' => $made['location']['id'], 'order_date' => $this->businessDate->current($property)->toString(), 'expected_date' => $made['expected_date'],
                'payment_terms_days' => (int) $made['supplier']['payment_terms_days'], 'tax_bp' => $made['tax_bp'], 'subtotal_minor' => $made['subtotal'], 'tax_minor' => $made['tax'], 'total_minor' => $made['subtotal'] + $made['tax'], 'note' => $made['note'], 'created_by' => $actor,
                'business_date' => $this->businessDate->current($property)->toString(),
            ];

            if (! $this->store->addOrder($property, $row, $made['lines'], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('An order with this number already exists. Try again.');
            }

            $this->linkRequestLines($property, $id, $made['lines']);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'purchase_order.created', 'purchase_order', $id, null, ['number' => $number, 'supplier' => $made['supplier']['code'], 'total_minor' => $made['subtotal'] + $made['tax'], 'lines' => count($made['lines'])]));
        });

        return $this->show($property, $actorId, $id);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    public function update(PropertyId $property, string $actorId, string $id, string $supplierId, string $locationId, ?string $expectedDate, ?int $taxBp, ?string $note, array $lines, int $lock): array
    {
        $this->access->require($property, $actorId, PurchasingAccess::ORDER_MANAGE, 'This person may not change purchase orders.');
        $order = $this->store->order($property, strtolower($id)) ?? throw Refusal::notFound('Purchase order not found.');

        if ($order['status'] !== 'draft') {
            throw Refusal::stateConflict('Only a draft can be edited. After approval a change is a revision.');
        }

        $made = $this->clean($property, $supplierId, $locationId, $expectedDate, $taxBp, $note, $lines, $order['id']);
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $order, $made, $lock): void {
            $fields = ['supplier_id' => $made['supplier']['id'], 'location_id' => $made['location']['id'], 'expected_date' => $made['expected_date'], 'payment_terms_days' => (int) $made['supplier']['payment_terms_days'], 'tax_bp' => $made['tax_bp'],
                'subtotal_minor' => $made['subtotal'], 'tax_minor' => $made['tax'], 'total_minor' => $made['subtotal'] + $made['tax'], 'note' => $made['note']];

            if (! $this->store->updateOrder($property, $order['id'], $lock, $fields, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This order changed after you opened it.');
            }

            foreach ($order['lines'] as $l) {
                if ($l['request_line_id'] !== null) {
                    $this->store->linkRequestLine($l['request_line_id'], null, null);
                }
            }

            $this->releaseRequests($property, $order['lines']);
            $this->store->replaceOrderLines($order['id'], $made['lines']);
            $this->linkRequestLines($property, $order['id'], $made['lines']);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'purchase_order.updated', 'purchase_order', $order['id'], ['total_minor' => (int) $order['total_minor']], ['total_minor' => $made['subtotal'] + $made['tax'], 'lines' => count($made['lines'])]));
        });

        return $this->show($property, $actorId, $order['id']);
    }

    /** Sends the draft for approval by its value, or approves it when no policy applies. @return array<string, mixed> */
    public function submit(PropertyId $property, string $actorId, string $id, int $lock): array
    {
        $this->access->require($property, $actorId, PurchasingAccess::ORDER_MANAGE, 'This person may not submit purchase orders.');
        $order = $this->store->order($property, strtolower($id)) ?? throw Refusal::notFound('Purchase order not found.');

        if ($order['status'] !== 'draft') {
            throw Refusal::stateConflict('Only a draft can be submitted.');
        }

        $actor = strtolower($actorId);
        $total = (int) $order['total_minor'];
        $warnings = [];

        $this->transactions->run(function () use ($property, $actor, $order, $lock, $total, &$warnings): void {
            foreach ($this->perDepartment($order['lines']) as $department => $amount) {
                $warning = $this->settings->enforce($property, $department, substr((string) $order['order_date'], 0, 7), $amount, $order['id']);

                if ($warning !== null) {
                    $warnings[] = $warning;
                }
            }

            $at = $this->clock->nowUtc();
            $required = $this->approvals->requirementFor($property, self::SUBJECT, $total)->required;
            $approvalId = null;

            if ($required) {
                $approvalId = $this->approvals->request(new ApprovalRequestInput(
                    $property, self::SUBJECT, $order['id'], $actor, 'Purchase order '.$order['number'], $this->payload($order),
                    ['number' => $order['number'], 'revision' => (int) $order['revision']], $total, $this->currency->currencyOf($property),
                ), IdempotencyKey::fromString('po-submit-'.$order['id'].'-'.$lock))->id;
            }

            if (! $this->store->updateOrder($property, $order['id'], $lock, ['status' => $required ? 'pending_approval' : 'approved', 'resume_status' => null, 'approval_id' => $approvalId], $at)) {
                throw Refusal::stateConflict('This order changed after you opened it.');
            }

            if (! $required) {
                $this->snapshot($order, 0, 'Approved', false, null, $actor);
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'purchase_order.submitted', 'purchase_order', $order['id'], ['status' => 'draft'], ['status' => $required ? 'pending_approval' : 'approved', 'number' => $order['number'], 'total_minor' => $total], null, $approvalId));
            $this->outbox->publish(new OutboxEvent($property, 'purchasing.order.submitted', $order['id'], 1, ['order_id' => $order['id'], 'number' => $order['number'], 'total_minor' => $total, 'approval_required' => $required, 'actor_id' => $actor]));
        });

        return [...$this->show($property, $actorId, $order['id']), 'budget_warnings' => $warnings];
    }

    /**
     * Takes the decision of the approvers. Approved: a new order becomes approved, or a revision takes effect. Rejected: a new order returns to a draft
     * with the reason, and a revision is dropped, leaving the order as it was.
     *
     * @return array<string, mixed>
     */
    public function release(PropertyId $property, string $actorId, string $id): array
    {
        $this->access->require($property, $actorId, PurchasingAccess::ORDER_MANAGE, 'This person may not release purchase orders.');
        $order = $this->store->order($property, strtolower($id)) ?? throw Refusal::notFound('Purchase order not found.');
        $actor = strtolower($actorId);

        if ($order['status'] !== 'pending_approval') {
            throw Refusal::stateConflict('This order is not waiting for approval.');
        }

        if ($order['created_by'] !== $actor && $order['approval_id'] !== null) {
            $maker = $this->approvals->find($property, $order['approval_id']);

            if ($maker !== null && $maker->makerId !== $actor) {
                throw Refusal::forbidden('Only the person who asked for the approval may take it.');
            }
        }

        $view = $this->approvals->find($property, (string) $order['approval_id']) ?? throw Refusal::notFound('Approval not found.');
        $pending = $order['pending_revision'] === null ? null : json_decode((string) $order['pending_revision'], true, 512, JSON_THROW_ON_ERROR);

        if (in_array($view->status, ['rejected', 'cancelled'], true)) {
            $this->transactions->run(function () use ($property, $actor, $order, $pending, $view): void {
                $note = null;

                foreach ($view->decisions as $d) {
                    $note = $d['reason'] ?? $note;
                }

                $back = $pending === null ? 'draft' : ($order['resume_status'] ?? 'approved');

                if (! $this->store->updateOrder($property, $order['id'], (int) $order['lock_version'], ['status' => $back, 'resume_status' => null, 'pending_revision' => null, 'approval_id' => $pending === null ? null : $order['approval_id']], $this->clock->nowUtc())) {
                    throw Refusal::stateConflict('This order changed meanwhile.');
                }

                $this->audit->record(new AuditEntry($property->toString(), $actor, $pending === null ? 'purchase_order.rejected' : 'purchase_order.revision_rejected', 'purchase_order', $order['id'], ['status' => 'pending_approval'], ['status' => $back, 'number' => $order['number']], $note, $order['approval_id']));
            });
        } elseif ($view->isApproved() && ! $view->consumed) {
            $this->transactions->run(function () use ($property, $actor, $order, $pending): void {
                $this->approvals->consume($property, (string) $order['approval_id'], self::SUBJECT, $order['id'], $pending === null ? $this->payload($order) : $pending['payload'], $actor);

                if ($pending === null) {
                    if (! $this->store->updateOrder($property, $order['id'], (int) $order['lock_version'], ['status' => 'approved'], $this->clock->nowUtc())) {
                        throw Refusal::stateConflict('This order changed meanwhile.');
                    }

                    $this->snapshot($order, 0, 'Approved', true, $order['approval_id'], $actor);
                    $this->audit->record(new AuditEntry($property->toString(), $actor, 'purchase_order.approved', 'purchase_order', $order['id'], ['status' => 'pending_approval'], ['status' => 'approved', 'number' => $order['number']], null, $order['approval_id']));
                    $this->outbox->publish(new OutboxEvent($property, 'purchasing.order.approved', $order['id'], 1, ['order_id' => $order['id'], 'number' => $order['number'], 'actor_id' => $actor]));

                    return;
                }

                $this->applyRevision($property, $actor, $order, $pending['snapshot'], $pending['reason'], true, $order['approval_id']);
            });
        }

        return $this->show($property, $actorId, $order['id']);
    }

    /** Sends an approved order to the supplier. @return array<string, mixed> */
    public function issue(PropertyId $property, string $actorId, string $id, int $lock): array
    {
        $this->access->require($property, $actorId, PurchasingAccess::ORDER_MANAGE, 'This person may not issue purchase orders.');
        $order = $this->store->order($property, strtolower($id)) ?? throw Refusal::notFound('Purchase order not found.');

        if ($order['status'] !== 'approved') {
            throw Refusal::stateConflict('Only an approved order can be issued.');
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $order, $lock): void {
            $at = $this->clock->nowUtc();

            if (! $this->store->updateOrder($property, $order['id'], $lock, ['status' => 'issued', 'issued_at' => $at], $at)) {
                throw Refusal::stateConflict('This order changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'purchase_order.issued', 'purchase_order', $order['id'], ['status' => 'approved'], ['status' => 'issued', 'number' => $order['number'], 'revision' => (int) $order['revision']]));
            $this->outbox->publish(new OutboxEvent($property, 'purchasing.order.issued', $order['id'], 1, ['order_id' => $order['id'], 'number' => $order['number'], 'supplier_id' => $order['supplier_id'], 'total_minor' => (int) $order['total_minor'], 'actor_id' => $actor]));
        });

        return $this->show($property, $actorId, $order['id']);
    }

    /**
     * A change to an approved order, with its reason. It becomes revision n+1. When it changes the supplier or the lines, or moves the value or a
     * quantity beyond the tolerance, and an approval policy applies to the new value, it waits for approval and takes effect only then.
     *
     * @param  list<array<string, mixed>>  $lines  existing lines by `line_id` (new `quantity`, `unit_price_minor`), new lines by `item_id`, `unit`, `quantity`; a line left out is dropped if nothing was received on it
     * @return array<string, mixed>
     */
    public function revise(PropertyId $property, string $actorId, string $id, string $supplierId, string $locationId, ?string $expectedDate, ?int $taxBp, ?string $note, array $lines, string $reason, int $lock): array
    {
        $this->access->require($property, $actorId, PurchasingAccess::ORDER_MANAGE, 'This person may not revise purchase orders.');
        $order = $this->store->order($property, strtolower($id)) ?? throw Refusal::notFound('Purchase order not found.');

        if (! in_array($order['status'], ['approved', 'issued', 'partially_received'], true)) {
            throw Refusal::stateConflict('Only an approved order can be revised.');
        }

        if ((int) $order['lock_version'] !== $lock) {
            throw Refusal::stateConflict('This order changed after you opened it.');
        }

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('A revision needs a reason of at most 200 characters.', ['reason']);
        }

        $made = $this->cleanRevision($property, $order, $supplierId, $locationId, $expectedDate, $taxBp, $note, $lines);
        $oldTotal = (int) $order['total_minor'];
        $newTotal = $made['subtotal'] + $made['tax'];
        $tolerance = $this->settings->settings($property)['po_tolerance_bp'];
        $structural = $made['supplier']['id'] !== $order['supplier_id'] || $made['structural'];
        $valueMoved = $oldTotal === 0 ? $newTotal > 0 : intdiv(abs($newTotal - $oldTotal) * 10000, $oldTotal) > $tolerance;
        $big = $structural || $valueMoved || $made['qty_moved_bp'] > $tolerance;
        $actor = strtolower($actorId);

        if ($made['snapshot'] === $this->snapshotOf($order)) {
            throw Refusal::invalid('Nothing changed.', ['lines']);
        }

        $this->transactions->run(function () use ($property, $actor, $order, $made, $newTotal, $big, $reason, $lock): void {
            $required = $big && $this->approvals->requirementFor($property, self::SUBJECT, $newTotal)->required;

            if (! $required) {
                $this->applyRevision($property, $actor, $order, $made['snapshot'], $reason, false, null);

                return;
            }

            $payload = ['number' => $order['number'], 'revision' => (int) $order['revision'] + 1, 'total_minor' => $newTotal, 'snapshot' => $made['snapshot']];
            $approvalId = $this->approvals->request(new ApprovalRequestInput(
                $property, self::SUBJECT, $order['id'], $actor, $reason, $payload, ['number' => $order['number'], 'revision' => (int) $order['revision'], 'total_minor' => (int) $order['total_minor']], $newTotal, $this->currency->currencyOf($property),
            ), IdempotencyKey::fromString('po-revise-'.$order['id'].'-'.$lock))->id;

            if (! $this->store->updateOrder($property, $order['id'], $lock, ['status' => 'pending_approval', 'resume_status' => $order['status'], 'approval_id' => $approvalId, 'pending_revision' => json_encode(['reason' => $reason, 'snapshot' => $made['snapshot'], 'payload' => $payload], JSON_THROW_ON_ERROR)], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This order changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'purchase_order.revision_requested', 'purchase_order', $order['id'], ['total_minor' => (int) $order['total_minor'], 'revision' => (int) $order['revision']], ['total_minor' => $newTotal, 'revision' => (int) $order['revision'] + 1], $reason, $approvalId));
        });

        return $this->show($property, $actorId, $order['id']);
    }

    /** @return array<string, mixed> */
    public function cancel(PropertyId $property, string $actorId, string $id, string $reason, int $lock): array
    {
        $this->access->require($property, $actorId, PurchasingAccess::ORDER_MANAGE, 'This person may not cancel purchase orders.');
        $order = $this->store->order($property, strtolower($id)) ?? throw Refusal::notFound('Purchase order not found.');

        if (! in_array($order['status'], ['draft', 'pending_approval', 'approved', 'issued'], true) || $this->anyReceived($order)) {
            throw Refusal::stateConflict('This order can no longer be cancelled. Close it instead if goods were received.');
        }

        return $this->finish($property, $actorId, $order, 'cancelled', $reason, $lock);
    }

    /** What was not delivered is given up: a partly received order is closed. @return array<string, mixed> */
    public function close(PropertyId $property, string $actorId, string $id, string $reason, int $lock): array
    {
        $this->access->require($property, $actorId, PurchasingAccess::ORDER_MANAGE, 'This person may not close purchase orders.');
        $order = $this->store->order($property, strtolower($id)) ?? throw Refusal::notFound('Purchase order not found.');

        if ($order['status'] !== 'partially_received') {
            throw Refusal::stateConflict('Only a partly received order is closed.');
        }

        return $this->finish($property, $actorId, $order, 'closed', $reason, $lock);
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private function finish(PropertyId $property, string $actorId, array $order, string $to, string $reason, int $lock): array
    {
        if (trim($reason) === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('A reason of at most 200 characters is required.', ['reason']);
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $order, $to, $reason, $lock): void {
            if (! $this->store->updateOrder($property, $order['id'], $lock, ['status' => $to, 'close_reason' => trim($reason), 'pending_revision' => null, 'resume_status' => null], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This order changed after you opened it.');
            }

            if ($to === 'cancelled') {
                foreach ($order['lines'] as $l) {
                    if ($l['request_line_id'] !== null) {
                        $this->store->linkRequestLine($l['request_line_id'], null, null);
                    }
                }

                $this->releaseRequests($property, $order['lines']);
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'purchase_order.'.$to, 'purchase_order', $order['id'], ['status' => $order['status']], ['status' => $to, 'number' => $order['number']], trim($reason), $order['approval_id']));
            $this->outbox->publish(new OutboxEvent($property, 'purchasing.order.'.$to, $order['id'], 1, ['order_id' => $order['id'], 'number' => $order['number'], 'actor_id' => $actor]));
        });

        return $this->show($property, $actorId, $order['id']);
    }

    /**
     * Puts a revision into effect: the order takes the new header and lines, the revision number goes up, and the snapshot is kept.
     *
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $snapshot
     */
    private function applyRevision(PropertyId $property, string $actor, array $order, array $snapshot, string $reason, bool $neededApproval, ?string $approvalId): void
    {
        $revision = (int) $order['revision'] + 1;
        $existing = array_column($order['lines'], null, 'id');
        $kept = [];
        $at = $this->clock->nowUtc();
        $next = max(array_map(static fn (array $l): int => (int) $l['line_no'], $order['lines']) ?: [0]);

        foreach ($snapshot['lines'] as $l) {
            if ($l['line_id'] !== null && isset($existing[$l['line_id']])) {
                $kept[$l['line_id']] = true;
                $this->store->updateOrderLine($l['line_id'], ['qty_milli' => $l['qty_milli'], 'unit_price_minor' => $l['unit_price_minor'], 'line_total_minor' => $l['line_total_minor'], 'department' => $l['department'], 'is_active' => true]);

                continue;
            }

            $this->store->addOrderLine($order['id'], ['id' => $this->ids->next(), 'line_no' => ++$next, 'item_id' => $l['item_id'], 'unit' => $l['unit'], 'qty_milli' => $l['qty_milli'], 'unit_price_minor' => $l['unit_price_minor'], 'line_total_minor' => $l['line_total_minor'], 'department' => $l['department'], 'request_line_id' => null]);
        }

        foreach ($existing as $lineId => $l) {
            if (! isset($kept[$lineId]) && (bool) $l['is_active']) {
                $this->store->updateOrderLine($lineId, ['is_active' => false]);
            }
        }

        $status = $order['status'] === 'pending_approval' ? ($order['resume_status'] ?? 'approved') : $order['status'];
        $fields = [
            'supplier_id' => $snapshot['supplier_id'], 'location_id' => $snapshot['location_id'], 'expected_date' => $snapshot['expected_date'], 'tax_bp' => $snapshot['tax_bp'], 'payment_terms_days' => $snapshot['payment_terms_days'], 'note' => $snapshot['note'],
            'subtotal_minor' => $snapshot['subtotal_minor'], 'tax_minor' => $snapshot['tax_minor'], 'total_minor' => $snapshot['total_minor'], 'revision' => $revision, 'status' => $status, 'resume_status' => null, 'pending_revision' => null,
            'approval_id' => $approvalId ?? $order['approval_id'],
        ];

        if (! $this->store->updateOrder($property, $order['id'], (int) $order['lock_version'], $fields, $at)) {
            throw Refusal::stateConflict('This order changed after you opened it.');
        }

        $this->store->addOrderRevision(['id' => $this->ids->next(), 'order_id' => $order['id'], 'revision' => $revision, 'snapshot' => $snapshot, 'reason' => $reason, 'needed_approval' => $neededApproval, 'approval_id' => $approvalId, 'created_by' => $actor], $at);
        $this->audit->record(new AuditEntry($property->toString(), $actor, 'purchase_order.revised', 'purchase_order', $order['id'], ['total_minor' => (int) $order['total_minor'], 'revision' => (int) $order['revision']], ['total_minor' => $snapshot['total_minor'], 'revision' => $revision, 'number' => $order['number']], $reason, $approvalId));
        $this->outbox->publish(new OutboxEvent($property, 'purchasing.order.revised', $order['id'], 1, ['order_id' => $order['id'], 'number' => $order['number'], 'revision' => $revision, 'total_minor' => $snapshot['total_minor'], 'actor_id' => $actor]));
    }

    /** @param array<string, mixed> $order */
    private function snapshot(array $order, int $revision, string $reason, bool $neededApproval, ?string $approvalId, string $actor): void
    {
        $this->store->addOrderRevision(['id' => $this->ids->next(), 'order_id' => $order['id'], 'revision' => $revision, 'snapshot' => $this->snapshotOf($order), 'reason' => $reason, 'needed_approval' => $neededApproval, 'approval_id' => $approvalId, 'created_by' => $actor], $this->clock->nowUtc());
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private function snapshotOf(array $order): array
    {
        $lines = [];

        foreach ($order['lines'] as $l) {
            if ((bool) $l['is_active']) {
                $lines[] = ['line_id' => $l['id'], 'item_id' => $l['item_id'], 'unit' => $l['unit'], 'qty_milli' => (int) $l['qty_milli'], 'unit_price_minor' => (int) $l['unit_price_minor'], 'line_total_minor' => (int) $l['line_total_minor'], 'department' => $l['department']];
            }
        }

        return [
            'supplier_id' => $order['supplier_id'], 'location_id' => $order['location_id'], 'expected_date' => $order['expected_date'] === null ? null : substr((string) $order['expected_date'], 0, 10), 'payment_terms_days' => (int) $order['payment_terms_days'], 'tax_bp' => (int) $order['tax_bp'],
            'note' => $order['note'], 'subtotal_minor' => (int) $order['subtotal_minor'], 'tax_minor' => (int) $order['tax_minor'], 'total_minor' => (int) $order['total_minor'], 'lines' => $lines,
        ];
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed> what the approvers see and a later release must match
     */
    private function payload(array $order): array
    {
        return ['number' => $order['number'], 'revision' => (int) $order['revision'], 'total_minor' => (int) $order['total_minor'], 'snapshot' => $this->snapshotOf($order)];
    }

    /** @param array<string, mixed> $order */
    private function anyReceived(array $order): bool
    {
        foreach ($order['lines'] as $l) {
            if ((int) $l['received_qty_milli'] > 0 || (int) $l['rejected_qty_milli'] > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, int> the value per department, for the budget
     */
    private function perDepartment(array $lines): array
    {
        $out = [];

        foreach ($lines as $l) {
            if ((bool) ($l['is_active'] ?? true) && $l['department'] !== null) {
                $out[$l['department']] = ($out[$l['department']] ?? 0) + (int) $l['line_total_minor'];
            }
        }

        return $out;
    }

    /**
     * Validates a new order's header and lines. Lines made from a request line take the item, unit, quantity and department from it.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array{supplier: array<string, mixed>, location: array<string, mixed>, expected_date: ?string, tax_bp: int, note: ?string, subtotal: int, tax: int, lines: list<array<string, mixed>>}
     */
    private function clean(PropertyId $property, string $supplierId, string $locationId, ?string $expectedDate, ?int $taxBp, ?string $note, array $lines, ?string $orderId): array
    {
        [$supplier, $location, $expected, $tax, $note] = $this->header($property, $supplierId, $locationId, $expectedDate, $taxBp, $note);

        if ($lines === [] || count($lines) > self::MAX_LINES) {
            throw Refusal::invalid('An order has one to '.self::MAX_LINES.' lines.', ['lines']);
        }

        $today = $this->businessDate->current($property)->toString();
        $out = [];
        $seen = [];
        $subtotal = 0;
        $no = 0;

        foreach ($lines as $line) {
            $requestLine = null;
            $department = isset($line['department']) && $line['department'] !== '' ? (string) $line['department'] : null;

            if (isset($line['request_line_id']) && $line['request_line_id'] !== '') {
                [$requestLine, $request] = $this->requestLine($property, strtolower((string) $line['request_line_id']), $orderId);
                $itemId = $requestLine['item_id'];
                $unit = $requestLine['unit'];
                $qty = (int) $requestLine['qty_milli'];
                $department = $request['department'];
            } else {
                $itemId = strtolower((string) ($line['item_id'] ?? ''));
                $unit = strtoupper(trim((string) ($line['unit'] ?? '')));
                $qty = StockQuantity::parse((string) ($line['quantity'] ?? ''));

                if ($qty === null || $qty < 1 || $qty > StockQuantity::MAX_MILLI) {
                    throw Refusal::invalid('Give every quantity as a number above zero, with at most three decimals.', ['lines']);
                }
            }

            if ($department !== null && ! in_array($department, InventoryCatalogService::DEPARTMENTS, true)) {
                throw Refusal::invalid('Choose a department from the list.', ['lines']);
            }

            $item = $this->inventory->item($property, $itemId) ?? throw Refusal::invalid('Choose an item for every line.', ['lines']);

            if (! (bool) $item['is_active']) {
                throw Refusal::stateConflict('Item '.$item['code'].' is inactive.');
            }

            if ($unit !== $item['base_unit'] && $this->inventory->currentUnit($property, $item['id'], $unit) === null) {
                throw Refusal::invalid('Item '.$item['code'].' has no conversion for the unit '.$unit.'.', ['lines']);
            }

            if (isset($seen[$item['id'].'|'.$unit])) {
                throw Refusal::invalid('An item appears once in an order, in one unit.', ['lines']);
            }

            $seen[$item['id'].'|'.$unit] = true;
            $price = isset($line['unit_price_minor']) && $line['unit_price_minor'] !== '' ? (int) $line['unit_price_minor'] : ($this->store->priceOn($property, $supplier['id'], $item['id'], $unit, $today)['unit_price_minor'] ?? null);

            if ($price === null) {
                throw Refusal::invalid('There is no price for '.$item['code'].' in '.$unit.' from this supplier. Give the price.', ['lines']);
            }

            $price = (int) $price;

            if ($price < 0 || $price > StockValue::MAX_UNIT_COST_MINOR) {
                throw Refusal::invalid('Give every price as a whole amount of at most 100,000,000.', ['lines']);
            }

            $total = StockValue::ofQuantity($qty, $price);
            $subtotal += $total;
            $out[] = ['id' => $this->ids->next(), 'line_no' => ++$no, 'item_id' => $item['id'], 'unit' => $unit, 'qty_milli' => $qty, 'unit_price_minor' => $price, 'line_total_minor' => $total, 'department' => $department, 'request_line_id' => $requestLine['id'] ?? null];
        }

        return ['supplier' => $supplier, 'location' => $location, 'expected_date' => $expected, 'tax_bp' => $tax, 'note' => $note, 'subtotal' => $subtotal, 'tax' => StockValue::mulDiv($subtotal, $tax, 10000), 'lines' => $out];
    }

    /**
     * Validates a revision and builds the snapshot it would make. Existing lines are matched by id; a line left out is dropped, but not when goods were received on it.
     *
     * @param  array<string, mixed>  $order
     * @param  list<array<string, mixed>>  $lines
     * @return array{supplier: array<string, mixed>, snapshot: array<string, mixed>, subtotal: int, tax: int, structural: bool, qty_moved_bp: int}
     */
    private function cleanRevision(PropertyId $property, array $order, string $supplierId, string $locationId, ?string $expectedDate, ?int $taxBp, ?string $note, array $lines): array
    {
        [$supplier, $location, $expected, $tax, $note] = $this->header($property, $supplierId, $locationId, $expectedDate, $taxBp, $note);
        $existing = array_column(array_filter($order['lines'], static fn (array $l): bool => (bool) $l['is_active']), null, 'id');
        $received = $this->anyReceived($order);

        if ($supplier['id'] !== $order['supplier_id'] && $received) {
            throw Refusal::stateConflict('The supplier cannot change after goods were received.');
        }

        if ($lines === [] || count($lines) > self::MAX_LINES) {
            throw Refusal::invalid('An order has one to '.self::MAX_LINES.' lines.', ['lines']);
        }

        $out = [];
        $kept = [];
        $subtotal = 0;
        $structural = false;
        $qtyMoved = 0;
        $today = $this->businessDate->current($property)->toString();

        foreach ($lines as $line) {
            $lineId = isset($line['line_id']) && $line['line_id'] !== '' ? strtolower((string) $line['line_id']) : null;
            $qty = StockQuantity::parse((string) ($line['quantity'] ?? ''));

            if ($qty === null || $qty < 1 || $qty > StockQuantity::MAX_MILLI) {
                throw Refusal::invalid('Give every quantity as a number above zero, with at most three decimals.', ['lines']);
            }

            if ($lineId !== null) {
                $old = $existing[$lineId] ?? throw Refusal::invalid('A line does not belong to this order.', ['lines']);
                $kept[$lineId] = true;

                if ($qty < (int) $old['received_qty_milli'] + (int) $old['rejected_qty_milli'] && $qty < (int) $old['received_qty_milli']) {
                    throw Refusal::stateConflict('The quantity of '.($this->inventory->item($property, $old['item_id'])['code'] ?? 'a line').' cannot be below what was already received.');
                }

                $moved = (int) $old['qty_milli'] === 0 ? 10000 : intdiv(abs($qty - (int) $old['qty_milli']) * 10000, (int) $old['qty_milli']);
                $qtyMoved = max($qtyMoved, $moved);
                $itemId = $old['item_id'];
                $unit = $old['unit'];
                $department = $old['department'];
                $default = (int) $old['unit_price_minor'];
            } else {
                $structural = true;
                $itemId = strtolower((string) ($line['item_id'] ?? ''));
                $unit = strtoupper(trim((string) ($line['unit'] ?? '')));
                $department = isset($line['department']) && $line['department'] !== '' ? (string) $line['department'] : null;
                $item = $this->inventory->item($property, $itemId) ?? throw Refusal::invalid('Choose an item for every new line.', ['lines']);

                if ($unit !== $item['base_unit'] && $this->inventory->currentUnit($property, $item['id'], $unit) === null) {
                    throw Refusal::invalid('Item '.$item['code'].' has no conversion for the unit '.$unit.'.', ['lines']);
                }

                if ($department !== null && ! in_array($department, InventoryCatalogService::DEPARTMENTS, true)) {
                    throw Refusal::invalid('Choose a department from the list.', ['lines']);
                }

                $default = $this->store->priceOn($property, $supplier['id'], $item['id'], $unit, $today)['unit_price_minor'] ?? null;
                $qtyMoved = 10000;
            }

            $price = isset($line['unit_price_minor']) && $line['unit_price_minor'] !== '' ? (int) $line['unit_price_minor'] : $default;

            if ($price === null || $price < 0 || $price > StockValue::MAX_UNIT_COST_MINOR) {
                throw Refusal::invalid('Give every price as a whole amount of at most 100,000,000.', ['lines']);
            }

            $total = StockValue::ofQuantity($qty, (int) $price);
            $subtotal += $total;
            $out[] = ['line_id' => $lineId, 'item_id' => $itemId, 'unit' => $unit, 'qty_milli' => $qty, 'unit_price_minor' => (int) $price, 'line_total_minor' => $total, 'department' => $department];
        }

        foreach ($existing as $lineId => $old) {
            if (! isset($kept[$lineId])) {
                if ((int) $old['received_qty_milli'] > 0 || (int) $old['rejected_qty_milli'] > 0) {
                    throw Refusal::stateConflict('A line with goods received cannot be removed.');
                }

                $structural = true;
            }
        }

        $taxMinor = StockValue::mulDiv($subtotal, $tax, 10000);
        $snapshot = [
            'supplier_id' => $supplier['id'], 'location_id' => $location['id'], 'expected_date' => $expected, 'payment_terms_days' => (int) $supplier['payment_terms_days'], 'tax_bp' => $tax, 'note' => $note,
            'subtotal_minor' => $subtotal, 'tax_minor' => $taxMinor, 'total_minor' => $subtotal + $taxMinor, 'lines' => $out,
        ];

        return ['supplier' => $supplier, 'snapshot' => $snapshot, 'subtotal' => $subtotal, 'tax' => $taxMinor, 'structural' => $structural, 'qty_moved_bp' => $qtyMoved];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: ?string, 3: int, 4: ?string} */
    private function header(PropertyId $property, string $supplierId, string $locationId, ?string $expectedDate, ?int $taxBp, ?string $note): array
    {
        $supplier = $this->store->supplier($property, strtolower($supplierId)) ?? throw Refusal::invalid('Choose a supplier.', ['supplier_id']);
        $location = $this->inventory->location($property, strtolower($locationId)) ?? throw Refusal::invalid('Choose the location the goods go to.', ['location_id']);

        if (! (bool) $supplier['is_active'] || ! (bool) $location['is_active']) {
            throw Refusal::stateConflict('An order needs an active supplier and an active location.');
        }

        $expected = $expectedDate === null || $expectedDate === '' ? null : $expectedDate;

        if ($expected !== null && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expected) !== 1 || ! checkdate((int) substr($expected, 5, 2), (int) substr($expected, 8, 2), (int) substr($expected, 0, 4)))) {
            throw Refusal::invalid('Give the date as year-month-day.', ['expected_date']);
        }

        $tax = $taxBp ?? $this->settings->settings($property)['tax_bp'];

        if ($tax < 0 || $tax > 5000) {
            throw Refusal::invalid('The tax rate is from 0 to 5000 basis points.', ['tax_bp']);
        }

        $note = $note === null || trim($note) === '' ? null : trim($note);

        if ($note !== null && mb_strlen($note) > 200) {
            throw Refusal::invalid('The note is at most 200 characters.', ['note']);
        }

        return [$supplier, $location, $expected, $tax, $note];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} an approved request line that is free (or already on this order) and its request
     */
    private function requestLine(PropertyId $property, string $lineId, ?string $orderId): array
    {
        foreach (['approved', 'ordered'] as $status) {
            foreach ($this->store->requests($property, $status, 500) as $head) {
                $request = $this->store->request($property, $head['id']);

                foreach ($request['lines'] ?? [] as $l) {
                    if ($l['id'] === $lineId) {
                        if ($l['po_id'] !== null && $l['po_id'] !== $orderId) {
                            throw Refusal::stateConflict('A request line is already on another order.');
                        }

                        return [$l, $request];
                    }
                }
            }
        }

        throw Refusal::invalid('A request line is not approved or does not exist.', ['lines']);
    }

    /** @param list<array<string, mixed>> $lines */
    private function linkRequestLines(PropertyId $property, string $orderId, array $lines): void
    {
        $requests = [];

        foreach ($lines as $l) {
            if ($l['request_line_id'] === null) {
                continue;
            }

            $this->store->linkRequestLine($l['request_line_id'], $orderId, $l['id']);
            $requests[$l['request_line_id']] = true;
        }

        $this->refreshRequests($property, array_keys($requests));
    }

    /** @param list<array<string, mixed>> $lines the order lines that were taken off */
    private function releaseRequests(PropertyId $property, array $lines): void
    {
        $this->refreshRequests($property, array_values(array_filter(array_column($lines, 'request_line_id'))));
    }

    /**
     * A request whose lines are all on orders is "ordered"; one that loses a line goes back to "approved".
     *
     * @param  list<string>  $requestLineIds
     */
    private function refreshRequests(PropertyId $property, array $requestLineIds): void
    {
        if ($requestLineIds === []) {
            return;
        }

        $wanted = array_flip($requestLineIds);

        foreach (['approved', 'ordered'] as $status) {
            foreach ($this->store->requests($property, $status, 500) as $head) {
                $request = $this->store->request($property, $head['id']);

                if ($request === null || array_intersect_key(array_flip(array_column($request['lines'], 'id')), $wanted) === []) {
                    continue;
                }

                $all = array_reduce($request['lines'], static fn (bool $carry, array $l): bool => $carry && $l['po_id'] !== null, true);
                $to = $all ? 'ordered' : 'approved';

                if ($to !== $request['status']) {
                    $this->store->updateRequest($property, $request['id'], (int) $request['lock_version'], ['status' => $to], $this->clock->nowUtc());
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>|null  $supplier
     * @param  array<string, mixed>|null  $location
     * @return array<string, mixed>
     */
    private function head(array $order, ?array $supplier, ?array $location): array
    {
        return [
            'id' => $order['id'], 'number' => $order['number'], 'revision' => (int) $order['revision'], 'status' => $order['status'], 'order_date' => substr((string) $order['order_date'], 0, 10), 'expected_date' => $order['expected_date'] === null ? null : substr((string) $order['expected_date'], 0, 10),
            'supplier' => ['id' => $order['supplier_id'], 'code' => $supplier['code'] ?? '', 'name' => $supplier['name'] ?? ''], 'location' => ['id' => $order['location_id'], 'code' => $location['code'] ?? '', 'name' => $location['name'] ?? ''],
            'payment_terms_days' => (int) $order['payment_terms_days'], 'tax_bp' => (int) $order['tax_bp'], 'subtotal_minor' => (int) $order['subtotal_minor'], 'tax_minor' => (int) $order['tax_minor'], 'total_minor' => (int) $order['total_minor'], 'note' => $order['note'], 'lock_version' => (int) $order['lock_version'],
        ];
    }

    /** @return list<array<string, mixed>> the approved request lines that are not on an order yet */
    private function openRequestLines(PropertyId $property): array
    {
        $items = array_column($this->inventory->items($property), null, 'id');
        $out = [];

        foreach ($this->store->requests($property, 'approved', 200) as $head) {
            $request = $this->store->request($property, $head['id']);

            foreach ($request['lines'] ?? [] as $l) {
                if ($l['po_id'] === null) {
                    $out[] = ['id' => $l['id'], 'request_id' => $request['id'], 'request_number' => $request['number'], 'department' => $request['department'], 'urgency' => $request['urgency'], 'needed_by' => substr((string) $request['needed_by'], 0, 10), 'item_id' => $l['item_id'], 'item_code' => $items[$l['item_id']]['code'] ?? '', 'item_name' => $items[$l['item_id']]['name'] ?? '', 'unit' => $l['unit'], 'qty_milli' => (int) $l['qty_milli'], 'est_unit_cost_minor' => (int) $l['est_unit_cost_minor']];
                }
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function itemChoices(PropertyId $property): array
    {
        $units = [];

        foreach ($this->inventory->unitVersions($property) as $u) {
            $units[$u['item_id']][$u['unit']] = $u['unit'];
        }

        return array_values(array_map(static fn (array $i): array => ['id' => $i['id'], 'code' => $i['code'], 'name' => $i['name'], 'base_unit' => $i['base_unit'], 'units' => [$i['base_unit'], ...array_values($units[$i['id']] ?? [])]], array_filter($this->inventory->items($property), static fn (array $i): bool => (bool) $i['is_active'])));
    }
}
