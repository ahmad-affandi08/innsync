<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Modules\InventoryPurchasing\Domain\StockQuantity;
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
     * @return array{movement: array<string, mixed>, replayed: bool}
     */
    public function post(PropertyId $property, string $actorId, array $item, array $location, string $kind, string $unit, int $qtyMilli, ?string $reasonCode, ?string $reference, ?string $note, ?string $sourceType, ?string $sourceRef, ?string $transferId, ?string $overrideReason, bool $allowNegative, ?array $snapshot = null): array
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

        if (! $inflow) {
            $balance = $this->inventory->balanceOf($property, $item['id'], $location['id']);

            if ($balance - $base < 0) {
                $override = $this->negativeAllowed($property, $actor, $item, $location, $allowNegative, $overrideReason);
            }
        }

        $id = $this->ids->next();
        $sign = $inflow ? 1 : -1;
        $date = $this->businessDate->current($property)->toString();
        $row = [
            'id' => $id, 'item_id' => $item['id'], 'location_id' => $location['id'], 'kind' => $kind, 'reason_code' => $reasonCode, 'unit' => $unit, 'unit_qty_milli' => $sign * $qtyMilli, 'conversion_id' => $conversion,
            'factor_milli' => $factor, 'base_qty_milli' => $sign * $base, 'reference' => $reference, 'source_type' => $sourceType, 'source_ref' => $sourceRef, 'transfer_id' => $transferId, 'note' => $note,
            'override_reason' => $override, 'business_date' => $date, 'posted_by' => $actor,
        ];
        $this->inventory->addMovement($property, $row, $this->clock->nowUtc());
        $this->audit->record(new AuditEntry($property->toString(), $actor, 'stock.'.$kind.'_posted', 'inventory_item', $item['id'], null, [
            'location' => $location['code'], 'unit' => $unit, 'unit_qty_milli' => $sign * $qtyMilli, 'factor_milli' => $factor, 'base_qty_milli' => $sign * $base, 'reason_code' => $reasonCode, 'source' => $sourceType === null ? null : $sourceType.':'.$sourceRef,
        ], $override));
        $this->outbox->publish(new OutboxEvent($property, 'inventory.stock.moved', $id, 1, ['movement_id' => $id, 'item_id' => $item['id'], 'location_id' => $location['id'], 'kind' => $kind, 'base_qty_milli' => $sign * $base, 'actor_id' => $actor]));

        return ['movement' => $this->shape($row, $item), 'replayed' => false];
    }

    /** The reason an outflow may go below zero, or a refusal. */
    private function negativeAllowed(PropertyId $property, string $actor, array $item, array $location, bool $allowNegative, ?string $reason): string
    {
        $category = $this->inventory->category($property, $item['category_id']);

        if (! $allowNegative || (bool) $location['negative_blocked'] || ($category !== null && (bool) $category['negative_blocked'])) {
            throw Refusal::stateConflict('There is not enough stock, and this location or category does not allow stock below zero.');
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
            'base_unit' => $item['base_unit'], 'reason_code' => $row['reason_code'], 'override_reason' => $row['override_reason'],
        ];
    }
}
