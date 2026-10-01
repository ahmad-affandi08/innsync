<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Domain\Folios;

/**
 * How money was received (FR-FO-024): cash, QRIS, a card through an EDC machine, a bank transfer, or an online payment from
 * a booking channel. No card number is ever stored (NFR-09); only the slip or approval reference the staff reads from the EDC.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Qris = 'qris';
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case Online = 'online';

    public function needsReference(): bool
    {
        return $this !== self::Cash;
    }
}
