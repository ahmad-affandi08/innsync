<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** The audit entries of the actions that change a financial figure. */
interface FinanceAuditQueries
{
    /**
     * @param  list<string>  $prefixes  action prefixes (each ends with a dot or an underscore) to include
     * @param  array{actor_id?: string|null, action?: string|null}  $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function trail(PropertyId $property, array $prefixes, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc, array $filters, int $limit, int $offset): array;
}
