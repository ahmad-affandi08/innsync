<?php

declare(strict_types=1);

namespace App\Shared\Application\Integration;

use App\Shared\Domain\Tenancy\PropertyId;

interface CircuitStore
{
    public function load(PropertyId $property, string $provider): Circuit;

    /** @return bool false when another request changed the circuit first (the caller re-reads and decides again) */
    public function save(PropertyId $property, string $provider, Circuit $circuit, int $expectedVersion): bool;
}
