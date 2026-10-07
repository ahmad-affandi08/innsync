<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Setup;

use App\Shared\Application\Setup\ModuleSettings;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Facades\DB;

final class DatabaseModuleSettings implements ModuleSettings
{
    /** @var array<string, list<string>> */
    private array $memo = [];

    public function disabled(PropertyId $property): array
    {
        $id = $property->toString();

        return $this->memo[$id] ??= $this->read($id);
    }

    public function profile(PropertyId $property): ?string
    {
        $value = DB::table('property_profiles')->where('property_id', $property->toString())->value('profile');

        return is_string($value) ? $value : null;
    }

    /** @return list<string> */
    private function read(string $id): array
    {
        $raw = DB::table('property_profiles')->where('property_id', $id)->value('disabled_modules');
        $list = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }
}
