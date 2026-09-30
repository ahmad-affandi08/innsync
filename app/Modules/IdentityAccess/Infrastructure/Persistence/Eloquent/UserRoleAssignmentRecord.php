<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent;

use App\Modules\IdentityAccess\Domain\Authorization\ScopeType;
use App\Shared\Infrastructure\Persistence\Eloquent\PropertyOwnedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['user_id', 'role_id', 'scope_type', 'scope_id', 'is_active'])]
final class UserRoleAssignmentRecord extends PropertyOwnedModel
{
    protected $table = 'user_role_assignments';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope_type' => ScopeType::class,
            'is_active' => 'boolean',
            'lock_version' => 'integer',
        ];
    }
}
