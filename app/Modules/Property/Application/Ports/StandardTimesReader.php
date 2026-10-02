<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Ports;

use App\Shared\Domain\Tenancy\PropertyId;

/** The standard check-in and check-out times of a property (`HH:MM` on its wall clock). Read-only. */
interface StandardTimesReader
{
    /** @return array{check_in: string, check_out: string} */
    public function standardTimes(PropertyId $property): array;
}
