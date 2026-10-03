<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Outbox\OutboxConsumer;
use App\Shared\Application\Outbox\OutboxMessage;

/**
 * Takes out of stock what the kitchen says its sold dishes used (FR-KIT-004, BR-005). One issue movement for each ingredient of each sold line, the source being that line, so a
 * message handled twice posts nothing twice. A sale is never refused for want of stock the books are behind on: the balance may go below zero, unless the location or the category
 * of the ingredient blocks negative stock, and then the message is not taken and waits for someone to look at it. The inventory reads the event only, never the kitchen.
 */
final readonly class RecipeConsumptionConsumer implements OutboxConsumer
{
    public const EVENT = 'kitchen.consumption.posted';

    public function __construct(private InventoryStore $inventory, private StockPoster $poster) {}

    public function name(): string
    {
        return 'inventory.recipe-consumption';
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

        foreach ($d['entries'] ?? [] as $e) {
            $item = $this->inventory->item($property, strtolower((string) $e['item_id'])) ?? throw Refusal::notFound('Item not found.');
            $this->poster->post($property, (string) $d['actor_id'], $item, $location, 'issue', (string) $e['unit'], (int) $e['quantity_milli'], 'kitchen', (string) $d['bill_number'], null, 'kitchen', strtolower((string) $e['line_id']), null, null, true, null, null, null, true);
        }
    }
}
