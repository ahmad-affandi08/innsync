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
 * Stock opname (FR-INV-006, FR-INV-012). Starting a count freezes the system quantity of each item to count, in a location, at that instant (the
 * snapshot). The counters enter what they find without seeing the snapshot (a blind count). Someone else, with the approval privilege, reviews the
 * submitted count and either sends it back for a recount or approves it; approving posts the differences to the ledger as adjustments.
 *
 * The difference is the counted quantity minus the snapshot, never minus the balance of the day it is approved, because the people counted the shelf
 * as it was when the count began. Movements posted while counting stay in the ledger as they are, and the adjustment is added on top of them, so a
 * receipt or an issue during the count never shows up as a false difference (FR-INV-012). The value of a difference is that of the adjustment movement,
 * at the moving average of the moment it is posted (FR-INV-007); until then the screen shows an estimate at the current average.
 */
final readonly class StockCountService
{
    public const MANAGE_PERMISSION = 'inventory.count.manage';

    public const APPROVE_PERMISSION = 'inventory.count.approve';

    public const KINDS = ['scheduled', 'spot'];

    public const MAX_LINES = 500;

    public function __construct(
        private StockCountStore $counts,
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
        private PropertyCurrencyReader $currency,
    ) {}

    /**
     * @return array{counts: list<array<string, mixed>>, locations: list<array<string, mixed>>, categories: list<array<string, mixed>>, kinds: list<string>, may: array{manage: bool, approve: bool}}
     */
    public function overview(PropertyId $property, string $actorId, ?string $status): array
    {
        $this->assertProperty($property);
        $may = $this->mayOf($property, $actorId);

        if (! $may['manage'] && ! $may['approve'] && ! $this->permissions->allowsInProperty($actorId, StockService::VIEW_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not see stock counts.');
        }

        if ($status !== null && $status !== '' && ! in_array($status, ['counting', 'submitted', 'approved', 'cancelled'], true)) {
            throw Refusal::invalid('Choose counting, submitted, approved or cancelled.', ['status']);
        }

        $locations = array_column($this->inventory->locations($property), null, 'id');
        $rows = $this->counts->all($property, $status === '' ? null : $status, 200);
        $names = $this->staff->namesOf($property, array_values(array_unique(array_filter(array_merge(array_column($rows, 'started_by'), array_column($rows, 'submitted_by'), array_column($rows, 'decided_by'))))));

        return [
            'counts' => array_map(fn (array $c): array => $this->head($c, $locations, $names), $rows),
            'locations' => array_values(array_map(static fn (array $l): array => ['id' => $l['id'], 'code' => $l['code'], 'name' => $l['name']], array_filter($locations, static fn (array $l): bool => (bool) $l['is_active']))),
            'categories' => array_values(array_map(static fn (array $c): array => ['id' => $c['id'], 'code' => $c['code'], 'name' => $c['name']], array_filter($this->inventory->categories($property), static fn (array $c): bool => (bool) $c['is_active']))),
            'kinds' => self::KINDS,
            'may' => $may,
        ];
    }

    /**
     * The count sheet, or the minutes of a count. While it is being counted the snapshot is hidden from everyone who cannot approve.
     *
     * @return array<string, mixed>
     */
    public function show(PropertyId $property, string $actorId, string $id): array
    {
        $this->assertProperty($property);
        $may = $this->mayOf($property, $actorId);

        if (! $may['manage'] && ! $may['approve'] && ! $this->permissions->allowsInProperty($actorId, StockService::VIEW_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not see stock counts.');
        }

        $count = $this->counts->find($property, strtolower($id)) ?? throw Refusal::notFound('Stock count not found.');
        $actor = strtolower($actorId);
        $locations = array_column($this->inventory->locations($property), null, 'id');
        $items = array_column($this->inventory->items($property), null, 'id');
        $names = $this->staff->namesOf($property, array_values(array_unique(array_filter([$count['started_by'], $count['submitted_by'], $count['decided_by']]))));
        $blind = $count['status'] === 'counting' && ! $may['approve'];
        $decided = in_array($count['status'], ['approved', 'cancelled'], true);
        $balances = [];

        if (! $decided && ! $blind) {
            foreach ($this->inventory->balances($property, null, $count['location_id']) as $b) {
                $balances[$b['item_id']] = (int) $b['balance_milli'];
            }
        }

        $units = [];

        if (! $decided) {
            foreach ($this->inventory->unitVersions($property) as $u) {
                $units[$u['item_id']][$u['unit']] = $u['unit'];
            }

            $units = array_map(static fn (array $list): array => array_values($list), $units);
        }

        $lines = [];
        $gain = 0;
        $loss = 0;

        foreach ($count['lines'] as $l) {
            $item = $items[$l['item_id']] ?? ['code' => '', 'name' => '', 'base_unit' => ''];
            $snapshot = (int) $l['snapshot_qty_milli'];
            $counted = $l['counted_base_milli'] === null ? null : (int) $l['counted_base_milli'];
            $variance = $l['variance_milli'] !== null ? (int) $l['variance_milli'] : ($counted === null ? null : $counted - $snapshot);
            $value = $l['value_minor'] === null ? null : (int) $l['value_minor'];

            if ($value === null && $variance !== null && $variance !== 0 && ! $decided && ! $blind) {
                $value = $this->estimate($property, $l['item_id'], $variance);
            }

            if ($variance !== null && $value !== null && $variance !== 0) {
                $value > 0 ? $gain += $value : $loss += $value;
            }

            $lines[] = [
                'id' => $l['id'], 'item_id' => $l['item_id'], 'item_code' => $item['code'], 'item_name' => $item['name'], 'base_unit' => $item['base_unit'],
                'units' => $decided ? [] : [$item['base_unit'], ...($units[$l['item_id']] ?? [])],
                'snapshot_milli' => $blind ? null : $snapshot, 'counted_unit' => $l['counted_unit'], 'counted_unit_qty_milli' => $l['counted_unit_qty_milli'] === null ? null : (int) $l['counted_unit_qty_milli'],
                'counted_milli' => $counted, 'variance_milli' => $blind ? null : $variance, 'moved_since_milli' => isset($balances[$l['item_id']]) ? $balances[$l['item_id']] - $snapshot : ($blind || $decided ? null : -$snapshot),
                'reason_code' => $l['reason_code'], 'note' => $l['note'], 'value_minor' => $blind ? null : $value, 'value_is_estimate' => $l['value_minor'] === null && $value !== null,
            ];
        }

        usort($lines, static fn (array $a, array $b): int => strcmp($a['item_code'], $b['item_code']));

        return [
            ...$this->head($count, $locations, $names),
            'currency' => $this->currency->currencyOf($property), 'blind' => $blind, 'reasons' => StockMovementService::ADJUST_REASONS, 'lines' => $lines,
            'gain_minor' => $blind ? null : $gain, 'loss_minor' => $blind ? null : $loss, 'net_minor' => $blind ? null : $gain + $loss,
            'may_count' => $may['manage'] && $count['status'] === 'counting', 'may_submit' => $may['manage'] && $count['status'] === 'counting',
            'may_cancel' => $may['manage'] && in_array($count['status'], ['counting', 'submitted'], true),
            'may_review' => $may['approve'] && $count['status'] === 'submitted' && $count['submitted_by'] !== $actor,
            'review_blocked_self' => $may['approve'] && $count['status'] === 'submitted' && $count['submitted_by'] === $actor,
        ];
    }

    /** @return array<string, mixed> */
    public function start(PropertyId $property, string $actorId, string $locationId, string $kind, ?string $categoryId, ?string $scheduledFor, ?string $note, ?IdempotencyKey $key = null): array
    {
        $this->assertProperty($property);

        if (! $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not start a stock count.');
        }

        if (! in_array($kind, self::KINDS, true)) {
            throw Refusal::invalid('Choose a scheduled or a spot count.', ['kind']);
        }

        $note = $note === null || trim($note) === '' ? null : trim($note);

        if ($note !== null && mb_strlen($note) > 200) {
            throw Refusal::invalid('The note is at most 200 characters.', ['note']);
        }

        $scheduledFor = $scheduledFor === null || $scheduledFor === '' ? null : $scheduledFor;

        if ($scheduledFor !== null && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduledFor) !== 1 || ! checkdate((int) substr($scheduledFor, 5, 2), (int) substr($scheduledFor, 8, 2), (int) substr($scheduledFor, 0, 4)))) {
            throw Refusal::invalid('Give the date as year-month-day.', ['scheduled_for']);
        }

        $location = $this->inventory->location($property, strtolower($locationId)) ?? throw Refusal::invalid('Choose the location to count.', ['location_id']);

        if (! (bool) $location['is_active']) {
            throw Refusal::stateConflict('An inactive location cannot be counted.');
        }

        $category = null;

        if ($categoryId !== null && $categoryId !== '') {
            $category = $this->inventory->category($property, strtolower($categoryId)) ?? throw Refusal::invalid('Choose a category from the list.', ['category_id']);
        }

        $actor = strtolower($actorId);
        $id = $this->ids->next();

        $operation = function () use ($property, $actor, $id, $location, $category, $kind, $scheduledFor, $note): void {
            $wanted = array_flip($this->counts->itemsWithHistory($property, $location['id']));
            $items = array_values(array_filter($this->inventory->items($property), static fn (array $i): bool => isset($wanted[$i['id']]) && (bool) $i['is_active'] && ($category === null || $i['category_id'] === $category['id'])));

            if ($items === []) {
                throw Refusal::stateConflict('There is no stock history to count in this location'.($category === null ? '' : ' for this category').'.');
            }

            if (count($items) > self::MAX_LINES) {
                throw Refusal::invalid('A count has at most '.self::MAX_LINES.' items. Count by category instead.', ['category_id']);
            }

            usort($items, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

            // Items are locked in one order, so the snapshot of each is the balance no posting can change until this transaction ends.
            foreach ($items as $item) {
                $this->inventory->lockItem($property, $item['id']);
            }

            $lines = array_map(fn (array $item): array => ['id' => $this->ids->next(), 'item_id' => $item['id'], 'snapshot_qty_milli' => $this->inventory->balanceOf($property, $item['id'], $location['id'])], $items);
            $number = $this->numbers->next($property, 'OPN');
            $date = $this->businessDate->current($property)->toString();
            $at = $this->clock->nowUtc();

            if (! $this->counts->add($property, ['id' => $id, 'number' => $number, 'location_id' => $location['id'], 'category_id' => $category['id'] ?? null, 'kind' => $kind, 'scheduled_for' => $scheduledFor, 'note' => $note, 'started_by' => $actor, 'started_at' => $at, 'business_date' => $date], $lines, $at)) {
                throw Refusal::stateConflict('A count with this number already exists. Try again.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'stock_count.started', 'stock_count', $id, null, ['number' => $number, 'location' => $location['code'], 'kind' => $kind, 'category' => $category['code'] ?? null, 'lines' => count($lines)]));
            $this->outbox->publish(new OutboxEvent($property, 'inventory.count.started', $id, 1, ['count_id' => $id, 'number' => $number, 'actor_id' => $actor]));
        };

        if ($key === null) {
            $this->transactions->run($operation);
        } else {
            $once = $this->executor->execute(
                new IdempotencyRequest($property, $key, 'inventory.count.start', ['location' => $location['id'], 'category' => $category['id'] ?? null, 'kind' => $kind, 'scheduled_for' => $scheduledFor, 'note' => $note], $actor),
                function () use ($operation, $id): array {
                    $operation();

                    return ['id' => $id];
                },
            );
            $id = (string) $once->payload['id'];
        }

        return $this->show($property, $actorId, $id);
    }

    /**
     * Saves what the counters found. A line without a quantity is cleared. Several people may count at once; the version of the count catches two saves
     * that would overwrite each other.
     *
     * @param  list<array{line_id: string, unit?: string|null, quantity?: string|null, reason_code?: string|null, note?: string|null}>  $entries
     * @return array<string, mixed>
     */
    public function saveCounts(PropertyId $property, string $actorId, string $id, int $lock, array $entries): array
    {
        $this->assertProperty($property);

        if (! $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not enter a stock count.');
        }

        $count = $this->counts->find($property, strtolower($id)) ?? throw Refusal::notFound('Stock count not found.');

        if ($count['status'] !== 'counting') {
            throw Refusal::stateConflict('Only a count that is being counted can be changed.');
        }

        if ($entries === [] || count($entries) > self::MAX_LINES) {
            throw Refusal::invalid('Send the lines you counted.', ['lines']);
        }

        $byId = array_column($count['lines'], null, 'id');
        $items = array_column($this->inventory->items($property), null, 'id');
        $prepared = [];

        foreach ($entries as $entry) {
            $line = $byId[strtolower((string) ($entry['line_id'] ?? ''))] ?? throw Refusal::invalid('A line does not belong to this count.', ['lines']);
            $item = $items[$line['item_id']] ?? throw Refusal::notFound('Item not found.');
            $quantity = $entry['quantity'] ?? null;
            $reason = $entry['reason_code'] ?? null;
            $note = $entry['note'] ?? null;
            $note = $note === null || trim($note) === '' ? null : trim($note);

            if ($reason !== null && $reason !== '' && ! in_array($reason, StockMovementService::ADJUST_REASONS, true)) {
                throw Refusal::invalid('Choose the reason from the list.', ['lines']);
            }

            if ($note !== null && mb_strlen($note) > 200) {
                throw Refusal::invalid('A note is at most 200 characters.', ['lines']);
            }

            $fields = ['reason_code' => $reason === '' ? null : $reason, 'note' => $note, 'counted_unit' => null, 'counted_unit_qty_milli' => null, 'counted_conversion_id' => null, 'counted_factor_milli' => null, 'counted_base_milli' => null, 'variance_milli' => null];

            if ($quantity !== null && trim((string) $quantity) !== '') {
                $qty = StockQuantity::parse((string) $quantity);

                if ($qty === null || $qty > StockQuantity::MAX_MILLI) {
                    throw Refusal::invalid('Give every quantity as a number with at most three decimals.', ['lines']);
                }

                $unit = strtoupper(trim((string) ($entry['unit'] ?? '')));

                if ($unit === '' || $unit === $item['base_unit']) {
                    $unit = $item['base_unit'];
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

                $fields = [...$fields, 'counted_unit' => $unit, 'counted_unit_qty_milli' => $qty, 'counted_conversion_id' => $conversion, 'counted_factor_milli' => $factor, 'counted_base_milli' => $base];
            }

            $prepared[$line['id']] = $fields;
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $count, $lock, $prepared, $actor): void {
            if (! $this->counts->touch($property, $count['id'], $lock, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This count changed while you were counting. Reload it and enter your lines again.');
            }

            foreach ($prepared as $lineId => $fields) {
                $this->counts->updateLine($lineId, $fields);
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'stock_count.counted', 'stock_count', $count['id'], null, ['number' => $count['number'], 'lines' => count($prepared)]));
        });

        return $this->show($property, $actorId, $count['id']);
    }

    /** The counters hand the count in. Every line needs a number (zero is a number); the differences are fixed now. @return array<string, mixed> */
    public function submit(PropertyId $property, string $actorId, string $id, int $lock): array
    {
        $this->assertProperty($property);

        if (! $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not hand in a stock count.');
        }

        $count = $this->counts->find($property, strtolower($id)) ?? throw Refusal::notFound('Stock count not found.');

        if ($count['status'] !== 'counting') {
            throw Refusal::stateConflict('Only a count that is being counted can be handed in.');
        }

        $items = array_column($this->inventory->items($property), null, 'id');
        $missing = [];

        foreach ($count['lines'] as $l) {
            if ($l['counted_base_milli'] === null) {
                $missing[] = $items[$l['item_id']]['code'] ?? $l['item_id'];
            }
        }

        if ($missing !== []) {
            sort($missing);
            throw Refusal::stateConflict('Count every item before handing in, with zero where there is none. Missing: '.implode(', ', array_slice($missing, 0, 8)).(count($missing) > 8 ? ' and '.(count($missing) - 8).' more' : '').'.');
        }

        $actor = strtolower($actorId);
        $differences = 0;

        $this->transactions->run(function () use ($property, $count, $lock, $actor, &$differences): void {
            $at = $this->clock->nowUtc();

            foreach ($count['lines'] as $l) {
                $variance = (int) $l['counted_base_milli'] - (int) $l['snapshot_qty_milli'];
                $differences += $variance === 0 ? 0 : 1;
                $this->counts->updateLine($l['id'], ['variance_milli' => $variance]);
            }

            if (! $this->counts->transition($property, $count['id'], $lock, ['counting'], 'submitted', ['submitted_by' => $actor, 'submitted_at' => $at], $at)) {
                throw Refusal::stateConflict('This count changed meanwhile. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'stock_count.submitted', 'stock_count', $count['id'], ['status' => 'counting'], ['status' => 'submitted', 'number' => $count['number'], 'lines_with_difference' => $differences]));
            $this->outbox->publish(new OutboxEvent($property, 'inventory.count.submitted', $count['id'], 1, ['count_id' => $count['id'], 'number' => $count['number'], 'actor_id' => $actor]));
        });

        return $this->show($property, $actorId, $count['id']);
    }

    /** The reviewer sends the count back to be counted again, with the reason. @return array<string, mixed> */
    public function sendBack(PropertyId $property, string $actorId, string $id, string $note, int $lock): array
    {
        $count = $this->reviewable($property, $actorId, $id);

        if (trim($note) === '' || mb_strlen($note) > 200) {
            throw Refusal::invalid('A reason of at most 200 characters is required.', ['note']);
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $count, $lock, $actor, $note): void {
            $at = $this->clock->nowUtc();

            if (! $this->counts->transition($property, $count['id'], $lock, ['submitted'], 'counting', ['submitted_by' => null, 'submitted_at' => null, 'decision_note' => trim($note)], $at)) {
                throw Refusal::stateConflict('This count changed meanwhile. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'stock_count.sent_back', 'stock_count', $count['id'], ['status' => 'submitted'], ['status' => 'counting', 'number' => $count['number']], trim($note)));
        });

        return $this->show($property, $actorId, $count['id']);
    }

    /** The reviewer approves: the differences are posted to the ledger as adjustments, once, in one transaction. @return array<string, mixed> */
    public function approve(PropertyId $property, string $actorId, string $id, int $lock, ?string $note = null): array
    {
        $count = $this->reviewable($property, $actorId, $id);
        $actor = strtolower($actorId);
        $location = $this->inventory->location($property, $count['location_id']) ?? throw Refusal::notFound('Location not found.');
        $items = array_column($this->inventory->items($property), null, 'id');
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if ($note !== null && mb_strlen($note) > 200) {
            throw Refusal::invalid('The note is at most 200 characters.', ['note']);
        }

        $this->transactions->run(function () use ($property, $count, $lock, $actor, $location, $items, $note): void {
            $lines = $count['lines'];
            usort($lines, static fn (array $a, array $b): int => strcmp($a['item_id'], $b['item_id']));

            foreach ($lines as $l) {
                $this->inventory->lockItem($property, $l['item_id']);
            }

            $posted = 0;

            foreach ($lines as $l) {
                $variance = (int) $l['variance_milli'];

                if ($variance === 0) {
                    continue;
                }

                $item = $items[$l['item_id']] ?? throw Refusal::notFound('Item not found.');
                $result = $this->poster->post(
                    $property, $actor, $item, $location, $variance > 0 ? 'adjustment_in' : 'adjustment_out', $item['base_unit'], abs($variance), $l['reason_code'] ?? 'count_correction', $count['number'], $l['note'],
                    'count', $count['id'], null, 'Stock count '.$count['number'], true,
                );
                $this->counts->updateLine($l['id'], ['movement_id' => $result['movement']['id'], 'value_minor' => $result['movement']['value_minor']]);
                $posted++;
            }

            $at = $this->clock->nowUtc();

            if (! $this->counts->transition($property, $count['id'], $lock, ['submitted'], 'approved', ['decided_by' => $actor, 'decided_at' => $at, 'decision_note' => $note], $at)) {
                throw Refusal::stateConflict('This count changed meanwhile. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'stock_count.approved', 'stock_count', $count['id'], ['status' => 'submitted'], ['status' => 'approved', 'number' => $count['number'], 'adjustments' => $posted], $note));
            $this->outbox->publish(new OutboxEvent($property, 'inventory.count.approved', $count['id'], 1, ['count_id' => $count['id'], 'number' => $count['number'], 'adjustments' => $posted, 'actor_id' => $actor]));
        });

        return $this->show($property, $actorId, $count['id']);
    }

    /** A count that is being counted or waits for review is dropped, with a reason; nothing is posted. @return array<string, mixed> */
    public function cancel(PropertyId $property, string $actorId, string $id, string $note, int $lock): array
    {
        $this->assertProperty($property);

        if (! $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not cancel a stock count.');
        }

        if (trim($note) === '' || mb_strlen($note) > 200) {
            throw Refusal::invalid('A reason of at most 200 characters is required.', ['note']);
        }

        $count = $this->counts->find($property, strtolower($id)) ?? throw Refusal::notFound('Stock count not found.');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $count, $lock, $actor, $note): void {
            $at = $this->clock->nowUtc();

            if (! $this->counts->transition($property, $count['id'], $lock, ['counting', 'submitted'], 'cancelled', ['decided_by' => $actor, 'decided_at' => $at, 'decision_note' => trim($note)], $at)) {
                throw Refusal::stateConflict('This count changed or was already decided. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'stock_count.cancelled', 'stock_count', $count['id'], ['status' => $count['status']], ['status' => 'cancelled', 'number' => $count['number']], trim($note)));
        });

        return $this->show($property, $actorId, $count['id']);
    }

    /** @return array<string, mixed> a submitted count the actor may decide */
    private function reviewable(PropertyId $property, string $actorId, string $id): array
    {
        $this->assertProperty($property);

        if (! $this->permissions->allowsInProperty($actorId, self::APPROVE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not review a stock count.');
        }

        $count = $this->counts->find($property, strtolower($id)) ?? throw Refusal::notFound('Stock count not found.');

        if ($count['status'] !== 'submitted') {
            throw Refusal::stateConflict('Only a count that was handed in can be reviewed.');
        }

        if ($count['submitted_by'] === strtolower($actorId)) {
            throw Refusal::forbidden('The person who handed in a count cannot review it.');
        }

        return $count;
    }

    /** What a difference is worth at the average of today, before it is posted. */
    private function estimate(PropertyId $property, string $itemId, int $variance): ?int
    {
        $pool = $this->inventory->pool($property, $itemId);
        $base = abs($variance);

        if ($pool['qty_milli'] > 0 && $pool['value_minor'] > 0) {
            return ($variance > 0 ? 1 : -1) * StockValue::mulDiv($base, $pool['value_minor'], $pool['qty_milli']);
        }

        $last = $this->inventory->lastInflowCost($property, $itemId);

        return $last === null ? null : ($variance > 0 ? 1 : -1) * StockValue::mulDiv($base, $last['value_minor'], $last['base_qty_milli']);
    }

    /**
     * @param  array<string, mixed>  $c
     * @param  array<string, array<string, mixed>>  $locations
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    private function head(array $c, array $locations, array $names): array
    {
        $utc = static fn (mixed $v): ?string => $v === null ? null : (new DateTimeImmutable((string) $v, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $day = static fn (mixed $v): ?string => $v === null ? null : substr((string) $v, 0, 10);

        return [
            'id' => $c['id'], 'number' => $c['number'], 'kind' => $c['kind'], 'status' => $c['status'], 'note' => $c['note'], 'decision_note' => $c['decision_note'], 'scheduled_for' => $day($c['scheduled_for']), 'business_date' => $day($c['business_date']),
            'location' => ['id' => $c['location_id'], 'code' => $locations[$c['location_id']]['code'] ?? '', 'name' => $locations[$c['location_id']]['name'] ?? ''],
            'started_by_name' => $names[$c['started_by']] ?? null, 'submitted_by_name' => $c['submitted_by'] === null ? null : ($names[$c['submitted_by']] ?? null), 'decided_by_name' => $c['decided_by'] === null ? null : ($names[$c['decided_by']] ?? null),
            'started_at' => $utc($c['started_at']), 'submitted_at' => $utc($c['submitted_at']), 'decided_at' => $utc($c['decided_at']), 'lock_version' => (int) $c['lock_version'],
            'line_count' => isset($c['line_count']) ? (int) $c['line_count'] : count($c['lines'] ?? []), 'counted_count' => isset($c['counted_count']) ? (int) $c['counted_count'] : null, 'variance_count' => isset($c['variance_count']) ? (int) $c['variance_count'] : null,
        ];
    }

    /** @return array{manage: bool, approve: bool} */
    private function mayOf(PropertyId $property, string $actorId): array
    {
        return ['manage' => $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property), 'approve' => $this->permissions->allowsInProperty($actorId, self::APPROVE_PERMISSION, $property)];
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
