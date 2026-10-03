<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Outbox\OutboxConsumer;
use App\Shared\Application\Outbox\OutboxMessage;

/**
 * Writes off the stock the kitchen says it threw away (FR-KIT-006, BR-005): one write-off movement for each ingredient of an entry of the waste log, the entry being the source, so a
 * message handled twice writes nothing off twice. Waste is real whatever the books say, so the balance may go below zero unless the location or the category blocks it, and then the
 * message waits for someone to look at it. The inventory reads the event only, never the kitchen.
 */
final readonly class KitchenWasteConsumer implements OutboxConsumer
{
    public const EVENT = 'kitchen.waste.recorded';

    public function __construct(private InventoryStore $inventory, private StockPoster $poster) {}

    public function name(): string
    {
        return 'inventory.kitchen-waste';
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
        $note = mb_substr((string) ($d['note'] ?? ''), 0, 200);

        foreach ($d['entries'] ?? [] as $e) {
            $item = $this->inventory->item($property, strtolower((string) $e['item_id'])) ?? throw Refusal::notFound('Item not found.');
            $this->poster->post($property, (string) $d['actor_id'], $item, $location, 'write_off', (string) $e['unit'], (int) $e['quantity_milli'], (string) $d['reason'], (string) $d['number'], $note === '' ? null : $note, 'kitchen', strtolower((string) $d['waste_id']), null, null, true, null, null, null, true);
        }
    }
}
