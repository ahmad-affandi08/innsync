<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Domain\Folios;

enum EntryType: string
{
    case Charge = 'charge';
    case Payment = 'payment';
    case Refund = 'refund';
    case Reversal = 'reversal';
}
