<?php

declare(strict_types=1);

namespace App\Shared\Application\Security;

use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Opens the login account of an employee from the HR record (CR-0002). The identity module owns the accounts, the roles and the map from a position to a role; HR only asks. The account
 * is made with the rules of the Access screen: the actor must manage users, may give only a role they hold themselves, and the email must be new.
 */
interface StaffAccounts
{
    /** Whether the person may open accounts in the property (they manage users there). */
    public function mayOpen(PropertyId $property, string $actorId): bool;

    /**
     * The name of the role an employee would get, for each of the given positions that has an active role set. A position the property has not mapped is left out.
     *
     * @param  list<string>  $positions
     * @return array<string, string> position as given to the role name
     */
    public function roleNamesFor(PropertyId $property, array $positions): array;

    /**
     * @return array{user_id: string, email: string, role: string, temporary_password: string} the password is shown once and kept nowhere in clear
     */
    public function open(PropertyId $property, string $actorId, string $name, string $email, string $position, string $reason): array;
}
