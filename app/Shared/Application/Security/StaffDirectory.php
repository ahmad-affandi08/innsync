<?php

declare(strict_types=1);

namespace App\Shared\Application\Security;

use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Names of the people who can be given work or asked to decide, answered by the identity module. A person is listed for a
 * permission only when it is granted to them for the whole property and both their account and their role are active.
 */
interface StaffDirectory
{
    /** @return list<array{id: string, name: string}> sorted by name */
    public function withPermission(PropertyId $property, string $permission): array;

    /**
     * Display names of the given users who work in this property.
     *
     * @param  list<string>  $userIds
     * @return array<string, string> user id to name
     */
    public function namesOf(PropertyId $property, array $userIds): array;
}
