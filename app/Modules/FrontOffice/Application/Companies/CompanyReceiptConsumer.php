<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Companies;

use App\Modules\FrontOffice\Application\Folios\FolioLedger;
use App\Modules\FrontOffice\Application\Folios\FolioRepository;
use App\Modules\FrontOffice\Domain\Folios\PaymentMethod;
use App\Modules\FrontOffice\Domain\Folios\Posting;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Outbox\OutboxConsumer;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Application\Time\Clock;
use App\Shared\Domain\Money\Money;
use App\Shared\Domain\Time\BusinessDate;
use DateTimeImmutable;

/**
 * Settles a company's folio with what finance received (FR-FIN-014, FR-FO-035). When finance records a receipt against a receivable that was billed from a
 * company folio, the amount is posted on that folio as a settlement payment, once per receipt, and the folio is closed when nothing is owed any more. A
 * receipt is never posted for more than the folio still owes: if the front office already took the money, the folio is left as it is and the skipped
 * receipt is audited.
 */
final readonly class CompanyReceiptConsumer implements OutboxConsumer
{
    public const EVENT = 'finance.receivable.received';

    public const CODE = 'AR_RECEIPT';

    public const SOURCE = 'ar_receipt';

    public function __construct(private FolioRepository $folios, private FolioLedger $ledger, private AuditTrail $audit, private Clock $clock) {}

    public function name(): string
    {
        return 'frontoffice.company-receipts';
    }

    public function supports(string $eventType): bool
    {
        return $eventType === self::EVENT;
    }

    public function consume(OutboxMessage $message): void
    {
        $property = $message->event->propertyId;
        $d = $message->event->data;

        if (($d['source_type'] ?? null) !== 'company_folio') {
            return;
        }

        $receiptId = strtolower((string) $d['receipt_id']);

        if ($this->folios->findBySource($property, self::SOURCE, $receiptId) !== null) {
            return;
        }

        $actor = strtolower((string) $d['actor_id']);
        $folio = $this->folios->lock($property, strtolower((string) $d['source_id']));

        if ($folio === null) {
            return;
        }

        $owed = $folio->balance->amountMinor;
        $amount = min((int) $d['amount_minor'], $owed);

        if ($folio->isClosed || $amount <= 0) {
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'folio.receipt.skipped', 'folio', $folio->id, null, ['receipt' => $d['number'], 'amount_minor' => (int) $d['amount_minor'], 'owed_minor' => max(0, $owed), 'closed' => $folio->isClosed], 'The folio no longer owes this amount.'));

            return;
        }

        $method = ($d['method'] ?? '') === 'online' ? PaymentMethod::Online : PaymentMethod::BankTransfer;
        $result = $this->ledger->post($property, $folio->id, fn (BusinessDate $date, DateTimeImmutable $at): Posting => Posting::payment(
            $this->ledger->newId(), self::CODE, mb_substr('Receipt '.$d['number'].' from '.$d['customer_code'], 0, 200), Money::ofMinor($amount, $folio->currency), $method, (string) $d['reference'], 'settlement', $date, $at, $actor, self::SOURCE, $receiptId,
        ));
        $this->audit->record(new AuditEntry($property->toString(), $actor, 'folio.payment.posted', 'folio', $folio->id, null, ['posting' => ['type' => 'payment', 'code' => self::CODE, 'total_minor' => -$amount, 'currency' => $folio->currency, 'receipt' => $d['number']]]));

        if ($result['balance']->amountMinor === 0) {
            $closing = $this->folios->lock($property, $folio->id);

            if ($closing !== null && ! $closing->isClosed && $this->folios->close($property, $closing, $actor, $this->clock->nowUtc())) {
                $this->audit->record(new AuditEntry($property->toString(), $actor, 'folio.closed', 'folio', $folio->id, ['status' => 'open', 'balance_minor' => 0], ['status' => 'closed']));
            }
        }
    }
}
