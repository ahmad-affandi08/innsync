<?php

declare(strict_types=1);

namespace App\Shared\Application\Setup;

use App\Shared\Domain\Tenancy\PropertyId;
use Closure;

/**
 * The data a module offers a new property so nobody types it in again (the usual expense accounts, shifts, kinds of leave). A module lists its parts; each is written only where
 * the property has none yet and refuses (a `Refusal`) when something is there, so running it again changes nothing. Policy that belongs to the hotel (rates, taxes, rooms) is not starter data.
 */
interface StarterData
{
    /**
     * @return list<array{module: string, label: string, install: Closure(PropertyId, string): int}> `module` is the menu key (`finance`, `hr`); `install` takes the property and the
     *                                                                                               administrator who is recorded as the actor, and returns how many records it wrote
     */
    public function parts(): array;
}
