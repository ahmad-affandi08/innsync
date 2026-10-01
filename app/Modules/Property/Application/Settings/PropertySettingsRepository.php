<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Settings;

use App\Modules\Property\Domain\Settings\PropertySettings;
use App\Shared\Domain\Tenancy\PropertyId;

interface PropertySettingsRepository
{
    /** Null when the property has never saved settings (defaults apply). */
    public function find(PropertyId $property): ?PropertySettings;

    /**
     * Writes the settings if the stored lock version still equals `$expectedLockVersion` (0 with no row yet).
     *
     * @return bool false when someone else saved first
     */
    public function save(PropertyId $property, PropertySettings $settings, int $expectedLockVersion, string $actorId): bool;
}
