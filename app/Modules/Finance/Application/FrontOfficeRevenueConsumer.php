<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxConsumer;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Application\Time\Clock;
use DateTimeZone;

/**
 * Revenue and cash from what the front office publishes (FR-FIN-001, FR-FIN-003, FR-FIN-006). A night audit books the revenue of its business date, base,
 * service charge and tax apart, by posting source and by payment method; a closed cashier shift books the cash it took in, ready to be received. Each is
 * booked once however often the event is delivered. Finance reads only the event, never the folio.
 */
final readonly class FrontOfficeRevenueConsumer implements OutboxConsumer
{
    public const NIGHT_AUDIT_EVENT = 'frontoffice.night_audit.completed';

    public const SHIFT_EVENT = 'frontoffice.cashier.shift.closed';

    /** Posting sources the front office owns, as the outlet each belongs to when no revenue outlet claims it. */
    private const BUILT_IN = ['night_audit' => 'rooms', 'laundry' => 'laundry'];

    public function __construct(private RevenueStore $store, private IdentifierGenerator $ids, private Clock $clock) {}

    public function name(): string
    {
        return 'finance.revenue';
    }

    public function supports(string $eventType): bool
    {
        return in_array($eventType, [self::NIGHT_AUDIT_EVENT, self::SHIFT_EVENT], true);
    }

    public function consume(OutboxMessage $message): void
    {
        $event = $message->event;
        $d = $event->data;
        $at = $message->occurredAt->setTimezone(new DateTimeZone('UTC'));
        $facts = ['event_id' => strtolower($message->eventId), 'correlation_id' => strtolower($message->correlationId), 'occurred_at' => $at->format('Y-m-d H:i:s.u')];

        if ($event->eventType === self::SHIFT_EVENT) {
            $this->store->addCashShift($event->propertyId, [
                'id' => $this->ids->next(), 'shift_id' => (string) $d['shift_id'], 'number' => (string) $d['number'], 'cashier_id' => strtolower((string) $d['cashier_id']), 'closed_by' => isset($d['actor_id']) ? strtolower((string) $d['actor_id']) : null,
                'closed_business_date' => (string) $d['closed_business_date'], 'currency' => (string) $d['currency'], 'opening_float_minor' => (int) $d['opening_float_minor'], 'expected_cash_minor' => (int) $d['expected_cash_minor'],
                'counted_cash_minor' => (int) $d['counted_cash_minor'], 'shift_variance_minor' => (int) $d['variance_minor'], 'drops_minor' => (int) $d['drops_minor'], 'cash_net_minor' => (int) $d['cash_net_minor'], ...$facts,
            ], $this->clock->nowUtc());

            return;
        }

        $day = $this->ids->next();
        $outlets = $this->store->outletsBySource($event->propertyId);
        $lines = [];
        $sum = ['base_minor' => 0, 'service_charge_minor' => 0, 'tax_minor' => 0, 'total_minor' => 0];

        foreach ($d['revenue_by_source'] ?? [] as $line) {
            $source = (string) $line['source'];
            $outlet = $outlets[$source] ?? null;
            $lines[] = [
                'id' => $this->ids->next(), 'day_id' => $day, 'business_date' => (string) $d['business_date'], 'source' => $source,
                'outlet_code' => $outlet['code'] ?? (self::BUILT_IN[$source] ?? 'other'), 'outlet_name' => $outlet['name'] ?? null,
                'base_minor' => (int) $line['base_minor'], 'service_charge_minor' => (int) $line['service_charge_minor'], 'tax_minor' => (int) $line['tax_minor'], 'total_minor' => (int) $line['total_minor'],
            ];

            foreach ($sum as $key => $value) {
                $sum[$key] = $value + (int) $line[$key];
            }
        }

        $payments = [];
        $collected = 0;

        foreach ($d['payments'] ?? [] as $p) {
            $payments[] = ['day_id' => $day, 'business_date' => (string) $d['business_date'], 'method' => (string) $p['method'], 'received_minor' => (int) $p['received_minor'], 'paid_back_minor' => (int) $p['paid_back_minor'], 'entries' => (int) $p['count']];
            $collected += (int) $p['received_minor'] - (int) $p['paid_back_minor'];
        }

        $this->store->addDay($event->propertyId, [
            'id' => $day, 'business_date' => (string) $d['business_date'], 'currency' => (string) ($d['currency'] ?? ''), 'night_audit_id' => (string) $d['night_audit_id'], ...$sum, 'collected_minor' => $collected,
            'actor_id' => isset($d['actor_id']) ? strtolower((string) $d['actor_id']) : null, ...$facts,
        ], $lines, $payments, $this->clock->nowUtc());
    }
}
