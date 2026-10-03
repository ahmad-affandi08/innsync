<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Modules\InventoryPurchasing\Domain\StockValue;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Outbox\OutboxConsumer;
use App\Shared\Application\Outbox\OutboxMessage;

/**
 * Books a production batch of the kitchen (FR-KIT-014, BR-005): one movement out for each ingredient and one in for the semi-finished good, the batch being the source of all of them,
 * so a message handled twice books nothing twice. The product comes in at what the ingredients cost, spread over what the batch really made, so the cost of a portion follows the yield.
 * The ingredients are real whatever the books say, so their balance may go below zero unless the location or the category blocks it, and then the message waits for someone to look at it.
 * The inventory reads the event only, never the kitchen.
 */
final readonly class KitchenProductionConsumer implements OutboxConsumer
{
    public const EVENT = 'kitchen.production.recorded';

    public function __construct(private InventoryStore $inventory, private StockPoster $poster) {}

    public function name(): string
    {
        return 'inventory.kitchen-production';
    }

    public function supports(string $eventType): bool
    {
        return $eventType === self::EVENT;
    }

    public function consume(OutboxMessage $message): void
    {
        $event = $message->event;
        $d = $event->data;
        $property = $event->propertyId;
        $location = $this->inventory->location($property, strtolower((string) $d['location_id'])) ?? throw Refusal::notFound('Location not found.');
        $source = strtolower((string) $d['production_id']);
        $actor = (string) $d['actor_id'];
        $number = (string) $d['number'];
        $value = 0;

        foreach ($d['inputs'] ?? [] as $in) {
            $item = $this->inventory->item($property, strtolower((string) $in['item_id'])) ?? throw Refusal::notFound('Item not found.');
            $moved = $this->poster->post($property, $actor, $item, $location, 'issue', (string) $in['unit'], (int) $in['quantity_milli'], 'kitchen', $number, null, 'kitchen_production', $source, null, null, true, null, null, null, true);
            $value += abs((int) $moved['movement']['value_minor']);
        }

        $out = $d['output'];
        $product = $this->inventory->item($property, strtolower((string) $out['item_id'])) ?? throw Refusal::notFound('Item not found.');
        $quantity = (int) $out['quantity_milli'];
        $unitCost = intdiv($value * 1000 + intdiv($quantity, 2), $quantity);
        $lot = ($out['expires_on'] ?? null) === null ? null : ['number' => (string) ($out['lot_number'] ?? $number), 'expires_on' => (string) $out['expires_on']];

        if ($unitCost > StockValue::MAX_UNIT_COST_MINOR) {
            throw Refusal::invalid('The batch is too dear for one unit of the product; check the yield.', ['unit_cost_minor']);
        }

        $this->poster->post($property, $actor, $product, $location, 'receipt', (string) $out['unit'], $quantity, null, $number, null, 'kitchen_production', $source, null, null, false, null, $unitCost, null, false, $lot);
    }
}
