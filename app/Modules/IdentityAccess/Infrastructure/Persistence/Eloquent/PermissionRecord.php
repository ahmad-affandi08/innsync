<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'description'])]
final class PermissionRecord extends Model
{
    use HasUlids;
}
