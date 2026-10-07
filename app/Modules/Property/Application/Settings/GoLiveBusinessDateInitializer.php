<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Settings;

use App\Shared\Application\Deployment\GoLiveBusinessDate;
use App\Shared\Domain\Tenancy\PropertyId;

final readonly class GoLiveBusinessDateInitializer implements GoLiveBusinessDate
{
    public function __construct(private PropertySettingsService $settings) {}

    public function initializeIfMissing(PropertyId $property, string $actorId, string $date, string $reason): void
    {
        $current = $this->settings->get($property);

        if ($current->businessDate !== null) {
            return;
        }

        $this->settings->initializeBusinessDate($property, $actorId, $date, $current->lockVersion, $reason);
    }
}
