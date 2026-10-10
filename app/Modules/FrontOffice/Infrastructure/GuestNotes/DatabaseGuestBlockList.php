<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\GuestNotes;

use App\Modules\FrontOffice\Application\GuestNotes\GuestBlockList;
use App\Modules\FrontOffice\Application\GuestNotes\GuestKey;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseGuestBlockList implements GuestBlockList
{
    public function blocks(PropertyId $property, string $name, ?string $phone): bool
    {
        return DB::table('guest_notes')->where('property_id', $property->toString())->where('guest_key', GuestKey::of($name, $phone))->where('flag', 'do_not_rent')->exists();
    }
}
