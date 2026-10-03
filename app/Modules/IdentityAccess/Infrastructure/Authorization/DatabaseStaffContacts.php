<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Authorization;

use App\Shared\Application\Security\StaffContacts;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseStaffContacts implements StaffContacts
{
    public function emailsOf(PropertyId $property, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return DB::table('users')
            ->whereIn('id', array_values(array_unique($userIds)))
            ->where('is_active', true)
            ->whereExists(static fn ($q) => $q->selectRaw('1')->from('user_role_assignments')->whereColumn('user_role_assignments.user_id', 'users.id')->where('user_role_assignments.property_id', $property->toString())->where('user_role_assignments.is_active', true))
            ->pluck('email', 'id')
            ->mapWithKeys(static fn ($email, $id): array => [strtolower((string) $id) => (string) $email])
            ->all();
    }
}
