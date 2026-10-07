<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

/**
 * What a person may see of one kind of card (FR-DSH-022): the whole property, or only some departments and outlets through roles assigned at those scopes
 * (NFR-06, docs/OPERATIONS/SCOPE-MODEL.md). The outlets are the ids of F&B outlets.
 */
final readonly class DashboardGrant
{
    /**
     * @param  list<string>  $departments  department codes
     * @param  list<string>  $outlets  F&B outlet ids
     */
    public function __construct(public bool $property, public array $departments, public array $outlets) {}

    public function any(): bool
    {
        return $this->property || $this->departments !== [] || $this->outlets !== [];
    }

    /** Only a part of the property: some departments or outlets. */
    public function limited(): bool
    {
        return ! $this->property && $this->any();
    }

    public function department(string $department): bool
    {
        return $this->property || in_array($department, $this->departments, true);
    }

    /** @return array{property: bool, departments: list<string>, outlets: list<string>} */
    public function toArray(): array
    {
        return ['property' => $this->property, 'departments' => $this->departments, 'outlets' => $this->outlets];
    }
}
