<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence\Eloquent;

use App\Shared\Application\Concurrency\OptimisticLockConflict;
use Illuminate\Database\Eloquent\Builder;

trait UsesOptimisticLocking
{
    /**
     * Update a record only when its persisted version still matches the version read.
     *
     * @param  Builder<static>  $query
     */
    protected function performUpdate(Builder $query)
    {
        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        $dirty = $this->getDirtyForUpdate();

        if (count($dirty) === 0) {
            return true;
        }

        $expectedVersion = (int) $this->getRawOriginal('lock_version');
        $this->setAttribute('lock_version', $expectedVersion + 1);
        $dirty = $this->getDirtyForUpdate();

        $affectedRows = $this->setKeysForSaveQuery($query)
            ->where($this->qualifyColumn('lock_version'), $expectedVersion)
            ->update($dirty);

        if ($affectedRows !== 1) {
            throw OptimisticLockConflict::forRecord(
                static::class,
                (string) $this->getKey(),
                $expectedVersion,
            );
        }

        $this->refreshSavedAttributes();
        $this->syncChanges();
        $this->fireModelEvent('updated', false);

        return true;
    }
}
