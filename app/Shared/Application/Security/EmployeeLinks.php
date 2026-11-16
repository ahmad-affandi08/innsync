<?php

declare(strict_types=1);

namespace App\Shared\Application\Security;

use App\Shared\Domain\Tenancy\PropertyId;

/** Which employee record belongs to which login account, answered by HR, so the Access screen can say who an account is. */
interface EmployeeLinks
{
    /**
     * @param  list<string>  $userIds
     * @return array<string, array{id: string, number: string, name: string, department: string, position: string, status: string}> keyed by user id
     */
    public function ofUsers(PropertyId $property, array $userIds): array;
}
