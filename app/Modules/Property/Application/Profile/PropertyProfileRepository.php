<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Profile;

use App\Shared\Domain\Tenancy\PropertyId;

interface PropertyProfileRepository
{
    /** @return array{profile: ?string, disabled: list<string>, features: list<string>} */
    public function get(PropertyId $property): array;

    /** @param list<string> $disabled */
    public function save(PropertyId $property, string $profile, array $disabled, string $actorId): void;

    /** @param list<string> $enabled the optional features that are switched on; every other one is switched off */
    public function saveFeatures(PropertyId $property, array $enabled, string $actorId): void;
}
