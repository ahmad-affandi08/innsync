<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Application;

use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxConsumer;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Time\Clock;
use RuntimeException;

/**
 * Turns the dishes of a settled bill into what they took out of the pantry (FR-KIT-004, FR-KIT-013). Each sold line is consumed by the recipe in force on the day the bill was settled,
 * once for each line and ingredient, and the version used is kept with it. The inventory is told what to take out; the kitchen never changes stock itself. A line whose dish has
 * no recipe takes nothing. A recipe in force with no location to take from is not guessed at: the message is not taken, and waits until the location is set.
 */
final readonly class SaleConsumptionConsumer implements OutboxConsumer
{
    public const SETTLED_EVENT = 'fnb.bill.settled';

    public const POSTED_EVENT = 'kitchen.consumption.posted';

    public function __construct(private RecipeStore $recipes, private TicketStore $tickets, private OutboxPublisher $outbox, private IdentifierGenerator $ids, private Clock $clock) {}

    public function name(): string
    {
        return 'kitchen.sale-consumption';
    }

    public function supports(string $eventType): bool
    {
        return $eventType === self::SETTLED_EVENT;
    }

    public function consume(OutboxMessage $message): void
    {
        $event = $message->event;
        $d = $event->data;
        $property = $event->propertyId;
        $lines = $d['lines'] ?? [];
        $versions = $this->recipes->inForce($property, array_values(array_unique(array_map(static fn (array $l): string => strtolower((string) $l['item_id']), $lines))), (string) $d['settled_business_date']);

        if ($versions === []) {
            return;
        }

        $location = $this->tickets->settings($property)['stock_location_id'] ?? null;

        if ($location === null) {
            throw new RuntimeException('Recipes are in force but the kitchen has no stock location to take the ingredients from.');
        }

        $rows = [];

        foreach ($lines as $l) {
            $v = $versions[strtolower((string) $l['item_id'])] ?? null;

            if ($v === null) {
                continue;
            }

            foreach ($v['lines'] as $r) {
                $rows[] = [
                    'id' => $this->ids->next(), 'bill_id' => strtolower((string) $d['bill_id']), 'bill_number' => (string) $d['bill_number'], 'line_id' => strtolower((string) $l['line_id']), 'menu_item_id' => strtolower((string) $l['item_id']), 'item_name' => (string) $l['name'],
                    'portions' => (int) $l['quantity'], 'version_id' => $v['id'], 'version' => (int) $v['version'], 'business_date' => (string) $d['settled_business_date'], 'ingredient_item_id' => $r['ingredient_item_id'], 'ingredient_name' => $r['ingredient_name'], 'unit' => $r['unit'],
                    'quantity_milli' => RecipeService::consumed((int) $r['quantity_milli'], (int) $r['waste_bp'], (int) $v['yield_portions'], (int) $l['quantity']),
                ];
            }
        }

        $added = $this->recipes->addConsumptions($property, $rows, $this->clock->nowUtc());

        if ($added !== []) {
            $this->outbox->publish(new OutboxEvent($property, self::POSTED_EVENT, strtolower((string) $d['bill_id']), 1, [
                'bill_id' => strtolower((string) $d['bill_id']), 'bill_number' => (string) $d['bill_number'], 'location_id' => $location, 'actor_id' => (string) ($d['actor_id'] ?? ''),
                'entries' => array_map(static fn (array $c): array => ['line_id' => $c['line_id'], 'item_id' => $c['ingredient_item_id'], 'unit' => $c['unit'], 'quantity_milli' => $c['quantity_milli'], 'version' => $c['version']], $added),
            ]));
        }
    }
}
