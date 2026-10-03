<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Application;

use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Domain\Tenancy\PropertyId;

/** The privileges of the staff side of the guest self-service, and the property check each service starts with. The guest side has no privilege: it has a session. */
final readonly class GuestAccess
{
    /** Makes, prints and rotates the QR codes of the rooms and tables. */
    public const QR_MANAGE = 'guest.qr.manage';

    /** Sees what guests ordered and verifies a charge to the room. */
    public const ORDER_MANAGE = 'guest.order.manage';

    /** Sends links for the self check-in, reads what guests sent, verifies or refuses it. Reading what a guest sent also needs the right to read identity documents. */
    public const CHECKIN_MANAGE = 'guest.checkin.manage';

    /** Writes a new version of the privacy notice the guest agrees to before sending an identity document. */
    public const PRIVACY_MANAGE = 'guest.privacy.manage';

    /** The front office privilege to read identity documents; asking for it here does not make this context depend on front office code. */
    public const IDENTITY_VIEW = 'front-office.guest-identity.view';

    public function __construct(private PermissionChecker $permissions, private PropertyContext $property) {}

    public function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }

    public function may(PropertyId $property, string $actorId, string $permission): bool
    {
        return $this->permissions->allowsInProperty($actorId, $permission, $property);
    }

    public function require(PropertyId $property, string $actorId, string $permission, string $message): void
    {
        $this->assertProperty($property);

        if (! $this->may($property, $actorId, $permission)) {
            throw Refusal::forbidden($message);
        }
    }
}
