<?php

declare(strict_types=1);

namespace App\Modules\Property\Infrastructure\Settings;

use App\Modules\Property\Application\Ports\StandardTimesReader;
use App\Modules\Property\Application\Settings\PropertySettingsRepository;
use App\Modules\Property\Domain\Settings\PropertySettings;
use App\Shared\Domain\Tenancy\PropertyId;

final readonly class EloquentStandardTimesReader implements StandardTimesReader
{
    public function __construct(private PropertySettingsRepository $settings) {}

    public function standardTimes(PropertyId $property): array
    {
        $settings = $this->settings->find($property) ?? PropertySettings::defaults();

        return ['check_in' => $settings->checkInTime->value, 'check_out' => $settings->checkOutTime->value];
    }
}
