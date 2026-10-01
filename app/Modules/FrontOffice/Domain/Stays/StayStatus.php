<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Domain\Stays;

enum StayStatus: string
{
    case InHouse = 'in_house';
    case CheckedOut = 'checked_out';
}
