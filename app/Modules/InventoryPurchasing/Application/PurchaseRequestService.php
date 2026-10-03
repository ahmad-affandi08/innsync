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
 * Purchase requests (FR-PUR-001, FR-PUR-002). A department asks for items with a quantity, the reason and how urgently it is needed. The requester
 * keeps it as a draft, then submits it. Its estimated value goes through the approval chain the owner configured for that amount (subject
 * `inventory.purchase-request`); with no policy for the amount it is approved on submit. When the approvers have decided, the requester releases
 * the decision (the approval gate lets only the maker use an approved request), and an approved request waits for Purchasing to put its lines on
 * a purchase order. The budget policy (FR-PUR-013) is applied when the request is submitted.
 */
final readonly class PurchaseRequestService
{
    public const SUBJECT = 'inventory.purchase-request';

    public const URGENCIES = ['low', 'normal', 'high', 'urgent'];

    public const MAX_LINES = 50;

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
    public function overview(PropertyId $property, string $actorId, ?string $status, ?string $department = null): array
    {
        $this->access->requireView($property, $actorId);

        if ($status !== null && $status !== '' && ! in_array($status, ['draft', 'pending_approval', 'approved', 'rejected', 'cancelled', 'ordered'], true)) {
            throw Refusal::invalid('Choose a status from the list.', ['status']);
        }

        if ($department !== null && $department !== '' && ! in_array($department, InventoryCatalogService::DEPARTMENTS, true)) {
            throw Refusal::invalid('Choose a department from the list.', ['department']);
        }

        $rows = $this->store->requests($property, $status === '' ? null : $status, 200);

        // A department that comes here from its own screen (laundry, kitchen) sees its own requests.
        if ($department !== null && $department !== '') {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['department'] === $department));
        }

        $names = $this->staff->namesOf($property, array_values(array_unique(array_column($rows, 'requested_by'))));

        return [
            'currency' => $this->currency->currencyOf($property),
            'requests' => array_map(fn (array $r): array => $this->head($r, $names), $rows),
            'departments' => InventoryCatalogService::DEPARTMENTS, 'urgencies' => self::URGENCIES, 'items' => $this->itemChoices($property),
            'may' => ['create' => $this->access->may($property, $actorId, PurchasingAccess::REQUEST_CREATE)],
        ];
    }

    /** @return array<string, mixed> */
    public function show(PropertyId $property, string $actorId, string $id): array
    {
        $this->access->requireView($property, $actorId);
        $request = $this->store->request($property, strtolower($id)) ?? throw Refusal::notFound('Purchase request not found.');
        $actor = strtolower($actorId);
        $items = array_column($this->inventory->items($property), null, 'id');
        $names = $this->staff->namesOf($property, [$request['requested_by']]);
        $approval = $request['approval_id'] === null ? null : $this->approvals->find($property, $request['approval_id']);
        $mine = $request['requested_by'] === $actor;
        $period = substr((string) $request['needed_by'], 0, 7);

        return [
            ...$this->head([...$request, 'line_count' => count($request['lines']), 'ordered_count' => count(array_filter($request['lines'], static fn (array $l): bool => $l['po_id'] !== null))], $names),
            'currency' => $this->currency->currencyOf($property), 'departments' => InventoryCatalogService::DEPARTMENTS, 'urgencies' => self::URGENCIES, 'items' => $this->itemChoices($property),
            'lines' => array_map(static fn (array $l): array => [
                'id' => $l['id'], 'item_id' => $l['item_id'], 'item_code' => $items[$l['item_id']]['code'] ?? '', 'item_name' => $items[$l['item_id']]['name'] ?? '', 'unit' => $l['unit'], 'qty_milli' => (int) $l['qty_milli'],
                'est_unit_cost_minor' => (int) $l['est_unit_cost_minor'], 'line_total_minor' => StockValue::ofQuantity((int) $l['qty_milli'], (int) $l['est_unit_cost_minor']), 'note' => $l['note'], 'po_id' => $l['po_id'],
            ], $request['lines']),
            'approval' => $approval === null ? null : ['id' => $approval->id, 'status' => $approval->status, 'consumed' => $approval->consumed, 'steps' => $approval->steps, 'decisions' => $approval->decisions],
            'budget' => $this->settings->standing($property, $request['department'], $period, 0),
            'may_edit' => $mine && $request['status'] === 'draft' && $this->access->may($property, $actorId, PurchasingAccess::REQUEST_CREATE),
            'may_submit' => $mine && $request['status'] === 'draft' && $this->access->may($property, $actorId, PurchasingAccess::REQUEST_CREATE),
            'may_release' => $mine && $request['status'] === 'pending_approval',
            'may_cancel' => in_array($request['status'], ['draft', 'pending_approval', 'approved'], true) && ($mine || $this->access->may($property, $actorId, PurchasingAccess::ORDER_MANAGE)) && ! $this->hasOrderedLines($request),
        ];
    }

    /**
     * @param  list<array{item_id: string, unit: string, quantity: string, est_cost_minor?: int|null, note?: string|null}>  $lines
     * @return array<string, mixed>
     */
    public function create(PropertyId $property, string $actorId, string $department, string $urgency, string $reason, string $neededBy, array $lines, ?IdempotencyKey $key = null): array
    {
        $this->access->require($property, $actorId, PurchasingAccess::REQUEST_CREATE, 'This person may not make purchase requests.');
        $clean = $this->clean($property, $department, $urgency, $reason, $neededBy, $lines);
        $actor = strtolower($actorId);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actor, $id, $clean): void {
            $number = $this->numbers->next($property, 'PR');
            $row = ['id' => $id, 'number' => $number, 'department' => $clean['department'], 'urgency' => $clean['urgency'], 'reason' => $clean['reason'], 'needed_by' => $clean['needed_by'], 'requested_by' => $actor, 'total_minor' => $clean['total'], 'business_date' => $this->businessDate->current($property)->toString()];

            if (! $this->store->addRequest($property, $row, $clean['lines'], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('A request with this number already exists. Try again.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'purchase_request.created', 'purchase_request', $id, null, ['number' => $number, 'department' => $clean['department'], 'urgency' => $clean['urgency'], 'total_minor' => $clean['total'], 'lines' => count($clean['lines'])]));
        });

        return $this->show($property, $actorId, $id);
    }

    /**
     * @param  list<array{item_id: string, unit: string, quantity: string, est_cost_minor?: int|null, note?: string|null}>  $lines
     * @return array<string, mixed>
     */
    public function update(PropertyId $property, string $actorId, string $id, string $department, string $urgency, string $reason, string $neededBy, array $lines, int $lock): array
    {
        $request = $this->own($property, $actorId, $id, ['draft']);
        $clean = $this->clean($property, $department, $urgency, $reason, $neededBy, $lines);
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $request, $clean, $lock): void {
            if (! $this->store->updateRequest($property, $request['id'], $lock, ['department' => $clean['department'], 'urgency' => $clean['urgency'], 'reason' => $clean['reason'], 'needed_by' => $clean['needed_by'], 'total_minor' => $clean['total']], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This request changed after you opened it.');
            }

            $this->store->replaceRequestLines($request['id'], $clean['lines']);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'purchase_request.updated', 'purchase_request', $request['id'], ['total_minor' => (int) $request['total_minor']], ['total_minor' => $clean['total'], 'lines' => count($clean['lines'])]));
        });

        return $this->show($property, $actorId, $request['id']);
    }

    /** Hands the request in. Its value decides whether and by whom it is approved. @return array<string, mixed> */
    public function submit(PropertyId $property, string $actorId, string $id, int $lock): array
    {
        $request = $this->own($property, $actorId, $id, ['draft']);

        if ($request['lines'] === []) {
            throw Refusal::invalid('A request needs at least one line.', ['lines']);
        }

        $actor = strtolower($actorId);
        $period = substr((string) $request['needed_by'], 0, 7);
        $warning = null;
        $total = (int) $request['total_minor'];

        $this->transactions->run(function () use ($property, $actor, $request, $lock, $period, $total, &$warning): void {
            $warning = $this->settings->enforce($property, $request['department'], $period, $total);
            $at = $this->clock->nowUtc();
            $required = $this->approvals->requirementFor($property, self::SUBJECT, $total)->required;
            $approvalId = null;

            if ($required) {
                $view = $this->approvals->request(new ApprovalRequestInput(
                    $property, self::SUBJECT, $request['id'], $actor, $request['reason'], $this->payload($request),
                    ['number' => $request['number'], 'department' => $request['department']], $total, $this->currency->currencyOf($property),
                ), IdempotencyKey::fromString('pr-submit-'.$request['id'].'-'.$lock));
                $approvalId = $view->id;
            }

            if (! $this->store->updateRequest($property, $request['id'], $lock, ['status' => $required ? 'pending_approval' : 'approved', 'approval_id' => $approvalId, 'submitted_at' => $at], $at)) {
                throw Refusal::stateConflict('This request changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'purchase_request.submitted', 'purchase_request', $request['id'], ['status' => 'draft'], ['status' => $required ? 'pending_approval' : 'approved', 'number' => $request['number'], 'total_minor' => $total], null, $approvalId));
            $this->outbox->publish(new OutboxEvent($property, 'purchasing.request.submitted', $request['id'], 1, ['request_id' => $request['id'], 'number' => $request['number'], 'total_minor' => $total, 'approval_required' => $required, 'actor_id' => $actor]));
        });

        return [...$this->show($property, $actorId, $request['id']), 'budget_warning' => $warning];
    }

    /**
     * Takes the decision of the approvers: an approved request is used once and the request becomes approved; a rejected one is closed with the reason.
     * While they have not decided, nothing changes.
     *
     * @return array<string, mixed>
     */
    public function release(PropertyId $property, string $actorId, string $id): array
    {
        $request = $this->own($property, $actorId, $id, ['pending_approval']);
        $view = $this->approvals->find($property, (string) $request['approval_id']) ?? throw Refusal::notFound('Approval not found.');
        $actor = strtolower($actorId);

        if ($view->status === 'rejected' || $view->status === 'cancelled' || $view->status === 'expired') {
            $note = null;

            foreach ($view->decisions as $d) {
                $note = $d['reason'] ?? $note;
            }

            $this->transactions->run(function () use ($property, $actor, $request, $note): void {
                if (! $this->store->updateRequest($property, $request['id'], (int) $request['lock_version'], ['status' => 'rejected', 'decision_note' => $note === null ? null : mb_substr($note, 0, 200)], $this->clock->nowUtc())) {
                    throw Refusal::stateConflict('This request changed meanwhile.');
                }

                $this->audit->record(new AuditEntry($property->toString(), $actor, 'purchase_request.rejected', 'purchase_request', $request['id'], ['status' => 'pending_approval'], ['status' => 'rejected', 'number' => $request['number']], $note, $request['approval_id']));
            });
        } elseif ($view->isApproved() && ! $view->consumed) {
            $this->transactions->run(function () use ($property, $actor, $request): void {
                $this->approvals->consume($property, (string) $request['approval_id'], self::SUBJECT, $request['id'], $this->payload($request), $actor);

                if (! $this->store->updateRequest($property, $request['id'], (int) $request['lock_version'], ['status' => 'approved'], $this->clock->nowUtc())) {
                    throw Refusal::stateConflict('This request changed meanwhile.');
                }

                $this->audit->record(new AuditEntry($property->toString(), $actor, 'purchase_request.approved', 'purchase_request', $request['id'], ['status' => 'pending_approval'], ['status' => 'approved', 'number' => $request['number']], null, $request['approval_id']));
                $this->outbox->publish(new OutboxEvent($property, 'purchasing.request.approved', $request['id'], 1, ['request_id' => $request['id'], 'number' => $request['number'], 'actor_id' => $actor]));
            });
        }

        return $this->show($property, $actorId, $request['id']);
    }

    /** @return array<string, mixed> */
    public function cancel(PropertyId $property, string $actorId, string $id, string $reason, int $lock): array
    {
        $this->access->requireView($property, $actorId);
        $request = $this->store->request($property, strtolower($id)) ?? throw Refusal::notFound('Purchase request not found.');
        $actor = strtolower($actorId);

        if ($request['requested_by'] !== $actor && ! $this->access->may($property, $actorId, PurchasingAccess::ORDER_MANAGE)) {
            throw Refusal::forbidden('Only the requester or Purchasing may cancel a request.');
        }

        if (! in_array($request['status'], ['draft', 'pending_approval', 'approved'], true) || $this->hasOrderedLines($request)) {
            throw Refusal::stateConflict('This request can no longer be cancelled.');
        }

        if (trim($reason) === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('A reason of at most 200 characters is required.', ['reason']);
        }

        $this->transactions->run(function () use ($property, $actor, $request, $reason, $lock): void {
            if (! $this->store->updateRequest($property, $request['id'], $lock, ['status' => 'cancelled', 'decision_note' => trim($reason)], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This request changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'purchase_request.cancelled', 'purchase_request', $request['id'], ['status' => $request['status']], ['status' => 'cancelled', 'number' => $request['number']], trim($reason), $request['approval_id']));
        });

        return $this->show($property, $actorId, $request['id']);
    }

    /** @param array<string, mixed> $request */
    private function hasOrderedLines(array $request): bool
    {
        foreach ($request['lines'] as $l) {
            if ($l['po_id'] !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed> exactly what the approvers see and what a later release must match
     */
    private function payload(array $request): array
    {
        return [
            'number' => $request['number'], 'department' => $request['department'], 'urgency' => $request['urgency'], 'needed_by' => substr((string) $request['needed_by'], 0, 10), 'total_minor' => (int) $request['total_minor'],
            'lines' => array_map(static fn (array $l): array => ['item_id' => $l['item_id'], 'unit' => $l['unit'], 'qty_milli' => (int) $l['qty_milli'], 'est_unit_cost_minor' => (int) $l['est_unit_cost_minor']], $request['lines']),
        ];
    }

    /**
     * @param  list<string>  $states
     * @return array<string, mixed>
     */
    private function own(PropertyId $property, string $actorId, string $id, array $states): array
    {
        $this->access->require($property, $actorId, PurchasingAccess::REQUEST_CREATE, 'This person may not make purchase requests.');
        $request = $this->store->request($property, strtolower($id)) ?? throw Refusal::notFound('Purchase request not found.');

        if ($request['requested_by'] !== strtolower($actorId)) {
            throw Refusal::forbidden('Only the person who made a request may do this.');
        }

        if (! in_array($request['status'], $states, true)) {
            throw Refusal::stateConflict('This request is not in a state where that can be done.');
        }

        return $request;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{department: string, urgency: string, reason: string, needed_by: string, total: int, lines: list<array<string, mixed>>}
     */
    private function clean(PropertyId $property, string $department, string $urgency, string $reason, string $neededBy, array $lines): array
    {
        if (! in_array($department, InventoryCatalogService::DEPARTMENTS, true)) {
            throw Refusal::invalid('Choose the department that needs it.', ['department']);
        }

        if (! in_array($urgency, self::URGENCIES, true)) {
            throw Refusal::invalid('Choose how urgent it is.', ['urgency']);
        }

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Say why it is needed, in at most 200 characters.', ['reason']);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $neededBy) !== 1 || ! checkdate((int) substr($neededBy, 5, 2), (int) substr($neededBy, 8, 2), (int) substr($neededBy, 0, 4))) {
            throw Refusal::invalid('Give the date as year-month-day.', ['needed_by']);
        }

        if ($lines === [] || count($lines) > self::MAX_LINES) {
            throw Refusal::invalid('A request has one to '.self::MAX_LINES.' lines.', ['lines']);
        }

        $out = [];
        $seen = [];
        $total = 0;

        foreach ($lines as $line) {
            $item = $this->inventory->item($property, strtolower((string) ($line['item_id'] ?? ''))) ?? throw Refusal::invalid('Choose an item for every line.', ['lines']);

            if (! (bool) $item['is_active']) {
                throw Refusal::stateConflict('Item '.$item['code'].' is inactive.');
            }

            $unit = strtoupper(trim((string) ($line['unit'] ?? '')));

            if ($unit !== $item['base_unit'] && $this->inventory->currentUnit($property, $item['id'], $unit) === null) {
                throw Refusal::invalid('Item '.$item['code'].' has no conversion for the unit '.$unit.'.', ['lines']);
            }

            if (isset($seen[$item['id'].'|'.$unit])) {
                throw Refusal::invalid('An item appears once in a request, in one unit.', ['lines']);
            }

            $seen[$item['id'].'|'.$unit] = true;
            $qty = StockQuantity::parse((string) ($line['quantity'] ?? ''));

            if ($qty === null || $qty < 1 || $qty > StockQuantity::MAX_MILLI) {
                throw Refusal::invalid('Give every quantity as a number above zero, with at most three decimals.', ['lines']);
            }

            $cost = (int) ($line['est_cost_minor'] ?? 0);

            if ($cost < 0 || $cost > StockValue::MAX_UNIT_COST_MINOR) {
                throw Refusal::invalid('Give every estimated cost as a whole amount of at most 100,000,000.', ['lines']);
            }

            $note = isset($line['note']) && trim((string) $line['note']) !== '' ? trim((string) $line['note']) : null;

            if ($note !== null && mb_strlen($note) > 200) {
                throw Refusal::invalid('A note is at most 200 characters.', ['lines']);
            }

            $total += StockValue::ofQuantity($qty, $cost);
            $out[] = ['id' => $this->ids->next(), 'item_id' => $item['id'], 'unit' => $unit, 'qty_milli' => $qty, 'est_unit_cost_minor' => $cost, 'note' => $note];
        }

        return ['department' => $department, 'urgency' => $urgency, 'reason' => $reason, 'needed_by' => $neededBy, 'total' => $total, 'lines' => $out];
    }

    /**
     * @param  array<string, mixed>  $r
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    private function head(array $r, array $names): array
    {
        $utc = static fn (mixed $v): ?string => $v === null ? null : (new DateTimeImmutable((string) $v, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        return [
            'id' => $r['id'], 'number' => $r['number'], 'department' => $r['department'], 'urgency' => $r['urgency'], 'reason' => $r['reason'], 'needed_by' => substr((string) $r['needed_by'], 0, 10), 'status' => $r['status'],
            'total_minor' => (int) $r['total_minor'], 'decision_note' => $r['decision_note'], 'requested_by_name' => $names[$r['requested_by']] ?? null, 'submitted_at' => $utc($r['submitted_at']), 'business_date' => substr((string) $r['business_date'], 0, 10),
            'line_count' => (int) ($r['line_count'] ?? 0), 'ordered_count' => (int) ($r['ordered_count'] ?? 0), 'lock_version' => (int) $r['lock_version'],
        ];
    }

    /** @return list<array<string, mixed>> active items with their units and the lowest supplier price in force today, for a first estimate */
    private function itemChoices(PropertyId $property): array
    {
        $units = [];

        foreach ($this->inventory->unitVersions($property) as $u) {
            $units[$u['item_id']][$u['unit']] = $u['unit'];
        }

        $latest = [];

        foreach ($this->store->startedPrices($property, $this->businessDate->current($property)->toString()) as $p) {
            $latest[$p['supplier_id'].'|'.$p['item_id'].'|'.$p['unit']] ??= $p;
        }

        $lowest = [];

        foreach ($latest as $p) {
            $key = $p['item_id'].'|'.$p['unit'];
            $price = (int) $p['unit_price_minor'];
            $lowest[$key] = isset($lowest[$key]) ? min($lowest[$key], $price) : $price;
        }

        return array_values(array_map(static function (array $i) use ($units, $lowest): array {
            $all = [$i['base_unit'], ...array_values($units[$i['id']] ?? [])];

            return ['id' => $i['id'], 'code' => $i['code'], 'name' => $i['name'], 'base_unit' => $i['base_unit'], 'units' => $all, 'suggested_cost_minor' => array_combine($all, array_map(static fn (string $u): ?int => $lowest[$i['id'].'|'.$u] ?? null, $all))];
        }, array_filter($this->inventory->items($property), static fn (array $i): bool => (bool) $i['is_active'])));
    }
}
