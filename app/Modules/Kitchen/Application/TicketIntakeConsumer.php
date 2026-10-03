<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Application;

use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxConsumer;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Application\Time\Clock;
use DateTimeZone;

/**
 * Puts what the point of sale sends on the screens (FR-KIT-001, FR-KIT-002): one ticket for each station of each send, with the table or room it goes to. A line no station
 * prepares never reaches a screen. A line that is voided, or a bill that is cancelled, takes its dishes off the screens, so nobody cooks what will not be paid for. The
 * kitchen reads the events only, never the point of sale.
 */
final readonly class TicketIntakeConsumer implements OutboxConsumer
{
    public const SENT_EVENT = 'fnb.order.sent';

    public const VOIDED_EVENT = 'fnb.line.voided';

    public const CANCELLED_EVENT = 'fnb.bill.cancelled';

    public const STATIONS = ['kitchen', 'bar'];

    public function __construct(private TicketStore $store, private IdentifierGenerator $ids, private Clock $clock) {}

    public function name(): string
    {
        return 'kitchen.tickets';
    }

    public function supports(string $eventType): bool
    {
        return in_array($eventType, [self::SENT_EVENT, self::VOIDED_EVENT, self::CANCELLED_EVENT], true);
    }

    public function consume(OutboxMessage $message): void
    {
        $event = $message->event;
        $d = $event->data;
        $now = $this->clock->nowUtc();

        match ($event->eventType) {
            self::SENT_EVENT => $this->intake($message),
            self::VOIDED_EVENT => $this->store->cancelLines($event->propertyId, [strtolower((string) $d['line_id'])], $now),
            default => $this->store->cancelBill($event->propertyId, strtolower((string) $d['bill_id']), $now),
        };
    }

    private function intake(OutboxMessage $message): void
    {
        $event = $message->event;
        $d = $event->data;
        $at = $message->occurredAt->setTimezone(new DateTimeZone('UTC'));
        $byStation = [];

        foreach ($d['lines'] ?? [] as $line) {
            if (in_array($line['station'] ?? null, self::STATIONS, true)) {
                $byStation[$line['station']][] = $line;
            }
        }

        $table = isset($d['table']) && $d['table'] !== '' ? (string) $d['table'] : null;
        $room = isset($d['room']) && $d['room'] !== '' ? (string) $d['room'] : null;

        foreach ($byStation as $station => $lines) {
            $this->store->addTicket($event->propertyId, [
                'id' => $this->ids->next(), 'outlet_id' => (string) $d['outlet_id'], 'outlet_code' => (string) $d['outlet_code'], 'station' => $station, 'batch_id' => strtolower((string) $d['batch_id']), 'batch_number' => (int) $d['batch_number'],
                'bill_id' => strtolower((string) $d['bill_id']), 'bill_number' => (string) $d['bill_number'], 'place_kind' => $table !== null ? 'table' : ($room !== null ? 'room' : 'counter'), 'place' => $table ?? $room,
                'business_date' => (string) $d['business_date'], 'received_at' => $at->format('Y-m-d H:i:s.u'),
            ], array_map(fn (array $l): array => [
                'id' => $this->ids->next(), 'line_id' => strtolower((string) $l['line_id']), 'name' => (string) $l['name'], 'variant' => $l['variant'] ?? null, 'modifiers' => array_values(array_map('strval', $l['modifiers'] ?? [])), 'quantity' => (int) $l['quantity'], 'note' => $l['note'] ?? null,
            ], $lines), $this->clock->nowUtc());
        }
    }
}
