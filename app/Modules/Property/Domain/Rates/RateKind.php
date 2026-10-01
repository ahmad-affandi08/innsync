<?php

declare(strict_types=1);

namespace App\Modules\Property\Domain\Rates;

/** What a rate plan is for. Complimentary is not a kind: it is a folio attribute (BR-008). */
enum RateKind: string
{
    case Public = 'public';
    case Corporate = 'corporate';
    case Package = 'package';
    case Ota = 'ota';
    case Promotion = 'promotion';
}
