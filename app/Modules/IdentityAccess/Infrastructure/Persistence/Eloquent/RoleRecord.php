<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent;

use App\Shared\Infrastructure\Persistence\Eloquent\PropertyOwnedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['name', 'requires_mfa', 'is_active'])]
final class RoleRecord extends PropertyOwnedModel
{
    protected $table = 'roles';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requires_mfa' => 'boolean',
            'is_active' => 'boolean',
            'lock_version' => 'integer',
        ];
    }
}
