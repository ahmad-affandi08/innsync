<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Storage of the price lists and promotions of the outlets. A rule is never changed, only retired. */
interface PriceRuleStore
{
    /** @return list<array<string, mixed>> the rules of an outlet, active ones first, newest first; with `item_code` and `item_name` */
    public function rules(PropertyId $property, string $outletId, bool $activeOnly): array;

    /** @return array<string, mixed>|null */
    public function rule(PropertyId $property, string $id): ?array;

    /** @param array<string, mixed> $row */
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return bool false when the rule was retired already */
    public function retire(PropertyId $property, string $id, string $by, string $reason, DateTimeImmutable $at): bool;
}
