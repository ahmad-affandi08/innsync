<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Shared\Domain\Tenancy\PropertyId;

interface ReportBuilderQueries
{
    /**
     * The rows of a dataset with only the chosen columns, filtered and sorted. Every name is checked by the service against the
     * catalogue; a name unknown here is refused.
     *
     * @param  list<string>  $columns
     * @param  array<string, string>  $filters  filter name to a value the catalogue allows
     * @param  array{from: string, to: string}|null  $range  the dates (or, for a dataset kept by instants, the UTC instants) the date column must fall in
     * @return list<array<string, scalar|null>>
     */
    public function rows(PropertyId $property, string $dataset, array $columns, array $filters, ?array $range, string $sort, string $direction, int $limit): array;
}
