<?php

declare(strict_types=1);

namespace App\Shared\Application\Deployment;

use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Sets the first business date of a property at go-live, so that deployment tooling does not depend on the Property context (BR-001).
 */
interface GoLiveBusinessDate
{
    /** Does nothing when the property already has a business date. Must run inside the property context. */
    public function initializeIfMissing(PropertyId $property, string $actorId, string $date, string $reason): void;
}
