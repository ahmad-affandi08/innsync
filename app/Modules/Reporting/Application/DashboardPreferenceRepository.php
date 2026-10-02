<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface DashboardPreferenceRepository
{
    /** @return array{order: list<string>, hidden: list<string>}|null */
    public function find(PropertyId $property, string $userId): ?array;

    /** @param list<string> $order @param list<string> $hidden */
    public function save(PropertyId $property, string $userId, array $order, array $hidden, DateTimeImmutable $at): void;

    public function clear(PropertyId $property, string $userId): void;
}
