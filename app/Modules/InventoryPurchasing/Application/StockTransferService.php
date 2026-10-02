<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Modules\InventoryPurchasing\Domain\StockQuantity;
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
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Moving stock between two locations with a hand-over document (FR-INV-005). The sender records what leaves and for where; the receiver, a different
 * person, confirms that it arrived. Only then do the two locations change, once, in one transaction: the source loses what the destination gains, so
 * the property's total stays the same. The receiver can also reject the hand-over with a reason, and the sender can cancel it, and neither moves
 * any stock. A transfer never takes the source below zero; the lines keep the unit and factor of the day the document was made.
 */
final readonly class StockTransferService
{
    public const SEND_PERMISSION = 'inventory.transfer.send';

    public const RECEIVE_PERMISSION = 'inventory.transfer.receive';

    public const MAX_LINES = 30;

    public function __construct(
        private InventoryStore $inventory,
        private StockPoster $poster,
        private BusinessDateProvider $businessDate,
        private DocumentNumbers $numbers,
        private PermissionChecker $permissions,
        private StaffDirectory $staff,
        private TransactionRunner $transactions,
        private IdempotentExecutor $executor,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    /**
     * @return array{transfers: list<array<string, mixed>>, may: array{send: bool, receive: bool}}
     */
    public function overview(PropertyId $property, string $actorId, ?string $status): array
    {
        $this->assertProperty($property);
        $may = ['send' => $this->may($property, $actorId, self::SEND_PERMISSION), 'receive' => $this->may($property, $actorId, self::RECEIVE_PERMISSION)];

        if (! $may['send'] && ! $may['receive'] && ! $this->may($property, $actorId, StockService::VIEW_PERMISSION)) {
            throw Refusal::forbidden('This person may not see stock transfers.');
        }

        if ($status !== null && $status !== '' && ! in_array($status, ['sent', 'received', 'rejected', 'cancelled'], true)) {
            throw Refusal::invalid('Choose sent, received, rejected or cancelled.', ['status']);
        }

        $transfers = $this->inventory->transfers($property, $status === '' ? null : $status, 200);
        $items = array_column($this->inventory->items($property), null, 'id');
        $locations = array_column($this->inventory->locations($property), null, 'id');
        $names = $this->staff->namesOf($property, array_values(array_unique(array_filter(array_merge(array_column($transfers, 'created_by'), array_column($transfers, 'decided_by'))))));
        $actor = strtolower($actorId);
        $utc = static fn (mixed $v): ?string => $v === null ? null : (new DateTimeImmutable((string) $v, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        return [
            'transfers' => array_map(fn (array $t): array => [
                'id' => $t['id'], 'number' => $t['number'], 'status' => $t['status'], 'note' => $t['note'], 'decision_note' => $t['decision_note'], 'business_date' => substr((string) $t['business_date'], 0, 10),
                'from' => ['id' => $t['from_location_id'], 'code' => $locations[$t['from_location_id']]['code'] ?? '', 'name' => $locations[$t['from_location_id']]['name'] ?? ''],
                'to' => ['id' => $t['to_location_id'], 'code' => $locations[$t['to_location_id']]['code'] ?? '', 'name' => $locations[$t['to_location_id']]['name'] ?? ''],
                'created_by_name' => $names[$t['created_by']] ?? null, 'decided_by_name' => $t['decided_by'] === null ? null : ($names[$t['decided_by']] ?? null), 'created_at' => $utc($t['created_at']), 'decided_at' => $utc($t['decided_at']),
                'lock_version' => (int) $t['lock_version'],
                'may_receive' => $may['receive'] && $t['status'] === 'sent' && $t['created_by'] !== $actor,
                'may_cancel' => $may['send'] && $t['status'] === 'sent' && $t['created_by'] === $actor,
                'lines' => array_map(static fn (array $l): array => [
                    'item_code' => $items[$l['item_id']]['code'] ?? '', 'item_name' => $items[$l['item_id']]['name'] ?? '', 'unit' => $l['unit'], 'unit_qty_milli' => (int) $l['unit_qty_milli'],
                    'base_qty_milli' => (int) $l['base_qty_milli'], 'base_unit' => $items[$l['item_id']]['base_unit'] ?? '',
                ], $t['lines']),
            ], $transfers),
            'may' => $may,
        ];
    }

    /**
     * @param  list<array{item_id: string, unit: string, quantity: string}>  $lines
     * @return array<string, mixed>
     */
    public function send(PropertyId $property, string $actorId, string $fromId, string $toId, array $lines, ?string $note, ?IdempotencyKey $key = null): array
    {
        $this->assertProperty($property);

        if (! $this->may($property, $actorId, self::SEND_PERMISSION)) {
            throw Refusal::forbidden('This person may not send stock between locations.');
        }

        $note = $note === null || trim($note) === '' ? null : trim($note);

        if ($note !== null && mb_strlen($note) > 200) {
            throw Refusal::invalid('The note is at most 200 characters.', ['note']);
        }

        if ($lines === [] || count($lines) > self::MAX_LINES) {
            throw Refusal::invalid('A transfer has one to '.self::MAX_LINES.' lines.', ['lines']);
        }

        $from = $this->inventory->location($property, strtolower($fromId)) ?? throw Refusal::invalid('Choose the location the stock leaves.', ['from_location_id']);
        $to = $this->inventory->location($property, strtolower($toId)) ?? throw Refusal::invalid('Choose the location the stock goes to.', ['to_location_id']);

        if ($from['id'] === $to['id']) {
            throw Refusal::invalid('The two locations must be different.', ['to_location_id']);
        }

        if (! (bool) $from['is_active'] || ! (bool) $to['is_active']) {
            throw Refusal::stateConflict('A transfer needs two active locations.');
        }

        $actor = strtolower($actorId);
        $id = $this->ids->next();

        $operation = function () use ($property, $actor, $id, $from, $to, $lines, $note): void {
            $prepared = [];
            $seen = [];
            $itemIds = array_map(static fn (array $l): string => strtolower((string) ($l['item_id'] ?? '')), $lines);
            sort($itemIds);

            // Items are locked in one order, so two transfers that share items cannot wait for each other.
            foreach ($itemIds as $itemId) {
                $this->inventory->lockItem($property, $itemId);
            }

            foreach ($lines as $line) {
                $item = $this->inventory->item($property, strtolower((string) ($line['item_id'] ?? ''))) ?? throw Refusal::invalid('Choose an item for every line.', ['lines']);

                if (isset($seen[$item['id']])) {
                    throw Refusal::invalid('An item appears once in a transfer.', ['lines']);
                }

                $seen[$item['id']] = true;

                if (! (bool) $item['is_active']) {
                    throw Refusal::stateConflict('Item '.$item['code'].' is inactive.');
                }

                $qty = StockQuantity::parse((string) ($line['quantity'] ?? ''));

                if ($qty === null || $qty < 1 || $qty > StockQuantity::MAX_MILLI) {
                    throw Refusal::invalid('Give every quantity as a number above zero, with at most three decimals.', ['lines']);
                }

                $unit = strtoupper(trim((string) ($line['unit'] ?? '')));

                if ($unit === $item['base_unit']) {
                    $conversion = null;
                    $factor = 1000;
                } else {
                    $current = $this->inventory->currentUnit($property, $item['id'], $unit) ?? throw Refusal::invalid('Item '.$item['code'].' has no conversion for the unit '.$unit.'.', ['lines']);
                    $conversion = $current['id'];
                    $factor = (int) $current['factor_milli'];
                }

                try {
                    $base = StockQuantity::toBase($qty, $factor);
                } catch (InvalidArgumentException $e) {
                    throw Refusal::invalid($e->getMessage(), ['lines']);
                }

                if ($base < 1) {
                    throw Refusal::invalid('A quantity is less than 0.001 of the base unit.', ['lines']);
                }

                if ($this->inventory->balanceOf($property, $item['id'], $from['id']) < $base) {
                    throw Refusal::stateConflict('There is not enough of '.$item['code'].' in '.$from['code'].' to send.');
                }

                $prepared[] = ['id' => $this->ids->next(), 'item_id' => $item['id'], 'unit' => $unit, 'unit_qty_milli' => $qty, 'conversion_id' => $conversion, 'factor_milli' => $factor, 'base_qty_milli' => $base, 'code' => $item['code']];
            }

            $number = $this->numbers->next($property, 'TRF');
            $date = $this->businessDate->current($property)->toString();

            if (! $this->inventory->addTransfer($property, ['id' => $id, 'number' => $number, 'from_location_id' => $from['id'], 'to_location_id' => $to['id'], 'note' => $note, 'created_by' => $actor, 'business_date' => $date], array_map(static fn (array $l): array => array_diff_key($l, ['code' => 0]), $prepared), $this->clock->nowUtc())) {
                throw Refusal::stateConflict('A transfer with this number already exists. Try again.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'stock_transfer.sent', 'stock_transfer', $id, null, ['number' => $number, 'from' => $from['code'], 'to' => $to['code'], 'lines' => array_map(static fn (array $l): array => ['item' => $l['code'], 'unit' => $l['unit'], 'unit_qty_milli' => $l['unit_qty_milli'], 'base_qty_milli' => $l['base_qty_milli']], $prepared)]));
            $this->outbox->publish(new OutboxEvent($property, 'inventory.transfer.sent', $id, 1, ['transfer_id' => $id, 'number' => $number, 'actor_id' => $actor]));
        };

        if ($key === null) {
            $this->transactions->run($operation);
        } else {
            $once = $this->executor->execute(
                new IdempotencyRequest($property, $key, 'inventory.transfer.send', ['from' => $from['id'], 'to' => $to['id'], 'note' => $note, 'lines' => $lines], $actor),
                function () use ($operation, $id): array {
                    $operation();

                    return ['id' => $id];
                },
            );
            $id = (string) $once->payload['id'];
        }

        return $this->inventory->transfer($property, $id) ?? throw Refusal::notFound('Transfer not found.');
    }

    /** Confirms the hand-over: the source loses and the destination gains what the document says, once. @return array<string, mixed> */
    public function receive(PropertyId $property, string $actorId, string $transferId, int $lock): array
    {
        $this->assertProperty($property);

        if (! $this->may($property, $actorId, self::RECEIVE_PERMISSION)) {
            throw Refusal::forbidden('This person may not confirm the receipt of a transfer.');
        }

        $transfer = $this->inventory->transfer($property, strtolower($transferId)) ?? throw Refusal::notFound('Transfer not found.');
        $actor = strtolower($actorId);

        if ($transfer['created_by'] === $actor) {
            throw Refusal::forbidden('The person who sent a transfer cannot confirm its receipt.');
        }

        $items = array_column($this->inventory->items($property), null, 'id');
        $from = $this->inventory->location($property, $transfer['from_location_id']) ?? throw Refusal::notFound('Location not found.');
        $to = $this->inventory->location($property, $transfer['to_location_id']) ?? throw Refusal::notFound('Location not found.');

        $this->transactions->run(function () use ($property, $actor, $transfer, $lock, $items, $from, $to): void {
            if (! $this->inventory->decideTransfer($property, $transfer['id'], $lock, 'received', $actor, null, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This transfer changed or was already decided.');
            }

            $lines = $transfer['lines'];
            usort($lines, static fn (array $a, array $b): int => strcmp($a['item_id'], $b['item_id']));

            foreach ($lines as $l) {
                $item = $items[$l['item_id']] ?? throw Refusal::notFound('Item not found.');
                $snapshot = ['conversion_id' => $l['conversion_id'], 'factor_milli' => (int) $l['factor_milli']];
                $this->poster->post($property, $actor, $item, $from, 'transfer_out', $l['unit'], (int) $l['unit_qty_milli'], null, $transfer['number'], null, 'transfer', $transfer['id'], $transfer['id'], null, false, $snapshot);
                $this->poster->post($property, $actor, $item, $to, 'transfer_in', $l['unit'], (int) $l['unit_qty_milli'], null, $transfer['number'], null, 'transfer', $transfer['id'], $transfer['id'], null, false, $snapshot);
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'stock_transfer.received', 'stock_transfer', $transfer['id'], ['status' => 'sent'], ['status' => 'received', 'number' => $transfer['number'], 'from' => $from['code'], 'to' => $to['code']]));
            $this->outbox->publish(new OutboxEvent($property, 'inventory.transfer.received', $transfer['id'], 1, ['transfer_id' => $transfer['id'], 'number' => $transfer['number'], 'actor_id' => $actor]));
        });

        return $this->inventory->transfer($property, $transfer['id']) ?? throw Refusal::notFound('Transfer not found.');
    }

    /** The receiver refuses the hand-over, with a reason; no stock moves. @return array<string, mixed> */
    public function reject(PropertyId $property, string $actorId, string $transferId, string $note, int $lock): array
    {
        $this->assertProperty($property);

        if (! $this->may($property, $actorId, self::RECEIVE_PERMISSION)) {
            throw Refusal::forbidden('This person may not reject a transfer.');
        }

        if (trim($note) === '' || mb_strlen($note) > 200) {
            throw Refusal::invalid('A reason of at most 200 characters is required.', ['note']);
        }

        $transfer = $this->inventory->transfer($property, strtolower($transferId)) ?? throw Refusal::notFound('Transfer not found.');

        if ($transfer['created_by'] === strtolower($actorId)) {
            throw Refusal::forbidden('The person who sent a transfer cannot reject it; cancel it instead.');
        }

        return $this->decide($property, $actorId, $transfer, 'rejected', trim($note), $lock);
    }

    /** The sender takes the document back before it is received. @return array<string, mixed> */
    public function cancel(PropertyId $property, string $actorId, string $transferId, int $lock): array
    {
        $this->assertProperty($property);
        $transfer = $this->inventory->transfer($property, strtolower($transferId)) ?? throw Refusal::notFound('Transfer not found.');

        if (! $this->may($property, $actorId, self::SEND_PERMISSION) || $transfer['created_by'] !== strtolower($actorId)) {
            throw Refusal::forbidden('Only the person who sent a transfer may cancel it.');
        }

        return $this->decide($property, $actorId, $transfer, 'cancelled', null, $lock);
    }

    /**
     * @param  array<string, mixed>  $transfer
     * @return array<string, mixed>
     */
    private function decide(PropertyId $property, string $actorId, array $transfer, string $status, ?string $note, int $lock): array
    {
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $transfer, $status, $note, $lock): void {
            if (! $this->inventory->decideTransfer($property, $transfer['id'], $lock, $status, $actor, $note, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This transfer changed or was already decided.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'stock_transfer.'.$status, 'stock_transfer', $transfer['id'], ['status' => 'sent'], ['status' => $status, 'number' => $transfer['number']], $note));
            $this->outbox->publish(new OutboxEvent($property, 'inventory.transfer.'.$status, $transfer['id'], 1, ['transfer_id' => $transfer['id'], 'number' => $transfer['number'], 'actor_id' => $actor]));
        });

        return $this->inventory->transfer($property, $transfer['id']) ?? throw Refusal::notFound('Transfer not found.');
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
