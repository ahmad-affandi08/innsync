<?php

declare(strict_types=1);

namespace App\Shared\Application\Retention;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface RetentionOverrides
{
    public function find(PropertyId $property, string $category): ?int;

    public function store(PropertyId $property, string $category, int $days, string $actorId, DateTimeImmutable $at): void;
}
