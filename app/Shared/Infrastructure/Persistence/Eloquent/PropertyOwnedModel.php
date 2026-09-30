<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence\Eloquent;

use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

abstract class PropertyOwnedModel extends Model
{
    use HasUlids;
    use UsesOptimisticLocking;

    /** @var array<string, mixed> */
    protected $attributes = [
        'lock_version' => 0,
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new PropertyScope);

        static::creating(static function (self $model): void {
            $model->assignOrVerifyActiveProperty();
        });

        static::updating(static function (self $model): void {
            $model->verifyPropertyIsUnchanged();
            $model->verifyActiveProperty();
        });

        static::deleting(static function (self $model): void {
            $model->verifyActiveProperty();
        });
    }

    protected function setKeysForSaveQuery($query)
    {
        return parent::setKeysForSaveQuery($query)->where(
            $this->qualifyColumn('property_id'),
            app(PropertyContext::class)->current()->toString(),
        );
    }

    private function assignOrVerifyActiveProperty(): void
    {
        $activePropertyId = app(PropertyContext::class)->current()->toString();
        $recordPropertyId = $this->getAttribute('property_id');

        if ($recordPropertyId === null || $recordPropertyId === '') {
            $this->setAttribute('property_id', $activePropertyId);

            return;
        }

        $this->assertMatchesActiveProperty((string) $recordPropertyId, $activePropertyId);
    }

    private function verifyPropertyIsUnchanged(): void
    {
        if ($this->isDirty('property_id')) {
            throw PropertyScopeViolation::immutable();
        }
    }

    private function verifyActiveProperty(): void
    {
        $activePropertyId = app(PropertyContext::class)->current()->toString();
        $recordPropertyId = (string) $this->getAttribute('property_id');

        $this->assertMatchesActiveProperty($recordPropertyId, $activePropertyId);
    }

    private function assertMatchesActiveProperty(string $recordPropertyId, string $activePropertyId): void
    {
        if ($recordPropertyId !== $activePropertyId) {
            throw PropertyScopeViolation::mismatched($activePropertyId, $recordPropertyId);
        }
    }
}
