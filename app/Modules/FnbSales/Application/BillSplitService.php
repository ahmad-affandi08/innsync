<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Application;

use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Documents\DocumentNumbers;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Rearranging the open bills of an outlet (FR-FBS-004): a bill moves to another table, two bills become one, or some of what a table ordered is paid apart.
 *
 * Only open bills with no payment on them are split or merged, so nothing that was paid is ever pulled from under a cashier; a bill with payments may still change table.
 * A line keeps the price it was ordered at, its discount and what the station knows of it; a portion split off a line is a line of its own at the same price. The
 * kitchen is told where the lines went so its tickets show the right table and bill.
 */
final readonly class BillSplitService
{
    public const MAX_COVERS = 500;

    public function __construct(
        private BillStore $bills,
        private SetupStore $setup,
        private BillGuard $guard,
        private BillService $service,
        private RoomCatalogReader $rooms,
        private FnbAccess $access,
        private DocumentNumbers $numbers,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array{tables: list<array{id: string, code: string}>, bills: list<array{id: string, number: string, table: string|null, lock_version: int}>} where a bill may go: free tables and the other open bills of its outlet */
    public function targets(PropertyId $property, string $actorId, string $billId): array
    {
        $this->access->require($property, $actorId, FnbAccess::POS_OPERATE, 'This person may not take orders.');
        $bill = $this->bills->bill($property, strtolower($billId)) ?? throw Refusal::notFound('Bill not found.');

        if ($bill['status'] !== 'open') {
            return ['tables' => [], 'bills' => []];
        }

        $open = $this->bills->openBills($property, $bill['outlet_id']);
        $taken = [];
        $codes = [];

        foreach ($open as $b) {
            if ($b['table_id'] !== null) {
                $taken[$b['table_id']] = true;
            }
        }

        $tables = [];

        foreach ($this->setup->tables($property, $bill['outlet_id']) as $t) {
            $codes[$t['id']] = $t['code'];

            if ((bool) $t['is_active'] && ! isset($taken[$t['id']])) {
                $tables[] = ['id' => $t['id'], 'code' => $t['code']];
            }
        }

        $others = [];

        foreach ($open as $b) {
            if ($b['id'] !== $bill['id']) {
                $others[] = ['id' => $b['id'], 'number' => $b['number'], 'table' => $b['table_id'] === null ? null : ($codes[$b['table_id']] ?? null), 'lock_version' => (int) $b['lock_version']];
            }
        }

        return ['tables' => $tables, 'bills' => $others];
    }

    /** Puts the bill on another table of its outlet that has no open bill. @return array<string, mixed> */
    public function moveTable(PropertyId $property, string $actorId, string $billId, int $lock, string $tableId): array
    {
        $this->access->require($property, $actorId, FnbAccess::POS_OPERATE, 'This person may not take orders.');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $billId, $lock, $tableId): void {
            $bill = $this->guard->open($property, strtolower($billId), $lock);
            $table = $this->setup->table($property, strtolower($tableId));

            if ($table === null || $table['outlet_id'] !== $bill['outlet_id'] || ! (bool) $table['is_active']) {
                throw Refusal::invalid('Choose a table of this outlet that is in use.', ['table_id']);
            }

            if ($bill['table_id'] === $table['id']) {
                throw Refusal::invalid('The bill is on this table already.', ['table_id']);
            }

            $from = $bill['table_id'] === null ? null : $this->setup->table($property, $bill['table_id']);

            if (! $this->bills->moveToTable($property, $bill['id'], $table['id'], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This table has an open bill already. Merge the bills instead.');
            }

            $this->guard->touch($property, $bill['id'], $lock);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'fnb_bill.table_moved', 'fnb_bill', $bill['id'], ['table' => $from['code'] ?? null], ['table' => $table['code']]));
            $this->told($property, $actor, $bill, $table['code'], $this->liveLineIds($bill));
        });

        return $this->service->show($property, $actorId, $billId);
    }

    /** Takes every line of the source bill onto the target bill and cancels the source, which stays as a record of the merge. @return array<string, mixed> the target bill */
    public function merge(PropertyId $property, string $actorId, string $sourceId, int $sourceLock, string $targetId, int $targetLock): array
    {
        $this->access->require($property, $actorId, FnbAccess::POS_OPERATE, 'This person may not take orders.');
        $actor = strtolower($actorId);
        $sourceId = strtolower($sourceId);
        $targetId = strtolower($targetId);

        if ($sourceId === $targetId) {
            throw Refusal::invalid('Choose another bill to merge into.', ['target_id']);
        }

        $this->transactions->run(function () use ($property, $actor, $sourceId, $sourceLock, $targetId, $targetLock): void {
            // Locked in a fixed order, so two people merging the same two bills in opposite directions cannot wait on each other.
            $first = strcmp($sourceId, $targetId) < 0;
            $a = $this->guard->open($property, $first ? $sourceId : $targetId, $first ? $sourceLock : $targetLock);
            $b = $this->guard->open($property, $first ? $targetId : $sourceId, $first ? $targetLock : $sourceLock);
            [$source, $target] = $first ? [$a, $b] : [$b, $a];

            if ($source['outlet_id'] !== $target['outlet_id']) {
                throw Refusal::invalid('Only bills of the same outlet are merged.', ['target_id']);
            }

            if ($this->bills->paymentCount($property, $source['id']) > 0 || $this->bills->paymentCount($property, $target['id']) > 0) {
                throw Refusal::stateConflict('A bill with payments is not merged. Refund the payments first.');
            }

            if ($source['room_id'] !== null && $source['room_id'] !== $target['room_id']) {
                throw Refusal::stateConflict('A bill of a room is merged only into a bill of the same room.');
            }

            $live = $this->liveLineIds($source);

            if ($live === []) {
                throw Refusal::stateConflict('This bill has nothing ordered. Cancel it instead.');
            }

            $now = $this->clock->nowUtc();
            $this->bills->moveLines($property, $source['id'], $target['id'], array_map(static fn (array $l): string => $l['id'], $source['lines']), $now);
            $covers = min(self::MAX_COVERS, (int) $target['covers'] + (int) $source['covers']);
            $this->bills->updateBill($property, $target['id'], ['covers' => $covers, 'lock_version' => $targetLock + 1], $now);
            $this->bills->updateBill($property, $source['id'], ['status' => 'cancelled', 'closed_by' => $actor, 'closed_at' => $now, 'cancel_reason' => 'Merged into '.$target['number'], 'merged_into_id' => $target['id'], 'lock_version' => $sourceLock + 1], $now);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'fnb_bill.merged', 'fnb_bill', $target['id'], ['source' => $source['number'], 'target' => $target['number']], ['lines_moved' => count($live), 'covers' => $covers], 'Merged '.$source['number'].' into '.$target['number']));
            $table = $target['table_id'] === null ? null : $this->setup->table($property, $target['table_id']);
            $this->told($property, $actor, $target, $table['code'] ?? null, [...$this->liveLineIds($target), ...$live]);
        });

        return $this->service->show($property, $actorId, $targetId);
    }

    /**
     * Splits some lines, or some portions of a line, off a bill onto a new open bill that is paid apart.
     *
     * @param  list<array{line_id: string, quantity: int|null}>  $picks  the lines, and how many portions of each (null: all of it)
     * @return array{bill: array<string, mixed>, new_bill_id: string}
     */
    public function split(PropertyId $property, string $actorId, string $billId, int $lock, array $picks, ?string $tableId, int $covers): array
    {
        $this->access->require($property, $actorId, FnbAccess::POS_OPERATE, 'This person may not take orders.');
        $actor = strtolower($actorId);

        if ($picks === []) {
            throw Refusal::invalid('Choose what goes on the new bill.', ['lines']);
        }

        if ($covers < 1 || $covers > self::MAX_COVERS) {
            throw Refusal::invalid('Give the number of guests, from 1 to '.self::MAX_COVERS.'.', ['covers']);
        }

        $newId = $this->ids->next();

        $this->transactions->run(function () use ($property, $actor, $billId, $lock, $picks, $tableId, $covers, $newId): void {
            $bill = $this->guard->open($property, strtolower($billId), $lock);

            if ($this->bills->paymentCount($property, $bill['id']) > 0) {
                throw Refusal::stateConflict('A bill with payments is not split. Refund the payments first.');
            }

            $table = null;

            if ($tableId !== null) {
                $table = $this->setup->table($property, strtolower($tableId));

                if ($table === null || $table['outlet_id'] !== $bill['outlet_id'] || ! (bool) $table['is_active']) {
                    throw Refusal::invalid('Choose a table of this outlet that is in use.', ['table_id']);
                }
            }

            $byId = [];

            foreach ($bill['lines'] as $l) {
                $byId[$l['id']] = $l;
            }

            $now = $this->clock->nowUtc();
            $whole = [];
            $portions = [];
            $seen = [];

            foreach ($picks as $pick) {
                $line = $byId[strtolower($pick['line_id'])] ?? throw Refusal::notFound('Line not found.');

                if (isset($seen[$line['id']])) {
                    throw Refusal::invalid('Each line is chosen once.', ['lines']);
                }

                $seen[$line['id']] = true;

                if (! in_array($line['status'], ['pending', 'sent'], true)) {
                    throw Refusal::stateConflict('Only a line that is ordered can go on another bill.');
                }

                $quantity = $pick['quantity'] ?? (int) $line['quantity'];

                if ($quantity < 1 || $quantity > (int) $line['quantity']) {
                    throw Refusal::invalid('Give how many portions go, from 1 to what was ordered.', ['lines']);
                }

                if ($quantity === (int) $line['quantity']) {
                    $whole[] = $line;
                } elseif ((int) $line['discount_minor'] > 0) {
                    throw Refusal::stateConflict('A line with a discount goes whole. Take the discount off first to split its portions.');
                } else {
                    $portions[] = [$line, $quantity];
                }
            }

            $staying = count($this->liveLineIds($bill)) - count($whole);

            if ($staying < 1 && $portions === []) {
                throw Refusal::stateConflict('Everything would leave this bill. Move the bill to another table instead.');
            }

            $number = $this->numbers->next($property, 'BILL');
            $new = [
                'id' => $newId, 'outlet_id' => $bill['outlet_id'], 'number' => $number, 'table_id' => $table['id'] ?? null, 'room_id' => $bill['room_id'], 'stay_id' => $bill['stay_id'], 'reservation_id' => $bill['reservation_id'],
                'split_from_id' => $bill['id'], 'source' => $bill['source'] ?? 'staff', 'covers' => $covers, 'note' => $bill['note'], 'status' => 'open', 'business_date' => $bill['business_date'], 'opened_by' => $actor, 'opened_at' => $now,
            ];

            if (! $this->bills->addBill($property, $new, $now)) {
                throw Refusal::stateConflict('This table has an open bill already. Merge the bills instead.');
            }

            $this->bills->moveLines($property, $bill['id'], $newId, array_map(static fn (array $l): string => $l['id'], $whole), $now);
            $moved = array_map(static fn (array $l): string => $l['id'], $whole);

            foreach ($portions as [$line, $quantity]) {
                $unit = (int) $line['unit_price_minor'] + (int) $line['modifiers_minor'];
                $left = (int) $line['quantity'] - $quantity;
                $copy = $line;
                unset($copy['bill_id'], $copy['line_no'], $copy['created_at'], $copy['updated_at']);
                $copy['id'] = $this->ids->next();
                $copy['quantity'] = $quantity;
                $copy['gross_minor'] = $unit * $quantity;
                $copy['line_total_minor'] = $unit * $quantity;
                $this->bills->addLine($property, $newId, $copy, $now);
                $this->bills->updateLine($property, $bill['id'], $line['id'], ['quantity' => $left, 'gross_minor' => $unit * $left, 'line_total_minor' => $unit * $left], $now);
                $moved[] = $copy['id'];
            }

            $this->guard->touch($property, $bill['id'], $lock);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'fnb_bill.split', 'fnb_bill', $bill['id'], ['number' => $bill['number']], ['new_bill' => $number, 'lines' => count($whole), 'portions_split' => count($portions)], 'Split '.$bill['number'].' to '.$number));
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'fnb_bill.opened', 'fnb_bill', $newId, null, ['number' => $number, 'split_from' => $bill['number'], 'table' => $table['code'] ?? null, 'room_id' => $bill['room_id'], 'covers' => $covers]));
            $this->told($property, $actor, $new, $table['code'] ?? null, $moved, $bill);
        });

        return ['bill' => $this->service->show($property, $actorId, $billId), 'new_bill_id' => $newId];
    }

    /**
     * @param  array<string, mixed>  $bill
     * @return list<string> the lines that are ordered and not voided
     */
    private function liveLineIds(array $bill): array
    {
        return array_values(array_map(static fn (array $l): string => $l['id'], array_filter($bill['lines'], static fn (array $l): bool => in_array($l['status'], ['pending', 'sent'], true))));
    }

    /**
     * Tells the kitchen which lines are on a bill and where, so a ticket whose dishes are all on it shows the table and the bill they belong to now.
     *
     * @param  array<string, mixed>  $bill
     * @param  list<string>  $lineIds
     * @param  array<string, mixed>|null  $from  the bill the lines left, when they left one that goes on
     */
    private function told(PropertyId $property, string $actor, array $bill, ?string $table, array $lineIds, ?array $from = null): void
    {
        $room = ($bill['room_id'] ?? null) === null ? null : $this->rooms->room($property, $bill['room_id'])?->number;
        $this->outbox->publish(new OutboxEvent($property, 'fnb.bill.rearranged', $this->ids->next(), 1, [
            'bill_id' => $bill['id'], 'bill_number' => $bill['number'], 'table' => $table, 'room' => $room, 'line_ids' => $lineIds, 'from_bill_id' => $from['id'] ?? null, 'actor_id' => $actor,
        ]));
    }
}
