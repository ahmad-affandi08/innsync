<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Outbox\OutboxConsumer;
use App\Shared\Application\Outbox\OutboxMessage;

/**
 * Takes out of stock the spare part a technician says went into a work order (FR-MTC-009): one issue movement, the use being the source, so a message handled twice issues nothing twice.
 * The part is already in the machine, so the balance may go below zero unless the location or the category blocks it, and then the message waits for someone to look at it. The
 * inventory reads the event only, never maintenance.
 */
final readonly class MaintenancePartConsumer implements OutboxConsumer
{
    public const EVENT = 'maintenance.part.used';

    public function __construct(private InventoryStore $inventory, private StockPoster $poster) {}

    public function name(): string
    {
        return 'inventory.maintenance-part';
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
        $item = $this->inventory->item($property, strtolower((string) $d['item_id'])) ?? throw Refusal::notFound('Item not found.');
        $this->poster->post($property, (string) $d['actor_id'], $item, $location, 'issue', (string) $d['unit'], (int) $d['quantity_milli'], 'maintenance', (string) $d['work_order_number'], null, 'maintenance', strtolower((string) $d['use_id']), null, null, true, null, null, null, true);
    }
}
