<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Licensing;

use Illuminate\Support\Facades\DB;

/** How many properties this installation may hold under the agreement, and how many it holds. A limit of 0 means no limit. */
final class PropertyLicense
{
    public function limit(): int
    {
        return max(0, (int) config('licensing.max_properties'));
    }

    public function count(): int
    {
        return DB::table('properties')->count();
    }

    public function allowsAnother(): bool
    {
        return $this->limit() === 0 || $this->count() < $this->limit();
    }
}
