<?php

declare(strict_types=1);

namespace App\Shared\Application\Tenancy;

use App\Shared\Domain\Tenancy\PropertyId;
use Closure;

final class PropertyContext
{
    private ?PropertyId $activePropertyId = null;

    public function activate(PropertyId $propertyId): void
    {
        $this->activePropertyId = $propertyId;
    }

    public function activateFromString(string $propertyId): void
    {
        $this->activate(PropertyId::fromString($propertyId));
    }

    public function clear(): void
    {
        $this->activePropertyId = null;
    }

    public function current(): PropertyId
    {
        return $this->activePropertyId
            ?? throw MissingPropertyContext::forScopedOperation();
    }

    public function hasActiveProperty(): bool
    {
        return $this->activePropertyId !== null;
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function run(PropertyId $propertyId, Closure $operation): mixed
    {
        $previous = $this->activePropertyId;
        $this->activePropertyId = $propertyId;

        try {
            return $operation();
        } finally {
            $this->activePropertyId = $previous;
        }
    }
}
