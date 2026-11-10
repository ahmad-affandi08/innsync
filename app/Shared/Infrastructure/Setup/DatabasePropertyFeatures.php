<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Setup;

use App\Shared\Application\Setup\PropertyFeatures;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Facades\DB;

final class DatabasePropertyFeatures implements PropertyFeatures
{
    /** Read each time (a primary-key range of at most eight rows): a copy kept in the object would outlive a change made in the same process, as a controller does between requests of a test or a worker. */
    public function enabled(PropertyId $property): array
    {
        return DB::table('property_features')->where('property_id', $property->toString())->where('enabled', true)->orderBy('feature')->pluck('feature')->map(static fn ($f): string => (string) $f)->all();
    }
}
