<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Shared\Domain\Tenancy\PropertyId;

/** The rows of each finance data set a person may export. Money is returned in minor units; `money` names the columns that hold it. */
interface FinanceExportQueries
{
    /**
     * @return array{header: list<string>, money: list<int>, rows: list<list<scalar|null>>} the rows dated in the range, oldest first, at most `$limit + 1` of them
     */
    public function dataset(PropertyId $property, string $dataset, string $from, string $to, int $limit): array;
}
