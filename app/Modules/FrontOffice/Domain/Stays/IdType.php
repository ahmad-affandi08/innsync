<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Domain\Stays;

/** The identity document a guest registered with (FR-FO-010). */
enum IdType: string
{
    case Ktp = 'ktp';
    case Passport = 'passport';
    case Sim = 'sim';
    case Kitas = 'kitas';
    case Other = 'other';
}
