<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Rates;

use App\Shared\Domain\Tenancy\PropertyId;

interface PropertyCurrencyReader
{
    /** ISO 4217 code of the property's accounting currency. */
    public function currencyOf(PropertyId $property): string;
}
