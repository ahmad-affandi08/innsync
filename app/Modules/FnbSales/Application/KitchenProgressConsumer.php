<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Application;

use App\Shared\Application\Outbox\OutboxConsumer;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Application\Time\Clock;

/** How far the kitchen and the bar are with each line (FR-KIT-002): the point of sale shows it to the waiters. It reads the event only, never the tickets. */
final readonly class KitchenProgressConsumer implements OutboxConsumer
{
    public const PROGRESS_EVENT = 'kitchen.ticket.progressed';

    public function __construct(private BillStore $bills, private Clock $clock) {}

    public function name(): string
    {
        return 'fnb.kitchen-progress';
    }

    public function supports(string $eventType): bool
    {
        return $eventType === self::PROGRESS_EVENT;
    }

    public function consume(OutboxMessage $message): void
    {
        $d = $message->event->data;
        $status = (string) ($d['status'] ?? '');

        if (in_array($status, ['preparing', 'ready', 'served'], true)) {
            $this->bills->setPrepStatus($message->event->propertyId, array_values(array_map('strtolower', array_map('strval', $d['line_ids'] ?? []))), $status, $this->clock->nowUtc());
        }
    }
}
