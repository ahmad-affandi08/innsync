<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** What the stock reports read from the inventory ledger: the value of the stock at a date, what moved in a period, and the food cost target. Dates are business dates. */
interface StockReportQueries
{
    /** @return list<array{department: string, location_id: string, location_code: string, location_name: string, value_minor: int}> value of the stock held at the end of a date, by department of the item and by location, without the rows that hold nothing */
    public function valueAt(PropertyId $property, string $asOf): array;

    /** @return list<array{department: string, kind: string, value_minor: int}> value moved in the range, by department of the item and by kind of movement; outgoing kinds are negative */
    public function movedBetween(PropertyId $property, string $from, string $to): array;

    /** @return array{target_bp: int, lock_version: int}|null */
    public function foodCostSettings(PropertyId $property): ?array;

    /** @return bool false when the target changed meanwhile */
    public function saveFoodCostTarget(PropertyId $property, int $targetBp, ?int $expectedLockVersion, string $by, DateTimeImmutable $at): bool;
}
