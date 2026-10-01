<?php

declare(strict_types=1);

namespace App\Modules\Property\Infrastructure\Rates;

use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class DatabasePropertyCurrencyReader implements PropertyCurrencyReader
{
    public function currencyOf(PropertyId $property): string
    {
        $code = DB::table('properties')->where('id', $property->toString())->value('currency_code');

        return is_string($code) ? $code : throw new RuntimeException('The property has no currency.');
    }
}
