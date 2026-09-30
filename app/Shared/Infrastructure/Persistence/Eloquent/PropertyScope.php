<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence\Eloquent;

use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final class PropertyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where(
            $model->qualifyColumn('property_id'),
            app(PropertyContext::class)->current()->toString(),
        );
    }
}
