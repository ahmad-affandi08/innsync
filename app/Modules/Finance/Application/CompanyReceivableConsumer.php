<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Shared\Application\Documents\DocumentNumbers;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxConsumer;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Application\Time\Clock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Accounts receivable from what the front office publishes (FR-FIN-014, FR-FIN-006). When a guest of a company or a travel agent checks out and the
 * company's folio still holds a balance, that balance becomes a receivable of the customer made for the company, due after the customer's payment terms.
 * It is made once per folio however often the event is delivered. Finance reads only the event, never the folio.
 */
final readonly class CompanyReceivableConsumer implements OutboxConsumer
{
    public const EVENT = 'frontoffice.company_folio.billable';

    /** The payment terms a customer starts with, in days; the owner changes them per customer. */
    public const DEFAULT_TERMS_DAYS = 30;

    public function __construct(private ReceivableStore $store, private DocumentNumbers $numbers, private IdentifierGenerator $ids, private Clock $clock) {}

    public function name(): string
    {
        return 'finance.accounts-receivable';
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

        if ($this->store->hasReceivable($property, 'company_folio', (string) $d['folio_id']) || (int) $d['balance_minor'] <= 0) {
            return;
        }

        $now = $this->clock->nowUtc();
        $customer = $this->store->customerOfCompany($property, (string) $d['company_id']);

        if ($customer === null) {
            $code = strtoupper((string) $d['company_code']);
            $row = ['id' => $this->ids->next(), 'code' => $code, 'name' => mb_substr((string) $d['company_name'], 0, 120), 'kind' => $d['company_kind'] === 'agent' ? 'agent' : 'company', 'terms_days' => self::DEFAULT_TERMS_DAYS, 'company_id' => (string) $d['company_id']];

            if (! $this->store->addCustomer($property, $row, $now)) {
                // The code is taken by a customer finance made by hand: keep the company's own customer apart.
                $row['code'] = substr($code, 0, 14).'-'.strtoupper(substr((string) $d['company_id'], -5));
                $this->store->addCustomer($property, $row, $now);
            }

            $customer = $this->store->customerOfCompany($property, (string) $d['company_id']);
        }

        if ($customer === null) {
            return;
        }

        $issued = (string) $d['business_date'];
        $due = (new DateTimeImmutable($issued, new DateTimeZone('UTC')))->modify('+'.(int) $customer['terms_days'].' days')->format('Y-m-d');
        $at = $message->occurredAt->setTimezone(new DateTimeZone('UTC'));

        $this->store->addReceivable($property, [
            'id' => $this->ids->next(), 'number' => $this->numbers->next($property, 'AR'), 'customer_id' => $customer['id'], 'source_type' => 'company_folio', 'source_id' => (string) $d['folio_id'], 'source_number' => (string) $d['folio_number'],
            'description' => mb_substr('Stay '.$d['reservation_number'].' · '.$d['guest_name'], 0, 200), 'reference' => null, 'issued_on' => $issued, 'due_date' => $due, 'business_date' => $issued, 'amount_minor' => (int) $d['balance_minor'],
            'currency' => (string) $d['currency'], 'event_id' => strtolower($message->eventId), 'correlation_id' => strtolower($message->correlationId), 'actor_id' => isset($d['actor_id']) ? strtolower((string) $d['actor_id']) : null,
            'occurred_at' => $at->format('Y-m-d H:i:s.u'),
        ], $now);
    }
}
