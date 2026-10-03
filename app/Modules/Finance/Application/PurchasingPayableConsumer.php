<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxConsumer;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Application\Time\Clock;
use DateTimeZone;

/**
 * Accounts payable from what Purchasing publishes (FR-FIN-011, FR-FIN-006). A recognised supplier invoice becomes a payable with its supplier, document,
 * due date and amount; a return that came with a supplier credit note becomes a credit to set against payables. Each is made once per source document
 * however often the event is delivered. Finance reads only the event; it never looks into the purchasing tables.
 */
final readonly class PurchasingPayableConsumer implements OutboxConsumer
{
    public const INVOICE_EVENT = 'purchasing.invoice.recognised';

    public const RETURN_EVENT = 'purchasing.return.posted';

    public function __construct(private PayableStore $store, private IdentifierGenerator $ids, private Clock $clock) {}

    public function name(): string
    {
        return 'finance.accounts-payable';
    }

    public function supports(string $eventType): bool
    {
        return in_array($eventType, [self::INVOICE_EVENT, self::RETURN_EVENT], true);
    }

    public function consume(OutboxMessage $message): void
    {
        $event = $message->event;
        $d = $event->data;
        $at = $message->occurredAt->setTimezone(new DateTimeZone('UTC'));
        $common = [
            'supplier_id' => (string) $d['supplier_id'], 'supplier_code' => (string) ($d['supplier_code'] ?? ''), 'supplier_name' => (string) ($d['supplier_name'] ?? ''), 'business_date' => (string) $d['business_date'],
            'currency' => (string) $d['currency'], 'event_id' => strtolower($message->eventId), 'correlation_id' => strtolower($message->correlationId), 'occurred_at' => $at->format('Y-m-d H:i:s.u'),
        ];

        if ($event->eventType === self::INVOICE_EVENT) {
            $this->store->addPayable($event->propertyId, [
                'id' => $this->ids->next(), ...$common, 'source_type' => 'supplier_invoice', 'source_id' => (string) $d['invoice_id'], 'source_number' => (string) $d['number'], 'document_number' => (string) $d['invoice_number'],
                'order_number' => $d['order_number'] ?? null, 'issued_on' => (string) $d['invoice_date'], 'due_date' => (string) $d['due_date'], 'amount_minor' => (int) $d['total_minor'], 'tax_minor' => (int) $d['tax_minor'],
                'actor_id' => isset($d['actor_id']) ? strtolower((string) $d['actor_id']) : null,
            ], $this->clock->nowUtc());

            return;
        }

        if ((int) ($d['credit_total_minor'] ?? 0) > 0 && ($d['credit_note_number'] ?? null) !== null) {
            $this->store->addCredit($event->propertyId, [
                'id' => $this->ids->next(), ...$common, 'source_type' => 'purchase_return', 'source_id' => (string) $d['return_id'], 'source_number' => (string) $d['number'], 'credit_note_number' => (string) $d['credit_note_number'],
                'amount_minor' => (int) $d['credit_total_minor'],
            ], $this->clock->nowUtc());
        }
    }
}
