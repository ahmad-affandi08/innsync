<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Modules\InventoryPurchasing\Domain\StockQuantity;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Documents\DocumentNumbers;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;

/**
 * What a department asks of the main store (FR-FBS-030): the store of an outlet (the bar, the kitchen) says which items and how many it needs from the main store. The main store answers by
 * sending a transfer from itself to the outlet, with the quantities it can spare (which the outlet then receives like any transfer), or by refusing with a reason. The requester may withdraw
 * a request that is still waiting. The stock moves only through the transfer.
 */
final readonly class StockRequisitionService
{
    public const REQUEST_PERMISSION = 'inventory.requisition.request';

    public const MAX_LINES = 30;

    public function __construct(
        private RequisitionStore $store,
        private InventoryStore $inventory,
        private StockTransferService $transfers,
        private DocumentNumbers $numbers,
        private PermissionChecker $permissions,
        private StaffDirectory $staff,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId, ?string $status): array
    {
        $this->assertProperty($property);
        $may = ['request' => $this->may($property, $actorId, self::REQUEST_PERMISSION), 'fulfil' => $this->may($property, $actorId, StockTransferService::SEND_PERMISSION)];

        if (! $may['request'] && ! $may['fulfil'] && ! $this->may($property, $actorId, StockService::VIEW_PERMISSION)) {
            throw Refusal::forbidden('This person may not see requisitions.');
        }

        if ($status !== null && $status !== '' && ! in_array($status, ['requested', 'fulfilled', 'rejected', 'cancelled'], true)) {
            throw Refusal::invalid('Choose requested, fulfilled, rejected or cancelled.', ['status']);
        }

        $locations = array_column($this->inventory->locations($property), null, 'id');
        $rows = $this->store->list($property, $status === '' ? null : $status, 200);
        $names = $this->staff->namesOf($property, array_values(array_unique(array_filter(array_merge(array_column($rows, 'requested_by'), array_column($rows, 'decided_by'))))));

        return [
            'requisitions' => array_map(fn (array $r): array => $this->shape($property, $actorId, $r, $locations, $names), $rows),
            'locations' => array_values(array_map(static fn (array $l): array => ['id' => $l['id'], 'code' => $l['code'], 'name' => $l['name'], 'kind' => $l['kind']], array_filter($locations, static fn (array $l): bool => (bool) $l['is_active']))),
            'items' => array_values(array_map(static fn (array $i): array => ['id' => $i['id'], 'code' => $i['code'], 'name' => $i['name'], 'base_unit' => $i['base_unit']], array_filter($this->inventory->items($property), static fn (array $i): bool => (bool) $i['is_active']))),
            'may' => $may,
        ];
    }

    /**
     * @param  list<array{item_id?: string, unit?: string, quantity?: string}>  $lines
     * @return array<string, mixed>
     */
    public function request(PropertyId $property, string $actorId, string $requestingId, string $supplyingId, array $lines, ?string $note): array
    {
        $this->assertProperty($property);

        if (! $this->may($property, $actorId, self::REQUEST_PERMISSION)) {
            throw Refusal::forbidden('This person may not ask the main store for goods.');
        }

        $note = $note === null || trim($note) === '' ? null : trim($note);

        if ($note !== null && mb_strlen($note) > 200) {
            throw Refusal::invalid('The note is at most 200 characters.', ['note']);
        }

        if ($lines === [] || count($lines) > self::MAX_LINES) {
            throw Refusal::invalid('A requisition has one to '.self::MAX_LINES.' lines.', ['lines']);
        }

        $requesting = $this->inventory->location($property, strtolower($requestingId)) ?? throw Refusal::invalid('Choose the store that needs the goods.', ['requesting_location_id']);
        $supplying = $this->inventory->location($property, strtolower($supplyingId)) ?? throw Refusal::invalid('Choose the store that gives the goods.', ['supplying_location_id']);

        if ($requesting['id'] === $supplying['id']) {
            throw Refusal::invalid('The two stores must be different.', ['supplying_location_id']);
        }

        if (! (bool) $requesting['is_active'] || ! (bool) $supplying['is_active']) {
            throw Refusal::stateConflict('A requisition needs two active stores.');
        }

        $clean = [];
        $seen = [];

        foreach ($lines as $line) {
            $item = $this->inventory->item($property, strtolower((string) ($line['item_id'] ?? ''))) ?? throw Refusal::invalid('Choose an item for every line.', ['lines']);

            if (! (bool) $item['is_active'] || isset($seen[$item['id']])) {
                throw Refusal::invalid('Every line has a different item that is in use.', ['lines']);
            }

            $seen[$item['id']] = true;
            $unit = strtoupper(trim((string) ($line['unit'] ?? '')));

            if ($unit !== $item['base_unit'] && $this->inventory->currentUnit($property, $item['id'], $unit) === null) {
                throw Refusal::invalid("{$item['code']} has no conversion for the unit {$unit}.", ['lines']);
            }

            $qty = StockQuantity::parse((string) ($line['quantity'] ?? ''));

            if ($qty === null || $qty < 1 || $qty > StockQuantity::MAX_MILLI) {
                throw Refusal::invalid('Give every quantity above zero, with at most three decimals.', ['lines']);
            }

            $clean[] = ['id' => $this->ids->next(), 'item_id' => $item['id'], 'unit' => $unit, 'quantity_milli' => $qty];
        }

        $actor = strtolower($actorId);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actor, $id, $requesting, $supplying, $clean, $note): void {
            $number = $this->numbers->next($property, 'RQ');

            if (! $this->store->add($property, ['id' => $id, 'number' => $number, 'requesting_location_id' => $requesting['id'], 'supplying_location_id' => $supplying['id'], 'status' => 'requested', 'note' => $note, 'requested_by' => $actor], $clean, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('A requisition with this number exists already. Try again.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'requisition.requested', 'requisition', $id, null, ['number' => $number, 'from' => $requesting['code'], 'to' => $supplying['code'], 'lines' => count($clean)]));
        });

        return $this->one($property, $actorId, $id);
    }

    /**
     * The main store sends what it can spare. `$quantities` maps a line to the quantity sent when it is not the quantity asked (0 leaves the line out).
     *
     * @param  array<string, string>  $quantities
     * @return array<string, mixed>
     */
    public function fulfil(PropertyId $property, string $actorId, string $id, int $lock, array $quantities): array
    {
        $this->assertProperty($property);

        if (! $this->may($property, $actorId, StockTransferService::SEND_PERMISSION)) {
            throw Refusal::forbidden('This person may not send stock between locations.');
        }

        $r = $this->store->find($property, strtolower($id)) ?? throw Refusal::notFound('Requisition not found.');

        if ($r['status'] !== 'requested') {
            throw Refusal::stateConflict('This requisition is closed already.');
        }

        $send = [];
        $lines = $this->store->lines($property, $r['id']);

        foreach ($lines as $l) {
            $given = $quantities[$l['id']] ?? null;
            $qty = $given === null ? (int) $l['quantity_milli'] : StockQuantity::parse($given);

            if ($qty === null || $qty < 0 || $qty > (int) $l['quantity_milli']) {
                throw Refusal::invalid('Send no more than was asked for, with at most three decimals.', ['quantities']);
            }

            if ($qty > 0) {
                $send[] = ['item_id' => $l['item_id'], 'unit' => $l['unit'], 'quantity' => StockQuantity::format($qty)];
            }
        }

        if ($send === []) {
            throw Refusal::invalid('Send at least one line, or refuse the requisition.', ['quantities']);
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $r, $lock, $send): void {
            $transfer = $this->transfers->send($property, $actor, $r['supplying_location_id'], $r['requesting_location_id'], $send, "Requisition {$r['number']}", IdempotencyKey::fromString('requisition-'.$r['id']));

            if (! $this->store->update($property, $r['id'], $lock, ['status' => 'fulfilled', 'decided_by' => $actor, 'decided_at' => $this->clock->nowUtc()->format('Y-m-d H:i:s.u'), 'transfer_id' => $transfer['id']], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This requisition changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'requisition.fulfilled', 'requisition', $r['id'], ['status' => 'requested'], ['status' => 'fulfilled', 'number' => $r['number'], 'transfer' => $transfer['number'] ?? null, 'lines' => count($send)]));
        });

        return $this->one($property, $actorId, $r['id']);
    }

    /** @return array<string, mixed> */
    public function reject(PropertyId $property, string $actorId, string $id, string $note, int $lock): array
    {
        $this->assertProperty($property);

        if (! $this->may($property, $actorId, StockTransferService::SEND_PERMISSION)) {
            throw Refusal::forbidden('This person may not send stock between locations.');
        }

        $note = trim($note);

        if ($note === '' || mb_strlen($note) > 200) {
            throw Refusal::invalid('Say why, in at most 200 characters.', ['note']);
        }

        return $this->close($property, $actorId, $id, $lock, 'rejected', $note);
    }

    /** @return array<string, mixed> */
    public function cancel(PropertyId $property, string $actorId, string $id, int $lock): array
    {
        $this->assertProperty($property);
        $r = $this->store->find($property, strtolower($id)) ?? throw Refusal::notFound('Requisition not found.');

        if ($r['requested_by'] !== strtolower($actorId)) {
            throw Refusal::forbidden('Only the person who asked may withdraw the requisition.');
        }

        return $this->close($property, $actorId, $id, $lock, 'cancelled', null);
    }

    /** @return array<string, mixed> */
    private function close(PropertyId $property, string $actorId, string $id, int $lock, string $status, ?string $note): array
    {
        $r = $this->store->find($property, strtolower($id)) ?? throw Refusal::notFound('Requisition not found.');
        $actor = strtolower($actorId);

        if ($r['status'] !== 'requested') {
            throw Refusal::stateConflict('This requisition is closed already.');
        }

        $this->transactions->run(function () use ($property, $actor, $r, $lock, $status, $note): void {
            if (! $this->store->update($property, $r['id'], $lock, ['status' => $status, 'decided_by' => $actor, 'decided_at' => $this->clock->nowUtc()->format('Y-m-d H:i:s.u'), 'decision_note' => $note], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This requisition changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'requisition.'.$status, 'requisition', $r['id'], ['status' => 'requested'], ['status' => $status, 'number' => $r['number']], $note));
        });

        return $this->one($property, $actorId, $r['id']);
    }

    /**
     * @param  array<string, mixed>  $r
     * @param  array<string, array<string, mixed>>  $locations
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    private function shape(PropertyId $property, string $actorId, array $r, array $locations, array $names): array
    {
        $utc = static fn (mixed $v): ?string => $v === null ? null : (new DateTimeImmutable((string) $v, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $open = $r['status'] === 'requested';

        return [
            'id' => $r['id'], 'number' => $r['number'], 'status' => $r['status'], 'note' => $r['note'], 'decision_note' => $r['decision_note'], 'transfer_id' => $r['transfer_id'], 'lock_version' => (int) $r['lock_version'],
            'requesting' => $locations[$r['requesting_location_id']]['name'] ?? '', 'supplying' => $locations[$r['supplying_location_id']]['name'] ?? '', 'requested_by' => $names[$r['requested_by']] ?? null, 'decided_by' => $names[$r['decided_by']] ?? null,
            'created_at' => $utc($r['created_at']), 'decided_at' => $utc($r['decided_at']),
            'lines' => array_map(static fn (array $l): array => ['id' => $l['id'], 'item_code' => $l['item_code'], 'item_name' => $l['item_name'], 'unit' => $l['unit'], 'quantity_milli' => (int) $l['quantity_milli']], $this->store->lines($property, $r['id'])),
            'may' => ['fulfil' => $open && $this->may($property, $actorId, StockTransferService::SEND_PERMISSION), 'cancel' => $open && $r['requested_by'] === strtolower($actorId)],
        ];
    }

    /** @return array<string, mixed> */
    private function one(PropertyId $property, string $actorId, string $id): array
    {
        $r = $this->store->find($property, $id) ?? throw Refusal::notFound('Requisition not found.');
        $locations = array_column($this->inventory->locations($property), null, 'id');

        return $this->shape($property, $actorId, $r, $locations, $this->staff->namesOf($property, array_values(array_filter([$r['requested_by'], $r['decided_by']]))));
    }

    private function may(PropertyId $property, string $actorId, string $permission): bool
    {
        return $this->permissions->allowsInProperty($actorId, $permission, $property);
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
