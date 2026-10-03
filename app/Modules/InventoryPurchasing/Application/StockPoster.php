<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Modules\InventoryPurchasing\Domain\StockQuantity;
use App\Modules\InventoryPurchasing\Domain\StockValue;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Time\Clock;
use App\Shared\Domain\Tenancy\PropertyId;
use InvalidArgumentException;

/**
 * Appends one movement to the stock ledger. It runs inside the caller's transaction and locks the item first, so two postings for one item run
 * one after the other and the balance it reads is the balance it changes. It owns the rules every kind of movement shares: the unit and factor
 * (the factor of the moment is kept), the base equivalent, the negative-stock policy (FR-INV-010, BR-007), and exactly-once posting for a source
 * document (BR-005): posting again for the same source, item and location returns the movement already made.
 *
 * It also values the movement (FR-INV-007). Stock is valued by the moving average over the item in the whole property: an inflow carries the cost it is
 * given (a transfer in carries the value of its transfer out, so moving stock between locations never changes the total), and an outflow is taken out
 * at the average cost of the moment. When nothing is left to average, an outflow uses the cost of the last inflow, so stock that went negative
 * is valued too and a later correction restores the value with the quantity.
 */
final readonly class StockPoster
{
    public const INFLOWS = ['opening', 'receipt', 'adjustment_in', 'transfer_in'];

    public const NEGATIVE_PERMISSION = 'inventory.stock.negative';

    public function __construct(
        private InventoryStore $inventory,
        private BusinessDateProvider $businessDate,
        private PermissionChecker $permissions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $location
     * @param  int  $qtyMilli  the magnitude in `$unit`; the ledger stores an outflow as negative
     * @param  array{conversion_id: string|null, factor_milli: int}|null  $snapshot  the unit and factor a document recorded earlier (a transfer)
     * @param  string|null  $overrideReason  why an outflow may take the balance below zero; needs the privilege
     * @param  int|null  $unitCostMinor  the cost of one `$unit` in minor units; needed for opening and receipt, optional for adjustment in (the average is used)
     * @param  int|null  $fixedValueMinor  the value a transfer in takes over from its transfer out, or the cost a return to a supplier takes out
     * @param  bool  $automatic  a movement the system makes from a sale: it may take the balance below zero (a sale is never refused for stock the books are behind on) unless the location or category blocks it
     * @param  array{number: string|null, expires_on: string|null}|null  $lot  the batch an inflow came in with; an outflow always takes from the batch that expires first
     * @return array{movement: array<string, mixed>, replayed: bool}
     */
    public function post(PropertyId $property, string $actorId, array $item, array $location, string $kind, string $unit, int $qtyMilli, ?string $reasonCode, ?string $reference, ?string $note, ?string $sourceType, ?string $sourceRef, ?string $transferId, ?string $overrideReason, bool $allowNegative, ?array $snapshot = null, ?int $unitCostMinor = null, ?int $fixedValueMinor = null, bool $automatic = false, ?array $lot = null): array
    {
        $actor = strtolower($actorId);
        $this->inventory->lockItem($property, $item['id']);

        if ($sourceType !== null && $sourceRef !== null) {
            $existing = $this->inventory->movementBySource($property, $sourceType, $sourceRef, $item['id'], $location['id']);

            if ($existing !== null) {
                return ['movement' => $this->shape($existing, $item), 'replayed' => true];
            }
        }

        if ($snapshot !== null) {
            $conversion = $snapshot['conversion_id'];
            $factor = $snapshot['factor_milli'];
        } elseif ($unit === $item['base_unit']) {
            $conversion = null;
            $factor = 1000;
        } else {
            $current = $this->inventory->currentUnit($property, $item['id'], $unit) ?? throw Refusal::invalid('This item has no conversion for that unit.', ['unit']);
            $conversion = $current['id'];
            $factor = (int) $current['factor_milli'];
        }

        try {
            $base = StockQuantity::toBase($qtyMilli, $factor);
        } catch (InvalidArgumentException $e) {
            throw Refusal::invalid($e->getMessage(), ['quantity']);
        }

        if ($base < 1) {
            throw Refusal::invalid('This quantity is less than 0.001 of the base unit.', ['quantity']);
        }

        $inflow = in_array($kind, self::INFLOWS, true);
        $override = null;

        if ($lot !== null) {
            if (! $inflow || $kind === 'transfer_in') {
                throw Refusal::invalid('A batch and an expiry date are given when stock comes in.', ['lot_number']);
            }

            if ($lot['number'] !== null && (trim($lot['number']) === '' || mb_strlen($lot['number']) > 40)) {
                throw Refusal::invalid('The batch number is at most 40 characters.', ['lot_number']);
            }

            if ($lot['expires_on'] !== null && ($lot['expires_on'] < $this->businessDate->current($property)->toString() || preg_match('/^\d{4}-\d{2}-\d{2}$/', $lot['expires_on']) !== 1)) {
                throw Refusal::invalid('Stock that has expired is not received; give an expiry date from today.', ['expires_on']);
            }
        }

        if (! $inflow) {
            $balance = $this->inventory->balanceOf($property, $item['id'], $location['id']);

            if ($balance - $base < 0) {
                $override = $this->negativeAllowed($property, $actor, $item, $location, $allowNegative, $overrideReason, $automatic);
            }
        }

        $sign = $inflow ? 1 : -1;
        $value = $this->valueOf($property, $item, $kind, $unit, $qtyMilli, $base, $unitCostMinor, $fixedValueMinor);
        $id = $this->ids->next();
        $date = $this->businessDate->current($property)->toString();
        $row = [
            'id' => $id, 'item_id' => $item['id'], 'location_id' => $location['id'], 'kind' => $kind, 'reason_code' => $reasonCode, 'unit' => $unit, 'unit_qty_milli' => $sign * $qtyMilli, 'conversion_id' => $conversion,
            'factor_milli' => $factor, 'base_qty_milli' => $sign * $base, 'value_minor' => $sign * $value, 'unit_cost_minor' => $unitCostMinor, 'reference' => $reference, 'source_type' => $sourceType, 'source_ref' => $sourceRef, 'transfer_id' => $transferId, 'note' => $note,
            'override_reason' => $override, 'business_date' => $date, 'posted_by' => $actor,
        ];
        $this->inventory->addMovement($property, $row, $this->clock->nowUtc());

        if ($inflow && $lot !== null && ($lot['number'] !== null || $lot['expires_on'] !== null)) {
            $this->inventory->addLot($property, ['id' => $this->ids->next(), 'item_id' => $item['id'], 'location_id' => $location['id'], 'movement_id' => $id, 'lot_number' => $lot['number'], 'expires_on' => $lot['expires_on'], 'received_milli' => $base, 'remaining_milli' => $base], $this->clock->nowUtc());
        } elseif (! $inflow) {
            $this->inventory->consumeLots($property, $item['id'], $location['id'], $base);
        }
        $this->audit->record(new AuditEntry($property->toString(), $actor, 'stock.'.$kind.'_posted', 'inventory_item', $item['id'], null, [
            'location' => $location['code'], 'unit' => $unit, 'unit_qty_milli' => $sign * $qtyMilli, 'factor_milli' => $factor, 'base_qty_milli' => $sign * $base, 'value_minor' => $sign * $value, 'reason_code' => $reasonCode, 'source' => $sourceType === null ? null : $sourceType.':'.$sourceRef,
        ], $override));
        $this->outbox->publish(new OutboxEvent($property, 'inventory.stock.moved', $id, 1, ['movement_id' => $id, 'item_id' => $item['id'], 'location_id' => $location['id'], 'kind' => $kind, 'base_qty_milli' => $sign * $base, 'actor_id' => $actor]));

        return ['movement' => $this->shape($row, $item), 'replayed' => false];
    }

    /**
     * What a movement of this quantity is worth, before it is posted, for a person who has to ask for approval first (FR-INV-010). The same figure `post` will book: the moving average, or the
     * cost given for stock that comes in.
     *
     * @param  array<string, mixed>  $item
     */
    public function estimate(PropertyId $property, array $item, string $kind, string $unit, int $qtyMilli, ?int $unitCostMinor): int
    {
        if ($unit === $item['base_unit']) {
            $factor = 1000;
        } else {
            $current = $this->inventory->currentUnit($property, $item['id'], $unit) ?? throw Refusal::invalid('This item has no conversion for that unit.', ['unit']);
            $factor = (int) $current['factor_milli'];
        }

        try {
            $base = StockQuantity::toBase($qtyMilli, $factor);
        } catch (InvalidArgumentException $e) {
            throw Refusal::invalid($e->getMessage(), ['quantity']);
        }

        return $this->valueOf($property, $item, $kind, $unit, $qtyMilli, $base, $unitCostMinor, null);
    }

    /** The value, a magnitude in minor units, of a movement of `$base` base thousandths. */
    private function valueOf(PropertyId $property, array $item, string $kind, string $unit, int $qtyMilli, int $base, ?int $unitCostMinor, ?int $fixedValueMinor): int
    {
        if ($kind === 'transfer_in') {
            return $fixedValueMinor ?? throw new InvalidArgumentException('A transfer in takes the value of its transfer out.');
        }

        // Goods returned to a supplier go out at what they cost on the receipt they came on, not at the average of the day.
        if ($kind === 'return_out' && $fixedValueMinor !== null) {
            return $fixedValueMinor;
        }

        if (in_array($kind, ['opening', 'receipt'], true) || ($kind === 'adjustment_in' && $unitCostMinor !== null)) {
            if ($unitCostMinor === null || $unitCostMinor < 0 || $unitCostMinor > StockValue::MAX_UNIT_COST_MINOR) {
                throw Refusal::invalid('Give the cost of one '.$unit.' as a whole amount of at most 100,000,000.', ['unit_cost_minor']);
            }

            return StockValue::ofQuantity($qtyMilli, $unitCostMinor);
        }

        $pool = $this->inventory->pool($property, $item['id']);

        if ($pool['qty_milli'] > 0 && $pool['value_minor'] > 0) {
            // Taking the last unit takes exactly the value left. Going past it (negative stock) carries on at the same average, so the value
            // goes below zero with the quantity and a later correction at that cost brings both back together.
            return StockValue::mulDiv($base, $pool['value_minor'], $pool['qty_milli']);
        }

        $last = $this->inventory->lastInflowCost($property, $item['id']);

        if ($last !== null) {
            return StockValue::mulDiv($base, $last['value_minor'], $last['base_qty_milli']);
        }

        if ($kind === 'adjustment_in') {
            throw Refusal::invalid('This item has no cost yet. Give the cost of one '.$unit.'.', ['unit_cost_minor']);
        }

        return 0;
    }

    /** The reason an outflow may go below zero, or a refusal. */
    private function negativeAllowed(PropertyId $property, string $actor, array $item, array $location, bool $allowNegative, ?string $reason, bool $automatic): string
    {
        $category = $this->inventory->category($property, $item['category_id']);

        if (! $allowNegative || (bool) $location['negative_blocked'] || ($category !== null && (bool) $category['negative_blocked'])) {
            throw Refusal::stateConflict('There is not enough stock, and this location or category does not allow stock below zero.');
        }

        if ($automatic) {
            return 'Taken by a sale before the stock was received';
        }

        if (! $this->permissions->allowsInProperty($actor, self::NEGATIVE_PERMISSION, $property)) {
            throw Refusal::stateConflict('There is not enough stock. Going below zero needs the privilege to open negative stock.');
        }

        if ($reason === null || trim($reason) === '' || mb_strlen($reason) > 200) {
            throw Refusal::stateConflict('There is not enough stock. To go below zero, give the reason (at most 200 characters).');
        }

        return trim($reason);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function shape(array $row, array $item): array
    {
        return [
            'id' => $row['id'], 'kind' => $row['kind'], 'unit' => $row['unit'], 'unit_qty_milli' => (int) $row['unit_qty_milli'], 'factor_milli' => (int) $row['factor_milli'], 'base_qty_milli' => (int) $row['base_qty_milli'],
            'value_minor' => (int) $row['value_minor'], 'base_unit' => $item['base_unit'], 'reason_code' => $row['reason_code'], 'override_reason' => $row['override_reason'],
        ];
    }
}
