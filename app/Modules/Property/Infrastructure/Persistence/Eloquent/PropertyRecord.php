<?php

declare(strict_types=1);

namespace App\Modules\Property\Infrastructure\Persistence\Eloquent;

use App\Shared\Infrastructure\Persistence\Eloquent\UsesOptimisticLocking;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'timezone', 'currency_code', 'is_active'])]
final class PropertyRecord extends Model
{
    use HasUlids;
    use UsesOptimisticLocking;

    protected $table = 'properties';

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
        'lock_version' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'lock_version' => 'integer',
        ];
    }
}
