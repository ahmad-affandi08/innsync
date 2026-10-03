<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Shared\Application\Documents\DocumentNumbers;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxConsumer;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Application\Time\Clock;
use DateTimeZone;

/**
 * The bills the F&B outlets settle (FR-FIN-001, FR-FIN-006). Each is kept once as a fact, with the base, the service charge and the tax apart and what was paid by each
 * method. The revenue of a business date then is what the night audit reports plus the bills settled on that date, except those charged to a room, whose revenue is already on
 * the folios. A day, once booked, never changes: a bill that reaches finance after its day was booked is kept as late and raises a reconciliation exception to settle with a
 * correction of that day, so the revenue is never lost and never silently moved. Finance reads only the event, never the point of sale.
 */
final readonly class FnbSalesRevenueConsumer implements OutboxConsumer
{
    public const SETTLED_EVENT = 'fnb.bill.settled';

    public function __construct(private RevenueStore $store, private ExceptionStore $exceptions, private DocumentNumbers $numbers, private IdentifierGenerator $ids, private Clock $clock) {}

    public function name(): string
    {
        return 'finance.fnb-revenue';
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
        $date = (string) $d['settled_business_date'];
        $paid = ['cash' => 0, 'card' => 0, 'qris' => 0, 'room' => 0];

        foreach ($d['payments'] ?? [] as $p) {
            if (isset($paid[(string) $p['method']])) {
                $paid[(string) $p['method']] += (int) $p['amount_minor'];
            }
        }

        $late = $this->store->dayExists($property, $date);
        $at = $message->occurredAt->setTimezone(new DateTimeZone('UTC'));

        $added = $this->store->addPosSale($property, [
            'id' => $this->ids->next(), 'bill_id' => (string) $d['bill_id'], 'bill_number' => (string) $d['bill_number'], 'outlet_id' => (string) $d['outlet_id'], 'outlet_code' => (string) $d['outlet_code'], 'source' => (string) $d['source'],
            'business_date' => $date, 'currency' => (string) $d['currency'], 'base_minor' => (int) $d['base_minor'], 'service_charge_minor' => (int) $d['service_charge_minor'], 'tax_minor' => (int) $d['tax_minor'], 'total_minor' => (int) $d['total_minor'],
            'cash_minor' => $paid['cash'], 'card_minor' => $paid['card'], 'qris_minor' => $paid['qris'], 'room_minor' => $paid['room'], 'late' => $late, 'event_id' => strtolower($message->eventId), 'correlation_id' => strtolower($message->correlationId),
            'actor_id' => isset($d['actor_id']) ? strtolower((string) $d['actor_id']) : null, 'occurred_at' => $at->format('Y-m-d H:i:s.u'),
        ], $this->clock->nowUtc());

        if ($added && $late && $paid['room'] === 0 && isset($d['actor_id'])) {
            $this->exceptions->add($property, [
                'id' => $this->ids->next(), 'number' => $this->numbers->next($property, 'EXC'), 'kind' => 'late_sale', 'business_date' => $date, 'amount_minor' => (int) $d['total_minor'], 'method' => null, 'reference' => (string) $d['bill_number'], 'folio_ref' => null,
                'description' => "Bill {$d['bill_number']} of {$d['outlet_code']} reached finance after the revenue of {$date} was booked. Correct that day.", 'created_by' => strtolower((string) $d['actor_id']),
            ], $this->clock->nowUtc());
        }
    }
}
