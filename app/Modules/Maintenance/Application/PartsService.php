<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Application;

use App\Modules\InventoryPurchasing\Application\IngredientCatalog;
use App\Modules\InventoryPurchasing\Application\MaintenancePartConsumer;
use App\Modules\InventoryPurchasing\Application\PurchaseRequesting;
use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
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
 * The spare parts of a work order (FR-MTC-009, -010). The technician who works a work order (or a manager) records the part that went into it: which item of the inventory, from which
 * location, how much; the work order keeps it with its cost at the average of the moment, and the stock is taken out through the inventory by an event. A part that is not on hand is
 * refused, with what is there. When the part is missing, the same people start a purchase request from the work order: it is a draft in purchasing, of the person who asked, and the
 * work order shows where it stands.
 */
final readonly class PartsService
{
    private const OPEN = ['open', 'assigned', 'in_progress', 'on_hold'];

    private const USE_STATUSES = ['in_progress', 'on_hold'];

    private const MAX_REQUEST_LINES = 20;

    public function __construct(
        private PartsStore $parts,
        private WorkOrderStore $orders,
        private MaintenanceAccess $access,
        private IngredientCatalog $catalog,
        private PurchaseRequesting $purchasing,
        private PropertyCurrencyReader $currencies,
        private BusinessDateProvider $businessDate,
        private StaffDirectory $staff,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> the parts of one work order: used, requested, and what the actor may do */
    public function panel(PropertyId $property, string $actorId, string $workOrderId): array
    {
        $w = $this->visible($property, $actorId, $workOrderId);
        $mayWork = $this->mayWork($property, $actorId, $w);
        $uses = $this->parts->usesOf($property, $w['id']);
        $requests = $this->parts->requestsOf($property, $w['id']);
        $status = $this->purchasing->statusOf($property, array_column($requests, 'purchase_request_id'));
        $names = $this->staff->namesOf($property, array_values(array_unique([...array_column($uses, 'used_by'), ...array_column($requests, 'requested_by')])));
        $mayUse = $mayWork && in_array($w['status'], self::USE_STATUSES, true);
        $mayRequest = $mayWork && in_array($w['status'], self::OPEN, true);

        return [
            'currency' => $this->currencies->currencyOf($property),
            'uses' => array_map(fn (array $u): array => [
                'id' => $u['id'], 'item_code' => $u['item_code'], 'item_name' => $u['item_name'], 'unit' => $u['unit'], 'quantity_milli' => (int) $u['quantity_milli'], 'location' => $u['location_name'], 'note' => $u['note'],
                'value_minor' => $u['value_minor'] === null ? null : (int) $u['value_minor'], 'used_on' => substr((string) $u['used_on'], 0, 10), 'by' => $names[$u['used_by']] ?? null, 'at' => $this->utc($u['created_at']),
            ], $uses),
            'total_minor' => array_sum(array_map(static fn (array $u): int => (int) $u['value_minor'], $uses)), 'complete' => ! in_array(null, array_column($uses, 'value_minor'), true),
            'requests' => array_map(fn (array $r): array => [
                'id' => $r['purchase_request_id'], 'number' => $r['purchase_request_number'], 'status' => $status[$r['purchase_request_id']]['status'] ?? null, 'total_minor' => $status[$r['purchase_request_id']]['total_minor'] ?? null,
                'by' => $names[$r['requested_by']] ?? null, 'at' => $this->utc($r['created_at']),
            ], $requests),
            'items' => $mayUse || $mayRequest ? $this->catalog->items($property) : [],
            'locations' => $mayUse ? $this->catalog->locations($property) : [],
            'urgencies' => ['low', 'normal', 'high', 'urgent'], 'business_date' => $this->businessDate->current($property)->toString(),
            'may' => ['use' => $mayUse, 'request' => $mayRequest],
        ];
    }

    /** @return array<string, mixed> the panel after the part */
    public function use(PropertyId $property, string $actorId, string $workOrderId, string $itemId, string $locationId, string $unit, int $quantityMilli, ?string $note): array
    {
        $w = $this->visible($property, $actorId, $workOrderId);
        $this->requireWork($property, $actorId, $w);

        if (! in_array($w['status'], self::USE_STATUSES, true)) {
            throw Refusal::stateConflict('Parts are recorded while the work is in progress or on hold.');
        }

        $item = $this->catalog->item($property, $itemId) ?? throw Refusal::invalid('Choose an item that is in use in the inventory.', ['item_id']);
        $unit = strtoupper(trim($unit));

        if (! in_array($unit, $item['units'], true)) {
            throw Refusal::invalid($item['name'].' is not counted in '.$unit.'.', ['unit']);
        }

        $location = null;

        foreach ($this->catalog->locations($property) as $l) {
            if ($l['id'] === strtolower($locationId)) {
                $location = $l;
            }
        }

        $location ?? throw Refusal::invalid('Choose a location of the inventory that is in use.', ['location_id']);

        if ($quantityMilli < 1 || $quantityMilli > 100_000_000) {
            throw Refusal::invalid('Give the quantity above zero, with at most three decimals.', ['quantity']);
        }

        $note = $this->text($note, 200, 'note');
        $onHand = $this->catalog->onHand($property, $item['id'], $location['id'], $unit) ?? 0;

        if ($onHand < $quantityMilli) {
            throw Refusal::stateConflict('Only '.rtrim(rtrim(number_format($onHand / 1000, 3, '.', ''), '0'), '.').' '.$unit.' of '.$item['name'].' is on hand at '.$location['name'].'. Choose another location or ask for more.');
        }

        $actor = strtolower($actorId);
        $id = $this->ids->next();
        $now = $this->clock->nowUtc();
        $today = $this->businessDate->current($property)->toString();
        $value = $this->catalog->valueOf($property, $item['id'], $unit, $quantityMilli);

        $this->transactions->run(function () use ($property, $w, $item, $location, $unit, $quantityMilli, $note, $actor, $id, $now, $today, $value): void {
            $this->orders->lock($property, $w['id']);
            $this->parts->addUse($property, [
                'id' => $id, 'work_order_id' => $w['id'], 'item_id' => $item['id'], 'item_code' => $item['code'], 'item_name' => $item['name'], 'unit' => $unit, 'quantity_milli' => $quantityMilli,
                'location_id' => $location['id'], 'location_name' => $location['name'], 'value_minor' => $value, 'note' => $note, 'used_on' => $today, 'used_by' => $actor,
            ], $now);
            $this->orders->addEvent($property, ['id' => $this->ids->next(), 'work_order_id' => $w['id'], 'kind' => 'part_used', 'note' => mb_substr(rtrim(rtrim(number_format($quantityMilli / 1000, 3, '.', ''), '0'), '.')." {$unit} · {$item['name']}", 0, 300), 'actor_id' => $actor], $now);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'work_order.part_used', 'work_order', $w['id'], null, [
                'number' => $w['number'], 'item' => $item['code'], 'unit' => $unit, 'quantity_milli' => $quantityMilli, 'location' => $location['code'], 'value_minor' => $value,
            ], $note));
            $this->outbox->publish(new OutboxEvent($property, MaintenancePartConsumer::EVENT, $id, 1, [
                'use_id' => $id, 'work_order_id' => $w['id'], 'work_order_number' => $w['number'], 'item_id' => $item['id'], 'location_id' => $location['id'], 'unit' => $unit, 'quantity_milli' => $quantityMilli, 'actor_id' => $actor,
            ]));
        });

        return $this->panel($property, $actorId, $w['id']);
    }

    /**
     * @param  list<array{item_id: string, unit: string, quantity_milli: int, note?: string|null}>  $lines
     * @return array<string, mixed> the panel after the request
     */
    public function request(PropertyId $property, string $actorId, string $workOrderId, string $urgency, string $reason, string $neededBy, array $lines): array
    {
        $w = $this->visible($property, $actorId, $workOrderId);
        $this->requireWork($property, $actorId, $w);

        if (! in_array($w['status'], self::OPEN, true)) {
            throw Refusal::stateConflict('This work order is closed.');
        }

        if ($lines === [] || count($lines) > self::MAX_REQUEST_LINES) {
            throw Refusal::invalid('A request has one to '.self::MAX_REQUEST_LINES.' lines.', ['lines']);
        }

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 160) {
            throw Refusal::invalid('Say what the parts are for, in at most 160 characters.', ['reason']);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $neededBy) !== 1 || $neededBy < $this->businessDate->current($property)->toString()) {
            throw Refusal::invalid('Give the date the parts are needed, from today.', ['needed_by']);
        }

        $clean = [];

        foreach ($lines as $l) {
            $item = $this->catalog->item($property, (string) ($l['item_id'] ?? '')) ?? throw Refusal::invalid('Choose an item that is in use in the inventory for every line.', ['lines']);
            $qty = (int) ($l['quantity_milli'] ?? 0);

            if ($qty < 1 || $qty > 100_000_000) {
                throw Refusal::invalid('Give every quantity above zero, with at most three decimals.', ['lines']);
            }

            $clean[] = ['item_id' => $item['id'], 'unit' => strtoupper(trim((string) ($l['unit'] ?? ''))), 'quantity_milli' => $qty, 'note' => isset($l['note']) ? $this->text((string) $l['note'], 200, 'lines') : null];
        }

        $actor = strtolower($actorId);
        $text = mb_substr("{$w['number']}: {$reason}", 0, 200);
        $draft = $this->purchasing->draft($property, $actor, 'maintenance', $urgency, $text, $neededBy, $clean);
        $now = $this->clock->nowUtc();

        $this->transactions->run(function () use ($property, $w, $draft, $actor, $now, $clean, $urgency): void {
            $this->orders->lock($property, $w['id']);
            $this->parts->addRequest($property, ['id' => $this->ids->next(), 'work_order_id' => $w['id'], 'purchase_request_id' => $draft['id'], 'purchase_request_number' => $draft['number'], 'requested_by' => $actor], $now);
            $this->orders->addEvent($property, ['id' => $this->ids->next(), 'work_order_id' => $w['id'], 'kind' => 'parts_requested', 'note' => $draft['number'], 'actor_id' => $actor], $now);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'work_order.parts_requested', 'work_order', $w['id'], null, ['number' => $w['number'], 'purchase_request' => $draft['number'], 'lines' => count($clean), 'urgency' => $urgency]));
        });

        return $this->panel($property, $actorId, $w['id']);
    }

    /** @return array<string, mixed> */
    private function visible(PropertyId $property, string $actorId, string $id): array
    {
        $this->access->assertProperty($property);
        $actor = strtolower($actorId);
        $manage = $this->access->may($property, $actorId, MaintenanceAccess::MANAGE);
        $row = $this->orders->find($property, strtolower($id)) ?? throw Refusal::notFound('Work order not found.');

        if (! $manage && $row['reported_by'] !== $actor && $row['assigned_to'] !== $actor) {
            throw Refusal::notFound('Work order not found.');
        }

        return $row;
    }

    /** @param array<string, mixed> $w */
    private function mayWork(PropertyId $property, string $actorId, array $w): bool
    {
        return $w['assigned_to'] === strtolower($actorId) || $this->access->may($property, $actorId, MaintenanceAccess::MANAGE);
    }

    /** @param array<string, mixed> $w */
    private function requireWork(PropertyId $property, string $actorId, array $w): void
    {
        if (! $this->mayWork($property, $actorId, $w)) {
            throw Refusal::forbidden('Parts are recorded by the technician the work order was given to, or a manager.');
        }
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

    private function utc(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
