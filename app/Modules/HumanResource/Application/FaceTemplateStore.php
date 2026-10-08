<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface FaceTemplateStore
{
    /** @return array{templates: list<list<float>>, samples: int, enrolled_at: string}|null */
    public function find(PropertyId $property, string $employeeId): ?array;

    /** @param list<list<float>> $templates replaces any earlier set of the employee */
    public function save(PropertyId $property, string $employeeId, array $templates, string $by, DateTimeImmutable $at): void;

    /** True when something was removed. */
    public function delete(PropertyId $property, string $employeeId): bool;

    /** @return array<string, string> employee id => when registered */
    public function enrolled(PropertyId $property): array;
}
