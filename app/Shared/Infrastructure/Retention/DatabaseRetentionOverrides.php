<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Retention;

use App\Shared\Application\Retention\RetentionOverrides;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseRetentionOverrides implements RetentionOverrides
{
    public function find(PropertyId $property, string $category): ?int
    {
        $days = DB::table('retention_overrides')
            ->where('property_id', $property->toString())
            ->where('category', $category)
            ->value('retention_days');

        return $days === null ? null : (int) $days;
    }

    public function store(PropertyId $property, string $category, int $days, string $actorId, DateTimeImmutable $at): void
    {
        DB::table('retention_overrides')->upsert(
            [['property_id' => $property->toString(), 'category' => $category, 'retention_days' => $days, 'updated_by' => $actorId, 'updated_at' => $at]],
            ['property_id', 'category'],
            ['retention_days', 'updated_by', 'updated_at'],
        );
    }
}
