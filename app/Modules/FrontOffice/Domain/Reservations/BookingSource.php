<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Domain\Reservations;

enum BookingSource: string
{
    case Direct = 'direct';
    case Phone = 'phone';
    case Ota = 'ota';
    case Corporate = 'corporate';
    case WalkIn = 'walk_in';
}
