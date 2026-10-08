<?php

declare(strict_types=1);

namespace App\Modules\Property\Infrastructure\Persistence;

use App\Modules\Property\Application\Branding\PropertyLogoStore;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Facades\DB;

final class DatabasePropertyLogoStore implements PropertyLogoStore
{
    public function find(PropertyId $property): ?array
    {
        $row = DB::table('property_logos')->where('property_id', $property->toString())->first(['mime', 'content', 'sha256']);

        return $row === null ? null : ['mime' => (string) $row->mime, 'content' => (string) $row->content, 'sha256' => (string) $row->sha256];
    }

    public function hash(PropertyId $property): ?string
    {
        $hash = DB::table('property_logos')->where('property_id', $property->toString())->value('sha256');

        return $hash === null ? null : (string) $hash;
    }

    public function save(PropertyId $property, string $mime, string $content, string $sha256, string $actorId): void
    {
        $now = now();
        $exists = DB::table('property_logos')->where('property_id', $property->toString())->exists();

        if ($exists) {
            DB::table('property_logos')->where('property_id', $property->toString())->update(['mime' => $mime, 'content' => $content, 'sha256' => $sha256, 'updated_by' => $actorId, 'updated_at' => $now]);

            return;
        }

        DB::table('property_logos')->insert(['property_id' => $property->toString(), 'mime' => $mime, 'content' => $content, 'sha256' => $sha256, 'updated_by' => $actorId, 'created_at' => $now, 'updated_at' => $now]);
    }

    public function remove(PropertyId $property): bool
    {
        return DB::table('property_logos')->where('property_id', $property->toString())->delete() > 0;
    }

    public function poweredBy(PropertyId $property): bool
    {
        $show = DB::table('property_branding_settings')->where('property_id', $property->toString())->value('show_powered_by');

        return $show === null ? true : (bool) $show;
    }

    public function setPoweredBy(PropertyId $property, bool $show, string $actorId): void
    {
        $now = now();

        if (DB::table('property_branding_settings')->where('property_id', $property->toString())->exists()) {
            DB::table('property_branding_settings')->where('property_id', $property->toString())->update(['show_powered_by' => $show, 'updated_by' => $actorId, 'updated_at' => $now]);

            return;
        }

        DB::table('property_branding_settings')->insert(['property_id' => $property->toString(), 'show_powered_by' => $show, 'updated_by' => $actorId, 'created_at' => $now, 'updated_at' => $now]);
    }

    public function soleProperty(): ?PropertyId
    {
        $ids = DB::table('properties')->where('is_active', true)->limit(2)->pluck('id');

        return $ids->count() === 1 ? PropertyId::fromString((string) $ids->first()) : null;
    }
}
