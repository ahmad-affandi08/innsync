<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Rates;

use App\Modules\Property\Domain\Rates\ChargeSchemeConfig;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface ChargeSchemeRepository
{
    /** @return bool false when the scope already has a scheme starting that date */
    public function add(PropertyId $property, ChargeSchemeConfig $config, string $reason, string $actorId, DateTimeImmutable $at): bool;

    /** Newest start date first. @return list<ChargeSchemeConfig> */
    public function forScope(PropertyId $property, string $scope): array;

    /** The scopes that have a scheme. @return list<string> */
    public function scopes(PropertyId $property): array;
}
