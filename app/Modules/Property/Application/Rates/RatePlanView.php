<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Rates;

/** What other contexts may know about a rate plan. */
final readonly class RatePlanView
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public string $kind,
        public ?string $inclusions,
        public bool $pricesIncludeCharges,
    ) {}
}
