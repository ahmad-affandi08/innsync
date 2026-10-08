<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\GuestDirectory;

use App\Shared\Domain\Tenancy\PropertyId;

interface GuestDirectoryReader
{
    /** @return list<array{guest_name: string, stays: int, nights: int, last_arrival: string, last_departure: string, last_reservation_id: string, upcoming: int, flag: string|null, note: string|null}> */
    public function guests(PropertyId $property, string $query, int $limit): array;
}
