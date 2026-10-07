<?php

declare(strict_types=1);

namespace App\Shared\Application\Setup;

use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The roles that suit how a property works. Owned by the identity module; the property module asks for them when a profile is chosen, so it never
 * touches roles itself.
 */
interface ProfileRoles
{
    /**
     * Makes the roles of the profile (a role that exists by name is left alone). A small profile also switches off the starting hotel roles that nobody holds and
     * nobody changed, so a small team is not offered nineteen roles. Nothing is deleted and a switched-off role can be switched on again.
     *
     * @return array{created: list<string>, deactivated: list<string>}
     */
    public function apply(PropertyId $property, string $profile): array;
}
