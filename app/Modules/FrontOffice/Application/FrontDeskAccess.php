<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application;

use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Domain\Tenancy\PropertyId;

/** Who may read and who may change what the front desk keeps: whoever sees reservations reads it, whoever manages reservations changes it. */
final readonly class FrontDeskAccess
{
    public function __construct(private PermissionChecker $permissions, private PropertyContext $property) {}

    public function read(PropertyId $property, string $actorId): void
    {
        $this->scope($property);

        if (! $this->permissions->allowsInProperty($actorId, ReservationService::VIEW_PERMISSION, $property) && ! $this->permissions->allowsInProperty($actorId, ReservationService::MANAGE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not see the front desk records.');
        }
    }

    public function write(PropertyId $property, string $actorId): void
    {
        $this->scope($property);

        if (! $this->permissions->allowsInProperty($actorId, ReservationService::MANAGE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not change the front desk records.');
        }
    }

    public function mayWrite(PropertyId $property, string $actorId): bool
    {
        return $this->permissions->allowsInProperty($actorId, ReservationService::MANAGE_PERMISSION, $property);
    }

    private function scope(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
