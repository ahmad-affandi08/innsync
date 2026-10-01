<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Domain\Folios;

use App\Shared\Domain\Money\Money;

/**
 * The bill of one window of a stay (FR-FO-020). It is only a header: the truth is the ordered, immutable list of postings,
 * and `balance` is their sum (charges minus payments). Open until closed; a closed folio refuses new postings.
 */
final readonly class Folio
{
    public function __construct(
        public string $id,
        public string $number,
        public string $reservationId,
        public int $window,
        public string $label,
        public string $currency,
        public bool $isClosed,
        public Money $balance,
        public int $lastSeq,
        public int $lockVersion,
    ) {}

    public function assertOpen(): void
    {
        if ($this->isClosed) {
            throw FolioRuleViolation::closed();
        }
    }

    public function assertCurrency(Money $money): void
    {
        if ($money->currency !== $this->currency) {
            throw FolioRuleViolation::currency();
        }
    }

    /** Product statuses of the PRD: open, partially settled, settled, closed. Derived, never stored (except closed). */
    public function statusFor(bool $hasPayments): string
    {
        return match (true) {
            $this->isClosed => 'closed',
            $hasPayments && $this->balance->amountMinor === 0 => 'settled',
            $hasPayments && $this->balance->amountMinor > 0 => 'partially_settled',
            default => 'open',
        };
    }
}
