<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Authorization;

use App\Shared\Application\Security\StaffAccess;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseStaffAccess implements StaffAccess
{
    public function revokeInProperty(PropertyId $property, string $userId): int
    {
        return DB::table('user_role_assignments')->where('property_id', $property->toString())->where('user_id', strtolower($userId))->where('is_active', true)->update(['is_active' => false, 'updated_at' => now()]);
    }
}
