<?php

declare(strict_types=1);

namespace App\Modules\Property\Infrastructure\Persistence;

use App\Modules\Property\Application\Profile\PropertyProfileRepository;
use App\Shared\Application\Setup\OptionalFeatures;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Facades\DB;

final class DatabasePropertyProfileRepository implements PropertyProfileRepository
{
    public function get(PropertyId $property): array
    {
        $row = DB::table('property_profiles')->where('property_id', $property->toString())->first(['profile', 'disabled_modules']);
        $list = $row !== null && is_string($row->disabled_modules) ? json_decode($row->disabled_modules, true) : null;

        $features = DB::table('property_features')->where('property_id', $property->toString())->where('enabled', true)->orderBy('feature')->pluck('feature')->map(static fn ($f): string => (string) $f)->all();

        return ['profile' => $row?->profile, 'disabled' => is_array($list) ? array_values(array_filter($list, 'is_string')) : [], 'features' => $features];
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

    public function saveFeatures(PropertyId $property, array $enabled, string $actorId): void
    {
        $now = now();

        foreach (OptionalFeatures::ALL as $feature) {
            $on = in_array($feature, $enabled, true);
            $where = ['property_id' => $property->toString(), 'feature' => $feature];

            if (DB::table('property_features')->where($where)->exists()) {
                DB::table('property_features')->where($where)->update(['enabled' => $on, 'updated_by' => strtolower($actorId), 'updated_at' => $now]);

                continue;
            }

            DB::table('property_features')->insert($where + ['enabled' => $on, 'updated_by' => strtolower($actorId), 'created_at' => $now, 'updated_at' => $now]);
        }
    }
}
