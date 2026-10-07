<?php

declare(strict_types=1);

namespace App\Modules\Property\Infrastructure\Persistence;

use App\Modules\Property\Application\Profile\PropertyProfileRepository;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Facades\DB;

final class DatabasePropertyProfileRepository implements PropertyProfileRepository
{
    public function get(PropertyId $property): array
    {
        $row = DB::table('property_profiles')->where('property_id', $property->toString())->first(['profile', 'disabled_modules']);
        $list = $row !== null && is_string($row->disabled_modules) ? json_decode($row->disabled_modules, true) : null;

        return ['profile' => $row?->profile, 'disabled' => is_array($list) ? array_values(array_filter($list, 'is_string')) : []];
    }

    public function save(PropertyId $property, string $profile, array $disabled, string $actorId): void
    {
        $now = now();
        $values = ['profile' => $profile, 'disabled_modules' => json_encode(array_values($disabled), JSON_THROW_ON_ERROR), 'updated_by' => strtolower($actorId), 'updated_at' => $now];

        if (DB::table('property_profiles')->where('property_id', $property->toString())->exists()) {
            DB::table('property_profiles')->where('property_id', $property->toString())->update($values + ['lock_version' => DB::raw('lock_version + 1')]);

            return;
        }

        DB::table('property_profiles')->insert($values + ['property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $now]);
    }
}
