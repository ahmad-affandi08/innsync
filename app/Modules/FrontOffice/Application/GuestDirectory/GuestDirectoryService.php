<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\GuestDirectory;

use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The guests the property has hosted, so a returning guest is recognised: how many times they stayed, how many nights, and the last stay. Guests are told apart by
 * name and phone, but the phone, the e-mail and the identity data never leave in this list; they stay behind the reservation, which audits who reads them.
 */
final readonly class GuestDirectoryService
{
    public function __construct(private GuestDirectoryReader $guests, private PermissionChecker $permissions, private PropertyContext $property) {}

    /** @return list<array{guest_name: string, stays: int, nights: int, last_arrival: string, last_departure: string, last_reservation_id: string, upcoming: int}> */
    public function list(PropertyId $property, string $actorId, string $query = ''): array
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }

        if (! $this->permissions->allowsInProperty($actorId, ReservationService::VIEW_PERMISSION, $property) && ! $this->permissions->allowsInProperty($actorId, ReservationService::MANAGE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not see guests.');
        }

        return $this->guests->guests($property, trim($query), 200);
    }
}
